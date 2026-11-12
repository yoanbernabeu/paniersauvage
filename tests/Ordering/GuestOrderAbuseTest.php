<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Customer\Repository\CustomerRepository;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Tests\Factory\CustomerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Guests (not signed in) cannot flood a sale with orders, nor learn anything about an existing
 * account by typing its e-mail address.
 */
final class GuestOrderAbuseTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testGuestOrdersAreThrottledPerIp(): void
    {
        $client = self::createClient();
        // Same kernel for every request: the in-memory counters live as long as the test.
        $client->disableReboot();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $baguette = $this->createOffer($sale, 'Baguette', 120);

        for ($i = 0; $i < 10; ++$i) {
            $this->orderAsGuest($client, $sale, $baguette, \sprintf('client-%d@example.test', $i));
            self::assertResponseStatusCodeSame(303);
            self::assertStringContainsString('/merci/', (string) $client->getResponse()->headers->get('Location'));
        }

        $this->orderAsGuest($client, $sale, $baguette, 'un-de-plus@example.test');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[data-flash="error"]', 'Tu as passé beaucoup de commandes d\'un coup');
        self::assertSame(10, $this->orderCount());
        self::assertSame(10, $this->reservedQty($baguette));
        self::assertSame(0, self::getContainer()->get(CustomerRepository::class)->count(['email' => 'un-de-plus@example.test']));
    }

    public function testGuestOrdersAreThrottledPerEmail(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $shop = $this->shopOf($this->createProducer());
        $sales = [];
        for ($i = 0; $i < 4; ++$i) {
            $sale = $this->createSale($shop);
            $sales[] = [$sale, $this->createOffer($sale, 'Baguette', 120)];
        }

        foreach (\array_slice($sales, 0, 3) as [$sale, $offer]) {
            $this->orderAsGuest($client, $sale, $offer, 'pressee@example.test');
            self::assertResponseStatusCodeSame(303);
        }

        [$sale, $offer] = $sales[3];
        $this->orderAsGuest($client, $sale, $offer, 'Pressee@Example.test');
        self::assertResponseStatusCodeSame(429);
        self::assertSame(3, $this->orderCount());

        // Another address is not affected by this one's limit.
        $this->orderAsGuest($client, $sale, $offer, 'tranquille@example.test');
        self::assertResponseStatusCodeSame(303);
    }

    public function testASecondActiveOrderForTheSameEmailIsRefusedAndASignInLinkIsSent(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $owner = CustomerFactory::createOne(['email' => 'lea@example.test', 'firstName' => 'Zéphyrine', 'lastName' => 'Okonkwo-Vasseur', 'phone' => '06 12 34 56 78']);
        $existing = $this->placeOrder($sale, $owner, [self::idOf($brioche) => 2]);
        $existingReference = $existing->getReference();

        $this->orderAsGuest($client, $sale, $brioche, 'LEA@example.test', 'Usurpateur', 'Anonyme');

        self::assertResponseRedirects($this->saleUrl($sale), 303);
        self::assertSame(1, $this->orderCount());
        self::assertSame(2, $this->reservedQty($brioche));

        // Only a sign-in link, sent to the address itself, leading to the existing order.
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'lea@example.test');
        self::assertEmailSubjectContains($email, 'Ton lien de connexion');
        self::assertEmailTextBodyContains($email, rawurlencode('/mes-commandes/'.$existingReference));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-flash="info"]', 'Cette adresse a déjà une commande pour cette vente');
        $page = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Zéphyrine', $page);
        self::assertStringNotContainsString('Okonkwo-Vasseur', $page);
        self::assertStringNotContainsString('06 12 34 56 78', $page);
        self::assertStringNotContainsString($existingReference, $page);

        // Once that order is cancelled, the address may order again.
        self::getContainer()->get(OrderManager::class)->cancelOrder($this->reload(Order::class, self::idOf($existing)), Actor::Producer);
        $this->orderAsGuest($client, $sale, $brioche, 'lea@example.test', 'Zéphyrine', 'Okonkwo-Vasseur');
        self::assertResponseStatusCodeSame(303);
        self::assertStringContainsString('/merci/', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(2, $this->orderCount());
    }

    public function testTheHoneypotSilentlyDropsTheOrder(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);

        $crawler = $client->request('GET', $this->saleUrl($sale));
        $field = $crawler->filter('input[name="order_form[website]"]');
        self::assertCount(1, $field);
        self::assertSame('-1', $field->attr('tabindex'));
        self::assertSame('off', $field->attr('autocomplete'));
        self::assertSame('true', $crawler->filter('[aria-hidden="true"]:has(> input[name="order_form[website]"])')->attr('aria-hidden'));

        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '1',
            'order_form[email]' => 'robot@example.test',
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => 'Robot',
            'order_form[lastName]' => 'Spam',
            'order_form[website]' => 'https://spam.example.test',
        ]);

        self::assertResponseRedirects($this->saleUrl($sale), 303);
        self::assertSame(0, $this->orderCount());
        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, self::getContainer()->get(CustomerRepository::class)->count(['email' => 'robot@example.test']));
        self::assertCount(0, self::getMailerMessages());

        $client->followRedirect();
        self::assertSelectorNotExists('[data-flash]');
    }

    public function testASignedInCustomerHasNoHoneypot(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $this->createOffer($sale, 'Brioche', 650, 5);
        $client->loginUser($this->createCustomer(), 'main');

        $client->request('GET', $this->saleUrl($sale));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="order_form[website]"]');
    }

    public function testAGuestNeverSeesNorChangesTheIdentityOfAnExistingAccount(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $known = CustomerFactory::createOne(['email' => 'lea@example.test', 'firstName' => 'Zéphyrine', 'lastName' => 'Okonkwo-Vasseur', 'phone' => '06 12 34 56 78']);
        $knownId = $known->getId();
        // An account whose profile is still empty is not completed by a guest either.
        $blank = CustomerFactory::createOne(['email' => 'vide@example.test', 'firstName' => null, 'lastName' => null, 'phone' => null]);
        $blankId = $blank->getId();

        $this->orderAsGuest($client, $sale, $brioche, 'lea@example.test', 'Usurpateur', 'Anonyme', '07 00 00 00 00');
        self::assertResponseStatusCodeSame(303);
        // The confirmation goes to the address owner, with the identity typed on the order.
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'lea@example.test');
        self::assertEmailTextBodyContains($email, 'Bonjour Usurpateur');
        self::assertEmailTextBodyNotContains($email, 'Zéphyrine');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Merci Usurpateur');
        $page = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Zéphyrine', $page);
        self::assertStringNotContainsString('Okonkwo-Vasseur', $page);
        self::assertStringNotContainsString('06 12 34 56 78', $page);

        $this->orderAsGuest($client, $sale, $brioche, 'vide@example.test', 'Inconnu', 'Pressé', '07 11 11 11 11');
        self::assertResponseStatusCodeSame(303);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $orders);
        self::assertSame($knownId, $orders[0]->getCustomer()->getId());
        self::assertSame('Usurpateur Anonyme', $orders[0]->getCustomerDisplayName());
        self::assertSame('07 00 00 00 00', $orders[0]->getCustomerPhone());
        self::assertSame($blankId, $orders[1]->getCustomer()->getId());
        self::assertSame('Inconnu Pressé', $orders[1]->getCustomerDisplayName());

        $customers = self::getContainer()->get(CustomerRepository::class);
        $known = $customers->find($knownId);
        self::assertNotNull($known);
        self::assertSame('Zéphyrine Okonkwo-Vasseur', $known->getDisplayName());
        self::assertSame('06 12 34 56 78', $known->getPhone());
        $blank = $customers->find($blankId);
        self::assertNotNull($blank);
        self::assertNull($blank->getFirstName());
        self::assertNull($blank->getLastName());
        self::assertNull($blank->getPhone());
    }

    public function testASignedInCustomerUpdatesTheirOwnProfileAndTheOrderKeepsItsIdentity(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer('lea@example.test', 'Zéphyrine', 'Martin');
        $customerId = $customer->getId();
        $client->loginUser($customer, 'main');

        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', [
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '1',
            'order_form[firstName]' => 'Léa-Rose',
            'order_form[phone]' => '06 99 88 77 66',
        ]);
        self::assertResponseStatusCodeSame(303);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $order = self::getContainer()->get(OrderRepository::class)->findOneBy([]);
        self::assertNotNull($order);
        self::assertSame('Léa-Rose Martin', $order->getCustomerDisplayName());
        self::assertSame('06 99 88 77 66', $order->getCustomerPhone());
        $profile = self::getContainer()->get(CustomerRepository::class)->find($customerId);
        self::assertNotNull($profile);
        self::assertSame('Léa-Rose', $profile->getFirstName());
        self::assertSame('06 99 88 77 66', $profile->getPhone());
    }

    private function orderAsGuest(KernelBrowser $client, Sale $sale, SaleOffer $offer, string $email, string $firstName = 'Jeanne', string $lastName = 'Durand', string $phone = ''): void
    {
        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($offer)) => '1',
            'order_form[email]' => $email,
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => $firstName,
            'order_form[lastName]' => $lastName,
            'order_form[phone]' => $phone,
        ]);
    }

    private function saleUrl(Sale $sale): string
    {
        return \sprintf('/%s/vente/%s', $sale->getShop()->getSlug(), $sale->getCode());
    }

    private function orderCount(): int
    {
        return self::getContainer()->get(OrderRepository::class)->count([]);
    }
}
