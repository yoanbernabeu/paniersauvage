<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Customer\Entity\Customer;
use App\Legal\LegalDocuments;
use App\Shop\Entity\User;
use App\Tests\Customer\MagicLinkTrait;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Ordering\OrderingFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Acceptance of the terms of use: producer sign-up, new version before /pro, customer account
 * creation and first order (date and version recorded).
 */
final class TermsAcceptanceTest extends WebTestCase
{
    use Factories;
    use MagicLinkTrait;
    use OrderingFixtures;

    private const array REGISTRATION = [
        'registration[firstName]' => 'Camille',
        'registration[lastName]' => 'Durand',
        'registration[shopName]' => 'La Ferme des Tilleuls',
        'registration[email]' => 'camille@example.test',
        'registration[plainPassword]' => 'pain-au-levain',
    ];

    public function testTheProducerSignUpRecordsTheAcceptedVersion(): void
    {
        $client = $this->createSignUpClient();
        $client->request('GET', '/pro/inscription');
        self::assertSelectorExists('input[type="checkbox"][name="registration[acceptTerms]"][required]');
        self::assertSelectorExists('label[for="registration_acceptTerms"] a[href="/cgu#sous-traitance"]');

        $client->submitForm('Créer ma boutique', [...self::REGISTRATION, 'registration[acceptTerms]' => '1']);
        self::assertResponseRedirects('/pro/bienvenue');

        $user = UserFactory::repository()->findOneBy(['email' => 'camille@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(LegalDocuments::TERMS_VERSION, $user->getTermsVersion());
        self::assertNotNull($user->getTermsAcceptedAt());
        self::assertTrue($user->hasAcceptedTerms(LegalDocuments::TERMS_VERSION));

        // Straight to the onboarding, no second acceptance.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertRouteSame('pro_onboarding');
    }

    public function testTheProducerSignUpIsRefusedWithoutTheBox(): void
    {
        $client = $this->createSignUpClient();
        $client->request('GET', '/pro/inscription');
        $client->submitForm('Créer ma boutique', self::REGISTRATION);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#registration_acceptTerms_errors', 'Accepte les conditions d\'utilisation');
        self::assertSame(0, UserFactory::repository()->count([]));
    }

    public function testAProducerMustAcceptANewVersionBeforeAnyProPage(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne(['termsVersion' => '0.9']);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/boutique?onglet=1');
        self::assertResponseRedirects('/pro/conditions?retour=/pro/boutique?onglet%3D1', 303);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nos conditions ont changé');
        self::assertSelectorTextContains('main', 'version '.LegalDocuments::TERMS_VERSION);

        // The box is mandatory.
        $client->submitForm('Accepter et continuer');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#terms_acceptance_acceptTerms_errors');
        self::assertSame('0.9', $this->reloadUser($producer)->getTermsVersion());

        $client->submitForm('Accepter et continuer', ['terms_acceptance[acceptTerms]' => '1']);
        self::assertResponseRedirects('/pro/boutique?onglet=1', 303);
        $user = $this->reloadUser($producer);
        self::assertSame(LegalDocuments::TERMS_VERSION, $user->getTermsVersion());
        self::assertNotNull($user->getTermsAcceptedAt());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertRouteSame('pro_shop_settings');
        self::assertSelectorTextContains('[data-flash="success"]', 'Tu peux reprendre là où tu en étais.');
    }

    public function testAProducerWhoNeverAcceptedIsAskedFirst(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['termsVersion' => null, 'termsAcceptedAt' => null]), 'producer');

        $client->request('GET', '/pro');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Avant de continuer');
    }

