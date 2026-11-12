<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Tests\Factory\CustomerFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Zenstruck\Foundry\Test\Factories;

final class LoginTest extends WebTestCase
{
    use Factories;
    use MagicLinkTrait;

    /** Headers of a form submitted from another site (what browsers send with it). */
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/'];

    public function testLoginPageAsksOnlyForAnEmail(): void
    {
        $client = self::createClient();
        $client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[type="email"][name="login[email]"]');
        self::assertSelectorNotExists('input[type="password"]');
        self::assertSelectorTextContains('button[type="submit"]', 'Recevoir mon lien');
    }

    public function testKnownEmailSignsInWithTheMagicLinkAfterConfirming(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);

        $this->requestLink($client, '  Camille@Example.test ');

        self::assertResponseRedirects('/connexion/lien-envoye');

        $email = $this->lastEmail();
        self::assertEmailAddressContains($email, 'To', 'camille@example.test');
        self::assertEmailSubjectContains($email, 'Ton lien de connexion');
        self::assertEmailHtmlBodyContains($email, 'Me connecter');
        self::assertEmailHtmlBodyContains($email, 'valable 15 minutes');
        self::assertEmailTextBodyContains($email, 'valable 15 minutes');
        self::assertEmailTextBodyContains($email, '/connexion/verification');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Regarde ta boîte mail');
        self::assertSelectorTextContains('[data-sent-email]', 'camille@example.test');

        // Not signed in until the link is followed.
        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');

