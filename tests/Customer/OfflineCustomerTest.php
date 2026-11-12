<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Accounting\Service\SaleExportBuilder;
use App\Customer\Entity\Customer;
use App\Customer\Repository\CustomerRepository;
use App\Customer\Service\OfflineCustomerManager;
use App\Notification\Repository\NotificationLogRepository;
use App\Notification\Service\ReminderSender;
use App\Ordering\Entity\Order;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Service\SaleLifecycle;
use App\Shared\Enum\Actor;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Ordering\OrderingFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Customers without e-mail: a producer takes an order by phone. They belong to one shop, never
 * sign in, never receive an e-mail, and the producer can correct, erase or give them an address.
 */
final class OfflineCustomerTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    private User $producer;
    private Sale $sale;
    private SaleOffer $bread;

    public function testAProducerTakesAnOrderWithoutEmail(): void
    {
        $client = $this->scenario();
        $url = \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($this->sale));

        $crawler = $client->request('GET', $url);
        self::assertSelectorTextContains('main', 'Pas d\'e-mail ? Laisse-le vide et indique un téléphone');
        self::assertNull($crawler->filter('input[name="order_form[email]"]')->attr('required'));

        // Neither e-mail nor phone: no way to reach this customer.
        $client->submitForm('Ajouter la commande', $this->fields(['order_form[phone]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Sans e-mail, indique au moins un téléphone pour joindre ce client.');

        $client->submitForm('Ajouter la commande', $this->fields());
        self::assertResponseRedirects();
        self::assertCount(0, self::getMailerMessages());
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Commande ajoutée pour Gisèle Roux, sans e-mail : préviens-le toi-même des changements.');

        $order = $this->onlyOrder();
        $customer = $order->getCustomer();
        self::assertNull($customer->getEmail());
        self::assertFalse($customer->hasEmail());
        self::assertTrue($customer->isWithoutEmail());
        self::assertSame($this->sale->getShop()->getId(), $customer->getOwnerShop()?->getId());
        self::assertSame('Gisèle', $customer->getFirstName());
        self::assertSame('04 75 41 22 33', $customer->getPhone());
        self::assertSame('-', $customer->getUserIdentifier(), 'Nothing to sign in with.');
        self::assertSame(0, self::getContainer()->get(NotificationLogRepository::class)->count([]));

        // The edit page says it and links to the customer.
        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', self::idOf($this->sale), self::idOf($order)));
        self::assertSelectorTextContains('[data-offline-customer]', 'Client sans e-mail');
        self::assertSame(\sprintf('/pro/clients-sans-email/%d', $customer->getId()), $crawler->filter('[data-offline-customer] a')->attr('href'));
        self::assertStringContainsString('préviens-le toi-même', $crawler->filter('main')->text());
    }

    public function testTheSameCustomerIsFoundAgainInTheSameShopOnly(): void
    {
        $client = $this->scenario();
        $url = \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($this->sale));

        $client->request('GET', $url);
        $client->submitForm('Ajouter la commande', $this->fields());
        $other = $this->createSale($this->shopOf($this->producer));
        $otherBread = $this->createOffer($other, 'Pain de campagne', 520);
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($other)));
        $client->submitForm('Ajouter la commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($other)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($otherBread)) => '1',
            'order_form[firstName]' => 'GISÈLE',
            'order_form[lastName]' => 'roux',
            'order_form[phone]' => '0475412233',
        ]);
        self::assertResponseRedirects();

        $customers = self::getContainer()->get(CustomerRepository::class)->findAll();
        self::assertCount(1, $customers, 'Same phone digits and name: the same customer.');

        // Another producer typing the same details gets their own customer.
        $neighbour = $this->createProducer();
        $neighbourSale = $this->createSale($this->shopOf($neighbour));
        $neighbourBread = $this->createOffer($neighbourSale, 'Baguette', 120);
        $client->loginUser($neighbour, 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($neighbourSale)));
        $client->submitForm('Ajouter la commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($neighbourSale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($neighbourBread)) => '1',
            'order_form[firstName]' => 'Gisèle',
            'order_form[lastName]' => 'Roux',
            'order_form[phone]' => '04 75 41 22 33',
        ]);
        self::assertResponseRedirects();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $customers = self::getContainer()->get(CustomerRepository::class)->findAll();
        self::assertCount(2, $customers);
        self::assertNotSame($customers[0]->getOwnerShop()?->getId(), $customers[1]->getOwnerShop()?->getId());

        // A different name with the same phone (a household): another customer.
        self::assertNotSame(
            $customers[0],
            self::getContainer()->get(OfflineCustomerManager::class)->resolve($this->sale->getShop(), 'Paul', 'Roux', '04 75 41 22 33'),
        );
    }

    public function testNoEmailIsEverSentToACustomerWithoutEmail(): void
    {
        $this->scenario();
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->offlineOrder();
        $regular = $this->placeOrder($this->sale, $this->createCustomer('paul@example.test'), [self::idOf($this->bread) => 1]);
        self::assertSame(['paul@example.test'], $this->recipients(), 'Only Paul gets a confirmation.');
        self::assertCount(1, self::getMailerMessages());

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => 3000]), Actor::Producer);
        $manager->cancelOrder($order, Actor::Producer);
        self::assertCount(1, self::getMailerMessages());

        $order = $this->offlineOrder();
        self::getContainer()->get(SaleLifecycle::class)->cancel($this->sale);
        self::assertSame(['paul@example.test'], $this->recipients(), 'The sale cancellation only reaches Paul.');
        self::assertTrue($this->reload(Order::class, self::idOf($order))->isCancelled());
        self::assertTrue($this->reload(Order::class, self::idOf($regular))->isCancelled());

        $logs = self::getContainer()->get(NotificationLogRepository::class)->findAll();
        foreach ($logs as $log) {
            self::assertSame('paul@example.test', $log->getRecipient());
        }
    }

    public function testTheDayBeforeReminderSkipsCustomersWithoutEmail(): void
    {
        $this->scenario(['deliveryDate' => new \DateTimeImmutable('tomorrow')]);
        $this->offlineOrder();

        $sent = self::getContainer()->get(ReminderSender::class)->sendForTomorrow();

        self::assertSame(0, $sent);
    }

    public function testTheProducerCorrectsTheCustomer(): void
    {
        $client = $this->scenario();
        $order = $this->offlineOrder();
        $customer = $order->getCustomer();
        $url = \sprintf('/pro/clients-sans-email/%d', $customer->getId());

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gisèle Roux');
        self::assertSelectorTextContains('[data-offline-notice]', 'il ne reçoit aucun message');
        self::assertStringContainsString($order->getReference(), $crawler->filter('[data-offline-orders]')->text());

        $client->submitForm('Enregistrer', ['offline_customer[firstName]' => '', 'offline_customer[lastName]' => '', 'offline_customer[phone]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Indique au moins un prénom ou un nom.');
        self::assertSelectorTextContains('main', 'Sans e-mail, indique au moins un téléphone pour joindre ce client.');

        $client->submitForm('Enregistrer', ['offline_customer[firstName]' => 'Gisèle', 'offline_customer[lastName]' => 'Roux-Faure', 'offline_customer[phone]' => '06 00 00 00 01']);
        self::assertResponseRedirects($url, 303);

        $order = $this->reload(Order::class, self::idOf($order));
        self::assertSame('Gisèle Roux-Faure', $order->getCustomerDisplayName());
        self::assertSame('06 00 00 00 01', $order->getCustomerPhone());
        self::assertTrue($order->getCustomer()->isWithoutEmail());
    }

    public function testWithANewAddressTheCustomerBecomesAnAccount(): void
    {
        $client = $this->scenario();
        $order = $this->offlineOrder();
        $id = (int) $order->getCustomer()->getId();

        $client->request('GET', \sprintf('/pro/clients-sans-email/%d', $id));
        $client->submitForm('Enregistrer', ['offline_customer[email]' => 'Gisele.Roux@Example.test']);

        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', self::idOf($this->sale)), 303);
        self::assertCount(0, self::getMailerMessages(), 'Nothing is sent right away.');
        $customer = $this->reload(Customer::class, $id);
        self::assertSame('gisele.roux@example.test', $customer->getEmail());
        self::assertFalse($customer->isWithoutEmail());
        self::assertNull($customer->getOwnerShop());

        // A regular account is no longer managed by the producer.
        $client->request('GET', \sprintf('/pro/clients-sans-email/%d', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithAKnownAddressTheOrdersMoveToThatAccount(): void
    {
        $client = $this->scenario();
        $order = $this->offlineOrder();
        $id = (int) $order->getCustomer()->getId();
        $account = $this->createCustomer('gisele@example.test', 'Gigi', 'R.');

        $client->request('GET', \sprintf('/pro/clients-sans-email/%d', $id));
        $client->submitForm('Enregistrer', ['offline_customer[email]' => 'gisele@example.test']);

        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', self::idOf($this->sale)), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Un compte utilise déjà cette adresse');
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertSame($account->getId(), $order->getCustomer()->getId());
        self::assertSame('Gisèle Roux', $order->getCustomerDisplayName(), 'The order keeps the identity typed by the producer.');
        self::assertSame('Gigi', $order->getCustomer()->getFirstName(), 'The account profile is untouched.');
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(Customer::class, $id));
    }

    public function testTheProducerErasesTheCustomer(): void
    {
        $client = $this->scenario();
        $order = $this->offlineOrder();
        $id = (int) $order->getCustomer()->getId();
        $url = \sprintf('/pro/clients-sans-email/%d', $id);

        // Still to deliver: refused.
        $client->request('GET', $url);
        $client->submitForm('Effacer ses données');
        self::assertResponseRedirects($url, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'Ce client a encore une commande à livrer');

        self::getContainer()->get(OrderManager::class)->cancelOrder($this->reload(Order::class, self::idOf($order)), Actor::Producer);
        $client->request('GET', $url);
        $client->submitForm('Effacer ses données');
        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', self::idOf($this->sale)), 303);

        $customer = $this->reload(Customer::class, $id);
        self::assertTrue($customer->isAnonymized());
        self::assertFalse($customer->hasEmail());
        self::assertNull($customer->getFirstName());
        self::assertNull($customer->getPhone());
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertNull($order->getCustomerPhone());
        self::assertNull($order->getCustomerFirstName());

        $client->request('GET', $url);
        self::assertSelectorTextContains('h1', 'Client effacé');
        self::assertSelectorNotExists('form[action$="/effacer"]');
    }

    public function testExportsShowTheCustomerWithoutAddress(): void
    {
        $this->scenario();
        $this->offlineOrder();

        $csv = self::getContainer()->get(SaleExportBuilder::class)->ordersCsv($this->sale);

        self::assertStringContainsString('Roux;Gisèle;;"04 75 41 22 33"', $csv);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function managementRoutes(): iterable
    {
        yield 'pro_offline_customer_edit' => ['GET', ''];
        yield 'pro_offline_customer_edit (POST)' => ['POST', ''];
        yield 'pro_offline_customer_erase' => ['POST', '/effacer'];
    }

    #[DataProvider('managementRoutes')]
    public function testAnotherProducerNeverSeesTheCustomer(string $method, string $suffix): void
    {
        $client = $this->scenario();
        $customer = $this->offlineOrder()->getCustomer();
        $client->loginUser($this->createProducer(), 'producer');

        $client->request($method, \sprintf('/pro/clients-sans-email/%d%s', $customer->getId(), $suffix), ['_token' => 'x', 'offline_customer' => ['email' => 'pirate@example.test']]);

        self::assertResponseStatusCodeSame(404);
        $customer = $this->reload(Customer::class, (int) $customer->getId());
        self::assertSame('Gisèle', $customer->getFirstName());
        self::assertNull($customer->getEmail());
    }

    #[DataProvider('managementRoutes')]
    public function testARegularAccountIsNotManagedByTheProducer(string $method, string $suffix): void
    {
        $client = $this->scenario();
        $account = $this->placeOrder($this->sale, $this->createCustomer('paul@example.test'), [self::idOf($this->bread) => 1])->getCustomer();

        $client->request($method, \sprintf('/pro/clients-sans-email/%d%s', $account->getId(), $suffix), ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('managementRoutes')]
    public function testAnonymousVisitorsAreSentToTheLoginPage(string $method, string $suffix): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $customer = CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => 'Gisèle']);

        $client->request($method, \sprintf('/pro/clients-sans-email/%d%s', $customer->getId(), $suffix));

        self::assertResponseRedirects('/pro/connexion');
    }

    public function testEraseRequiresAValidCsrfToken(): void
    {
        $client = $this->scenario();
        $customer = $this->offlineOrder()->getCustomer();

        $client->request('POST', \sprintf('/pro/clients-sans-email/%d/effacer', $customer->getId()), ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDisplayNames(): void
    {
        self::bootKernel();
        $shop = $this->shopOf($this->createProducer());

        self::assertSame('04 75 41 22 33', CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => null, 'lastName' => null, 'phone' => '04 75 41 22 33'])->getDisplayName());
        self::assertSame('Client sans e-mail', CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => null, 'lastName' => null, 'phone' => null])->getDisplayName());
        self::assertSame('lea@example.test', $this->createCustomer('lea@example.test', null, null)->getDisplayName());
    }

    /**
     * @param array<string, mixed> $attributes sale attributes
     */
    private function scenario(array $attributes = []): KernelBrowser
    {
        $client = self::createClient();
        $this->producer = $this->createProducer();
        $this->sale = $this->createSale($this->shopOf($this->producer), $attributes);
        $this->bread = $this->createOffer($this->sale, 'Pain de campagne', 520);
        $client->loginUser($this->producer, 'producer');

        return $client;
    }

    private function offlineOrder(): Order
    {
        $customer = CustomerFactory::new()->withoutEmail($this->sale->getShop())->create(['firstName' => 'Gisèle', 'lastName' => 'Roux', 'phone' => '04 75 41 22 33']);

        return $this->placeOrder($this->sale, $customer, [self::idOf($this->bread) => 2]);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return [
            'order_form[delivery]' => (string) $this->deliveryOf($this->sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '2',
            'order_form[email]' => '',
            'order_form[firstName]' => 'Gisèle',
            'order_form[lastName]' => 'Roux',
            'order_form[phone]' => '04 75 41 22 33',
            ...$overrides,
        ];
    }

    private function onlyOrder(): Order
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findAll();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $recipients = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email) {
                foreach ($message->getTo() as $address) {
                    $recipients[] = $address->getAddress();
                }
            }
        }

        return array_values(array_unique($recipients));
    }
}
