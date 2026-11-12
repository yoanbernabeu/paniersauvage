<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\Entity\Customer;
use App\Customer\Service\AbsenceHistory;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "2 absences": the orders a customer did not pick up at this producer lately, next to their name.
 */
final class AbsenceHistoryTest extends WebTestCase
{
    use Factories;

    public function testRecentAbsencesAtThisShopOnly(): void
    {
        self::bootKernel();
        $shop = $this->shop();
        $lea = CustomerFactory::createOne();
        $current = $this->openSale($shop);
        $order = $this->order($current, $lea, OrderStatus::Confirmed);

        $this->order($this->sale($shop, '-20 days'), $lea, OrderStatus::NotDelivered);
        $this->order($this->sale($shop, '-170 days'), $lea, OrderStatus::NotDelivered);
        // Picked up, cancelled, too old, another shop, another customer: not absences.
        $this->order($this->sale($shop, '-13 days'), $lea, OrderStatus::Delivered);
        $this->order($this->sale($shop, '-6 days'), $lea, OrderStatus::Cancelled);
        $this->order($this->sale($shop, '-200 days'), $lea, OrderStatus::NotDelivered);
        $this->order($this->sale($this->shop(), '-20 days'), $lea, OrderStatus::NotDelivered);
        $this->order($this->sale($shop, '-20 days'), CustomerFactory::createOne(), OrderStatus::NotDelivered);

        self::assertSame(2, self::getContainer()->get(AbsenceHistory::class)->recentFor($order));
    }

    public function testTheSaleOfTheOrderIsNotItsOwnHistory(): void
    {
        self::bootKernel();
        $shop = $this->shop();
        $lea = CustomerFactory::createOne();
        $sale = $this->sale($shop, '-1 day', SaleStatus::Closed);
        $absent = $this->order($sale, $lea, OrderStatus::NotDelivered);
        $history = self::getContainer()->get(AbsenceHistory::class);

        self::assertSame(0, $history->recentFor($absent));

        $this->order($this->sale($shop, '-8 days'), $lea, OrderStatus::NotDelivered);
        self::assertSame(0, $history->recentFor($absent), 'Loaded once per request.');
        $history->reset();
        self::assertSame(1, $history->recentFor($absent));
    }

    public function testTheBadgeShowsOnTheSaleTheDeliverySheetAndTheOrder(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $lea = CustomerFactory::createOne(['firstName' => 'Léa', 'lastName' => 'Martin']);
        $paul = CustomerFactory::createOne(['firstName' => 'Paul', 'lastName' => 'Arnaud']);
        $sale = $this->openSale($shop);
        $leaOrder = $this->order($sale, $lea, OrderStatus::Confirmed);
        $paulOrder = $this->order($sale, $paul, OrderStatus::Confirmed);
        $this->order($this->sale($shop, '-7 days'), $lea, OrderStatus::NotDelivered);
        $this->order($this->sale($shop, '-14 days'), $lea, OrderStatus::NotDelivered);
        $this->order($this->sale($shop, '-14 days'), $paul, OrderStatus::NotDelivered);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('2 absences', trim($crawler->filter(\sprintf('[data-order="%s"] [data-absences]', $leaOrder->getReference()))->text()));
        self::assertSame('1 absence', trim($crawler->filter(\sprintf('[data-order="%s"] [data-absences]', $paulOrder->getReference()))->text()));

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId().'/livraison');
        self::assertResponseIsSuccessful();
        self::assertSame('2', $crawler->filter(\sprintf('[data-order="%s"] [data-absences]', $leaOrder->getReference()))->attr('data-absences'));
        self::assertStringContainsString('Pas venu 2 fois', (string) $crawler->filter(\sprintf('[data-order="%s"] [data-absences]', $leaOrder->getReference()))->attr('title'));

        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $sale->getId(), $leaOrder->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-absences="2"]', '2 absences');
    }

    public function testNoBadgeForACustomerWhoAlwaysCame(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $lea = CustomerFactory::createOne();
        $sale = $this->openSale($shop);
        $this->order($sale, $lea, OrderStatus::Confirmed);
        $this->order($this->sale($shop, '-7 days'), $lea, OrderStatus::Delivered);
        // Her absence at another producer stays there.
        $this->order($this->sale($this->shop(), '-7 days'), $lea, OrderStatus::NotDelivered);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-absences]');
    }

    private function shop(): Shop
    {
        return $this->shopOf(UserFactory::createOne());
    }

    private function shopOf(User $producer): Shop
    {
        return $producer->getShop() ?? throw new \LogicException('No shop.');
    }

    private function openSale(Shop $shop): Sale
    {
        $sale = SaleFactory::createOne(['shop' => $shop]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);

        return $sale;
    }

    private function sale(Shop $shop, string $delivery, SaleStatus $status = SaleStatus::Done): Sale
    {
        $date = new \DateTimeImmutable($delivery);

        return SaleFactory::createOne([
            'shop' => $shop,
            'status' => $status,
            'deliveryDate' => $date,
            'opensAt' => $date->modify('-7 days 08:00'),
            'deadlineAt' => $date->modify('-3 days 20:00'),
        ]);
    }

    private function order(Sale $sale, Customer $customer, OrderStatus $status): Order
    {
        $order = OrderFactory::createOne(['sale' => $sale, 'customer' => $customer, 'status' => $status]);
        OrderLineFactory::createOne(['order' => $order]);

        return $order;
    }
}
