<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\Entity\Customer;
use App\Customer\Service\CustomerEraser;
use App\Notification\Entity\NotificationLog;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\NotificationLogFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFollowerFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class AccountDeletionTest extends WebTestCase
{
    use Factories;

    public function testDeletionNeedsAnExplicitConfirmation(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(['email' => 'camille@example.test']), 'main');

        $client->request('GET', '/mon-compte/supprimer');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-anonymisation-notice]');

        $client->submitForm('Supprimer définitivement');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Coche la case pour confirmer');
        self::assertSame(1, CustomerFactory::repository()->count(['email' => 'camille@example.test']));
    }

    public function testDeletionRequiresACsrfToken(): void
    {
        $client = self::createClient();
        $client->loginUser(CustomerFactory::createOne(['email' => 'camille@example.test']), 'main');

        $client->request('POST', '/mon-compte/supprimer', ['account_deletion' => ['confirm' => '1', '_token' => 'forged']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, CustomerFactory::repository()->count(['email' => 'camille@example.test']));
    }

    public function testAccountWithoutOrderIsDeleted(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);
        ShopFollowerFactory::createOne(['customer' => $customer]);
        $customerId = (int) $customer->getId();
        $log = NotificationLogFactory::createOne(['customer' => $customer, 'type' => 'login_link']);
        $client->loginUser($customer, 'main');

        $client->request('GET', '/mon-compte/supprimer');
        $client->submitForm('Supprimer définitivement', ['account_deletion[confirm]' => true]);

        self::assertResponseRedirects('/connexion');
        self::assertSame(0, CustomerFactory::repository()->count());
        // The notification log stays, without the address.
        self::assertSame([Customer::anonymizedEmailFor($customerId)], $this->recipients());
        self::assertSame(1, NotificationLogFactory::repository()->count(['id' => $log->getId(), 'customer' => null]));
        self::assertSame(0, ShopFollowerFactory::repository()->count());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ton compte est supprimé');

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testAccountWithOrdersIsAnonymised(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test', 'firstName' => 'Camille', 'lastName' => 'Martin', 'phone' => '06 11 22 33 44']);
        $customerId = $customer->getId();
        ShopFollowerFactory::createOne(['customer' => $customer]);
        $order = OrderFactory::new()->delivered()->create([
            'customer' => $customer,
            'sale' => SaleFactory::new()->done()->create(),
            'reference' => 'PS-DONE-0001',
            'deliveryAddress' => '3 rue des Lilas',
            'note' => 'Sonner chez Martin',
        ]);
        self::assertSame('Camille Martin', $order->getCustomerDisplayName());
        NotificationLogFactory::createOne(['customer' => $customer]);
        NotificationLogFactory::createOne(['customer' => null, 'recipient' => 'producteur@example.test', 'type' => 'sale_closed']);
        $client->loginUser($customer, 'main');

        $client->request('GET', '/mon-compte/supprimer');
        self::assertSelectorExists('[data-anonymisation-notice]');
        $client->submitForm('Supprimer définitivement', ['account_deletion[confirm]' => true]);

        self::assertResponseRedirects('/connexion');
        self::assertSame(0, CustomerFactory::repository()->count(['email' => 'camille@example.test']));
        self::assertSame(0, ShopFollowerFactory::repository()->count());

        $anonymised = CustomerFactory::repository()->findOneBy(['id' => $customerId]);
        self::assertNotNull($anonymised);
        self::assertTrue(CustomerEraser::isAnonymized($anonymised));
        self::assertNull($anonymised->getFirstName());
        self::assertNull($anonymised->getLastName());
        self::assertNull($anonymised->getPhone());
        self::assertSame(Customer::anonymizedEmailFor((int) $customerId), $anonymised->getEmail());
        // No real address left in the notification logs; other people's logs are untouched.
        self::assertSame([Customer::anonymizedEmailFor((int) $customerId), 'producteur@example.test'], $this->recipients());

        // Orders stay (the producers' history), without personal data once delivered.
        $delivered = OrderFactory::repository()->findOneBy(['reference' => 'PS-DONE-0001']);
        self::assertNotNull($delivered);
        self::assertNull($delivered->getDeliveryAddress());
        self::assertNull($delivered->getNote());
        self::assertNull($delivered->getCustomerFirstName());
        self::assertNull($delivered->getCustomerLastName());
        self::assertNull($delivered->getCustomerPhone());

        // The session is closed and the old address is free again.
        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testDeletionIsBlockedWhileADeliveryIsPending(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test', 'firstName' => 'Camille', 'phone' => '06 11 22 33 44']);
        ShopFollowerFactory::createOne(['customer' => $customer]);
        OrderFactory::createOne([
            'customer' => $customer,
            'sale' => SaleFactory::createOne(),
            'reference' => 'PS-NEXT-0002',
            'deliveryAddress' => '3 rue des Lilas',
        ]);
        $client->loginUser($customer, 'main');

        // The page explains why and points at the pending order instead of offering the deletion.
        $client->request('GET', '/mon-compte/supprimer');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-pending-delivery]');
        self::assertSelectorTextContains('[data-pending-delivery]', 'PS-NEXT-0002');
        self::assertSelectorNotExists('form[name="account_deletion"]');

        // Posting the form anyway changes nothing.
        $client->request('POST', '/mon-compte/supprimer', ['account_deletion' => ['confirm' => '1']]);
        self::assertResponseStatusCodeSame(409);

        $kept = CustomerFactory::repository()->findOneBy(['email' => 'camille@example.test']);
        self::assertNotNull($kept);
        self::assertSame('Camille', $kept->getFirstName());
        self::assertSame('06 11 22 33 44', $kept->getPhone());
        self::assertSame(1, ShopFollowerFactory::repository()->count());
        self::assertSame('3 rue des Lilas', OrderFactory::repository()->findOneBy(['reference' => 'PS-NEXT-0002'])?->getDeliveryAddress());

        $client->request('GET', '/mon-compte');
        self::assertResponseIsSuccessful();
    }

    public function testDeletingAnAccountLeavesTheOthersAlone(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);
        $neighbour = CustomerFactory::createOne(['email' => 'voisin@example.test']);
        ShopFollowerFactory::createOne(['customer' => $neighbour]);
        OrderFactory::createOne(['customer' => $neighbour, 'reference' => 'PS-THEIRS-01']);
        $client->loginUser($customer, 'main');

        $client->request('GET', '/mon-compte/supprimer');
        $client->submitForm('Supprimer définitivement', ['account_deletion[confirm]' => true]);

        self::assertSame(1, CustomerFactory::repository()->count(['email' => 'voisin@example.test']));
        self::assertSame(1, ShopFollowerFactory::repository()->count());
        self::assertSame(1, OrderFactory::repository()->count(['reference' => 'PS-THEIRS-01']));
    }

    /**
     * @return list<string|null>
     */
    private function recipients(): array
    {
        return array_map(
            static fn (NotificationLog $log): ?string => $log->getRecipient(),
            NotificationLogFactory::repository()->findBy([], ['id' => 'ASC']),
        );
    }
}