    public function testAPostIsSentToTheAcceptancePageWithoutReturnAddress(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['termsVersion' => '0.9']), 'producer');

        $client->request('POST', '/pro/boutique');
        self::assertResponseRedirects('/pro/conditions', 303);
    }

    public function testLoggingOutStaysPossible(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['termsVersion' => '0.9']), 'producer');
        $client->request('GET', '/pro/conditions');

        $client->submitForm('Me déconnecter');
        self::assertResponseRedirects('/pro/connexion');
    }

    public function testTheReturnAddressStaysInTheProducerArea(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne(['termsVersion' => '0.9']);
        $client->loginUser($producer, 'producer');

        foreach (['https://evil.example/pro', '//evil.example/pro', '/proxy', '/pro\\evil', '/mon-compte'] as $unsafe) {
            $client->request('GET', '/pro/conditions?retour='.rawurlencode($unsafe));
            self::assertResponseIsSuccessful();
        }

        $client->submitForm('Accepter et continuer', ['terms_acceptance[acceptTerms]' => '1']);
        self::assertResponseRedirects('/pro', 303);

        // Already accepted: the page leads on.
        $client->request('GET', '/pro/conditions?retour=/pro/produits');
        self::assertResponseRedirects('/pro/produits');
        $client->request('GET', '/pro/conditions');
        self::assertResponseRedirects('/pro');
    }

    public function testAProducerWithTheCurrentVersionIsNeverStopped(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/boutique');
        self::assertResponseIsSuccessful();
    }

    public function testTheCustomerAccountCreationRequiresTheTerms(): void
    {
        $client = self::createClient();
        $this->requestLink($client, 'nouvelle@example.test');
        $link = $this->linkOf($this->lastEmail());

        $crawler = $client->request('GET', $link);
        self::assertSelectorExists('input[type="checkbox"][name="accept_terms"][required]');
        self::assertSelectorExists('[data-terms-checkbox] a[href="/cgu#clients"]');

        // Without the box: nothing created, the error is shown.
        $client->submit($crawler->filter('main form')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-terms-checkbox] [role="alert"]', 'Accepte les conditions d\'utilisation');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'nouvelle@example.test']));

        $this->confirmLink($client, $link);
        self::assertResponseRedirects('/mon-compte');
        $customer = CustomerFactory::repository()->findOneBy(['email' => 'nouvelle@example.test']);
        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame(LegalDocuments::TERMS_VERSION, $customer->getTermsVersion());
        self::assertNotNull($customer->getTermsAcceptedAt());
    }

    public function testTheSignInOfAnExistingCustomerAsksNothing(): void
    {
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'lea@example.test']);
        $this->requestLink($client, 'lea@example.test');

        $client->request('GET', $this->linkOf($this->lastEmail()));
        self::assertSelectorNotExists('input[name="accept_terms"]');
    }

    public function testAGuestTicksTheBoxAndTheNewAccountRecordsIt(): void
    {
        $client = self::createClient();
        [$saleUrl, $fields] = $this->openSale();

        $client->request('GET', $saleUrl);
        self::assertSelectorExists('[data-terms-check] input[name="order_form[acceptTerms]"][required]');
        self::assertSelectorExists('[data-terms-check] a[href="/confidentialite"]');
        self::assertSelectorExists('[data-terms-check] a[href="/cgu#clients"]');

        $client->submitForm('Valider ma commande', $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-terms-check]', 'Accepte les conditions d\'utilisation pour valider ta commande.');

        $client->submitForm('Valider ma commande', [...$fields, 'order_form[acceptTerms]' => '1']);
        self::assertResponseRedirects();
        $customer = CustomerFactory::repository()->findOneBy(['email' => 'invite@example.test']);
        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame(LegalDocuments::TERMS_VERSION, $customer->getTermsVersion());
    }

    public function testAGuestNeverAcceptsOnBehalfOfAnExistingAccount(): void
    {
        $client = self::createClient();
        $existing = CustomerFactory::createOne(['email' => 'invite@example.test', 'termsVersion' => null, 'termsAcceptedAt' => null]);
        [$saleUrl, $fields] = $this->openSale();

        $client->request('GET', $saleUrl);
        $client->submitForm('Valider ma commande', [...$fields, 'order_form[acceptTerms]' => '1']);
        self::assertResponseRedirects();

        $customer = $this->reload(Customer::class, (int) $existing->getId());
        self::assertNull($customer->getTermsVersion());
        self::assertNull($customer->getTermsAcceptedAt());
    }

    public function testASignedInCustomerAcceptsANewVersionOnTheirNextOrder(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['termsVersion' => '0.9']);
        [$saleUrl, $fields] = $this->openSale();
        unset($fields['order_form[email]']);
        $client->loginUser($customer, 'main');

        $client->request('GET', $saleUrl);
        self::assertSelectorExists('input[name="order_form[acceptTerms]"]');
        $client->submitForm('Valider ma commande', [...$fields, 'order_form[acceptTerms]' => '1']);
        self::assertResponseRedirects();

        self::assertSame(LegalDocuments::TERMS_VERSION, $this->reload(Customer::class, (int) $customer->getId())->getTermsVersion());
    }

    public function testACustomerWithTheCurrentVersionSeesNoBox(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        [$saleUrl] = $this->openSale();
        $client->loginUser($customer, 'main');

        $client->request('GET', $saleUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="order_form[acceptTerms]"]');
    }

    /**
     * An open sale with one product, and the fields of a valid guest order (box not ticked).
     *
     * @return array{string, array<string, string>}
     */
    private function openSale(): array
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Pain de campagne', 520);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return [\sprintf('/%s/vente/%s', $sale->getShop()->getSlug(), $sale->getCode()), [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($offer)) => '1',
            'order_form[email]' => 'invite@example.test',
            'order_form[firstName]' => 'Inès',
            'order_form[lastName]' => 'Invitée',
        ]];
    }

    /** The sign-up limiter keeps its counters between tests. */
    private function createSignUpClient(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('limiter.registration')->create('127.0.0.1')->reset();

        return $client;
    }

    private function reloadUser(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId()) ?? throw new \LogicException('User not found.');
    }

    private function requestLink(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/connexion');
        $client->submitForm('Recevoir mon lien', ['login[email]' => $email]);
    }
}