        // Opening the link only asks for a confirmation (mail scanners, login CSRF).
        $crawler = $client->request('GET', $this->linkOf($email));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-confirm-email]', 'camille@example.test');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $form = $crawler->filter('main form')->form();

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');

        $client->submit($form);
        self::assertResponseRedirects('/mon-compte');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'camille@example.test');
        self::assertTrue($client->getRequest()->getSession()->has('_security_main'));

        // Signed in: the sign-in screens send the customer to their account.
        $client->request('GET', '/connexion');
        self::assertResponseRedirects('/mon-compte');
    }

    public function testKnownEmailGetsTheVerySameAnswer(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'habituee@example.test', 'firstName' => 'Zoé']);

        $this->requestLink($client, 'habituee@example.test');

        self::assertResponseRedirects('/connexion/lien-envoye');
        self::assertSame(1, CustomerFactory::repository()->count(['email' => 'habituee@example.test']));
        self::assertEmailHtmlBodyContains($this->lastEmail(), 'Bonjour Zoé');

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Regarde ta boîte mail');
    }

    public function testUnknownEmailGetsNoAccountButALinkToCreateIt(): void
    {
        $client = self::createClient();

        $this->requestLink($client, '  Camille@Example.test ');

        // Same answer as for a known address, and nothing created.
        self::assertResponseRedirects('/connexion/lien-envoye');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'camille@example.test']));

        $email = $this->lastEmail();
        self::assertEmailAddressContains($email, 'To', 'camille@example.test');
        self::assertEmailSubjectContains($email, 'Ton lien de connexion');
        self::assertEmailHtmlBodyContains($email, 'Créer mon compte');
        self::assertEmailTextBodyContains($email, 'aucun compte n\'existe encore');
        self::assertEmailTextBodyContains($email, 'valable 60 minutes');

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Regarde ta boîte mail');

        // Opening the link creates nothing: the owner of the address confirms.
        $link = $this->linkOf($email);
        self::assertStringStartsWith('/connexion/nouveau-compte?', $link);
        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-confirm-email]', 'camille@example.test');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'camille@example.test']));

        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte');
        $customer = CustomerFactory::repository()->findOneBy(['email' => 'camille@example.test']);
        self::assertNotNull($customer);
        self::assertNotNull($customer->getLastLoginAt());

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'camille@example.test');

        // The account-creation link works once.
        $client->getCookieJar()->clear();
        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSelectorNotExists('[data-confirm-email]');
    }

    public function testAccountCreationLinkCannotBeForged(): void
    {
        $client = self::createClient();
        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());

        // Another address in a link signed for camille.
        $forged = str_replace('camille%40example.test', 'mallory%40example.test', $link);
        self::assertNotSame($link, $forged);
        $client->request('GET', $forged);
        self::assertSelectorExists('[data-login-link-error]');

        $client->request('POST', $forged, ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'mallory@example.test']));

        // An unsigned link does nothing either.
        $client->request('GET', '/connexion/nouveau-compte?email=mallory%40example.test');
        self::assertSelectorExists('[data-login-link-error]');
    }

    public function testAccountCreationFromAnotherSiteIsRefused(): void
    {
        $client = self::createClient();
        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());

        // A form of another site posting the link (login CSRF): refused, nothing created.
        $client->request('POST', $link, ['_csrf_token' => 'csrf-token'], [], self::CROSS_SITE);
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'camille@example.test']));
    }

    public function testExpiredAccountCreationLinkOffersANewOne(): void
    {
        $client = self::createClient();

        $signer = self::getContainer()->get(UriSigner::class);
        self::assertInstanceOf(UriSigner::class, $signer);
        $expired = $signer->sign('http://localhost/connexion/nouveau-compte?email=camille%40example.test', time() - 60);

        $client->request('GET', $expired);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertInputValueSame('login[email]', 'camille@example.test');
    }

    public function testCustomerComesBackToThePageTheyAskedFor(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);

        $client->request('GET', '/mon-compte/supprimer');
        self::assertResponseRedirects('/connexion');

        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());
        self::assertStringContainsString('_target_path=%2Fmon-compte%2Fsupprimer', $link);

        // The target travels in the link: it works from another browser too.
        $client->getCookieJar()->clear();
        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte/supprimer');
    }

    public function testNewCustomerComesBackToThePageTheyAskedFor(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mon-compte/supprimer');
        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());

        $client->getCookieJar()->clear();
        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte/supprimer');
    }

    public function testMagicLinkWorksOnlyOnce(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);

        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());
        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte');

        // Someone who finds the link later (forwarded e-mail, history) cannot use it.
        $client->getCookieJar()->clear();
        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSelectorNotExists('[data-confirm-email]');
        // A new link is one click away, for the same address.
        self::assertInputValueSame('login[email]', 'camille@example.test');

        // Posting it directly is refused as well.
        $client->request('POST', $link, ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/connexion');
        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testASignInRevokesEveryOlderLink(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);

        $this->requestLink($client, 'camille@example.test');
        $older = $this->linkOf($this->lastEmail());
        $this->requestLink($client, 'camille@example.test');
        $newer = $this->linkOf($this->lastEmail());

        $this->confirmLink($client, $newer);
        self::assertResponseRedirects('/mon-compte');

        $client->getCookieJar()->clear();
        $client->request('GET', $older);
        self::assertSelectorExists('[data-login-link-error]');
    }

    public function testAlreadySignedInCustomerGoesStraightToTheTarget(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);

        $this->requestLink($client, 'camille@example.test');
        $link = $this->linkOf($this->lastEmail());

        $client->loginUser($customer, 'main');
        $client->request('GET', $link.'&_target_path=%2Fmon-compte%2Fsupprimer');
        self::assertResponseRedirects('/mon-compte/supprimer');
    }

    public function testExpiredLinkOffersANewOneLeadingToTheSamePage(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);

        $handler = self::getContainer()->get('security.authenticator.login_link_handler.main');
        self::assertInstanceOf(LoginLinkHandlerInterface::class, $handler);
        $url = $handler->createLoginLink($customer, null, -60)->getUrl();
        $path = parse_url($url, \PHP_URL_PATH).'?'.parse_url($url, \PHP_URL_QUERY).'&_target_path=%2Fmon-compte%2Fsupprimer';

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertInputValueSame('login[email]', 'camille@example.test');

        $client->submitForm('Recevoir mon lien');
        self::assertResponseRedirects('/connexion/lien-envoye');
        self::assertStringContainsString('_target_path=%2Fmon-compte%2Fsupprimer', $this->linkOf($this->lastEmail()));
    }

    public function testSignInFromAnotherSiteIsRefused(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'mallory@example.test']);

        $this->requestLink($client, 'mallory@example.test');
        $link = $this->linkOf($this->lastEmail());

        // An auto-submitted form of another site, carrying a valid link of the attacker.
        $client->getCookieJar()->clear();
        $client->request('POST', $link, ['_csrf_token' => 'csrf-token'], [], self::CROSS_SITE);
        self::assertResponseRedirects('/connexion');
        self::assertFalse($client->getRequest()->getSession()->has('_security_main'));

        // The attempt does not burn the link.
        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte');
    }

    public function testInvalidEmailIsRejectedWithoutSendingAnything(): void
    {
        $client = self::createClient();
        $this->requestLink($client, 'pas-un-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'ne semble pas valide');
        self::assertEmailCount(0);
        self::assertQueuedEmailCount(0);
    }

    public function testLinkRequestsAreThrottledPerEmail(): void
    {
        $client = self::createClient();
        // Same kernel for every request: the in-memory counters live as long as the test.
        $client->disableReboot();

        for ($i = 0; $i < 3; ++$i) {
            $this->requestLink($client, 'pressee@example.test');
            self::assertResponseRedirects('/connexion/lien-envoye');
        }

        $this->requestLink($client, 'pressee@example.test');
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('form', 'Patiente quelques minutes');

        // Another address is not affected by this one's limit.
        $this->requestLink($client, 'tranquille@example.test');
        self::assertResponseRedirects('/connexion/lien-envoye');
    }

    public function testLinkRequestsAreThrottledPerIp(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 20; ++$i) {
            $this->requestLink($client, \sprintf('client-%d@example.test', $i));
            self::assertResponseRedirects('/connexion/lien-envoye');
        }

        $this->requestLink($client, 'un-de-plus@example.test');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'un-de-plus@example.test']));
    }

    public function testTamperedLinkIsRefused(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);
        $this->requestLink($client, 'camille@example.test');

        $link = preg_replace('/hash=[^&]+/', 'hash=forged', $this->linkOf($this->lastEmail()));
        self::assertIsString($link);

        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSelectorNotExists('[data-confirm-email]');

        $client->request('POST', $link, ['_csrf_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorExists('[data-login-link-error]');

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(), 'main');

        $client->request('GET', '/mon-compte');
        $client->submitForm('Me déconnecter');
        self::assertResponseRedirects('/');

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testLogoutIsNeverAGetRequestNorACrossSitePost(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(), 'main');

        // Not the logout route (POST only): falls through to the shop pages, nobody is logged out.
        $client->request('GET', '/deconnexion');
        self::assertFalse($client->getResponse()->isRedirect('/'));

        $client->request('POST', '/deconnexion', ['_csrf_token' => 'csrf-token'], [], self::CROSS_SITE);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/mon-compte');
        self::assertResponseIsSuccessful();
    }

    private function requestLink(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/connexion');
        $client->submitForm('Recevoir mon lien', ['login[email]' => $email]);
    }
}
