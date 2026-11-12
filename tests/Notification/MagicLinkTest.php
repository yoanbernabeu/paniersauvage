<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Ordering\Event\OrderPlaced;
use App\Shared\Enum\Actor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * The "Voir ma commande" link logs the customer in (firewall `main`) and lands on their order.
 */
final class MagicLinkTest extends WebTestCase
{
    use Factories;
    use NotificationScenario;

    public function testTheOrderLinkLogsTheCustomerInAndRedirectsToTheOrder(): void
    {
        $client = self::createClient();
        $order = $this->createOrder(customer: ['email' => 'camille@example.test']);
        $this->dispatch(new OrderPlaced($order, Actor::Producer));

        $email = $this->onlyEmail();
        $url = $this->orderLink((string) $email->getTextBody());

        self::assertStringStartsWith('http://localhost:8000/connexion/verification?', $url);
        self::assertStringContainsString('user=camille@example.test', $url);
        self::assertStringContainsString('_target_path='.rawurlencode('/mes-commandes/'.$order->getReference()), $url);
        self::assertEmailHtmlBodyContains($email, htmlspecialchars($url));

        // The link opens a confirmation page; the button signs in and lands on the order.
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-confirm-email]', 'camille@example.test');
        $client->submit($crawler->filter('main form')->form());

        self::assertResponseRedirects('/mes-commandes/'.$order->getReference());
        self::assertTrue($client->getRequest()->getSession()->has('_security_main'));
    }

    public function testTheOrderLinkIsValidFor48HoursAndOnlyOnce(): void
    {
        $client = self::createClient();
        $order = $this->createOrder(customer: ['email' => 'camille@example.test']);
        $this->dispatch(new OrderPlaced($order, Actor::Producer));
        $url = $this->orderLink((string) $this->onlyEmail()->getTextBody());

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertIsString($query['expires'] ?? null);
        self::assertEqualsWithDelta(time() + 48 * 3600, (int) $query['expires'], 60);

        $crawler = $client->request('GET', $url);
        $client->submit($crawler->filter('main form')->form());
        self::assertResponseRedirects('/mes-commandes/'.$order->getReference());

        // Used: it now offers a new link, which leads to the same order.
        $client->getCookieJar()->clear();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertInputValueSame('login[email]', 'camille@example.test');

        $client->submitForm('Recevoir mon lien');
        self::assertResponseRedirects('/connexion/lien-envoye');
        $messages = self::getMailerMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last);
        self::assertStringContainsString('_target_path='.rawurlencode('/mes-commandes/'.$order->getReference()), (string) $last->getTextBody());
    }

    public function testATamperedLinkDoesNotLogIn(): void
    {
        $client = self::createClient();
        $order = $this->createOrder(customer: ['email' => 'camille@example.test']);
        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        $url = str_replace('camille@example.test', 'someone-else@example.test', $this->orderLink((string) $this->onlyEmail()->getTextBody()));
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-login-link-error]');
        self::assertSelectorNotExists('[data-confirm-email]');
        self::assertFalse($client->getRequest()->getSession()->has('_security_main'));
    }

    private function orderLink(string $textBody): string
    {
        if (1 !== preg_match('#^Voir ma commande : (\S+)$#m', $textBody, $matches)) {
            self::fail('The e-mail has no "Voir ma commande" link.');
        }

        return $matches[1];
    }
}
