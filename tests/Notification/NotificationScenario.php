<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Entity\NotificationLog;
use App\Notification\Repository\NotificationLogRepository;
use App\Ordering\Entity\Order;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFactory;
use Doctrine\ORM\Query;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Shared data set (the baker "Fournil du Pont") and helpers for the notification tests.
 * No other feature dispatches the domain events yet: the tests dispatch them themselves.
 */
trait NotificationScenario
{
    private const string NBSP = "\u{00A0}";

    private function createShop(): Shop
    {
        return ShopFactory::createOne([
            'name' => 'Fournil du Pont',
            'slug' => 'fournil-du-pont',
            'phone' => '06 11 22 33 44',
            'paymentInfo' => 'Wero au 06 11 22 33 44, espèces',
        ]);
    }

    /**
     * An open sale with one delivery (Place du village, 18 h – 19 h) and one offer (Pain de campagne, 5,20 €).
     *
     * @param array<string, mixed> $attributes
     */
    private function createSale(?Shop $shop = null, array $attributes = [], ?SaleFactory $factory = null): Sale
    {
        $shop ??= $this->createShop();
        $sale = ($factory ?? SaleFactory::new())->create(['shop' => $shop, 'title' => 'Fournée du lundi', ...$attributes]);

        SaleDeliveryFactory::createOne([
            'sale' => $sale,
            'deliveryPoint' => DeliveryPointFactory::new(['shop' => $shop, 'name' => 'Place du village', 'address' => '1 place de la Mairie']),
        ]);
        SaleOfferFactory::createOne([
            'sale' => $sale,
            'product' => ProductFactory::new(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520]),
        ]);

        return $sale;
    }

    /**
     * A confirmed order of `$quantity` × Pain de campagne.
     *
     * @param array<string, mixed> $customer
     */
    private function createOrder(?Sale $sale = null, int $quantity = 2, array $customer = [], ?OrderFactory $factory = null): Order
    {
        $sale ??= $this->createSale();
        $order = ($factory ?? OrderFactory::new())->create([
            'sale' => $sale,
            'customer' => CustomerFactory::new(['firstName' => 'Camille', 'lastName' => 'Martin', ...$customer]),
        ]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $this->breadOffer($sale), 'quantity' => Quantity::units($quantity)]);

        return $order;
    }

    private function breadOffer(Sale $sale): SaleOffer
    {
        $offer = $sale->getOffers()->first();
        self::assertInstanceOf(SaleOffer::class, $offer);

        return $offer;
    }

    private function dispatch(object $event): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch($event);
    }

    /**
     * @return list<Email>
     */
    private function emails(): array
    {
        $emails = [];
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email) {
                $emails[] = $message;
            }
        }

        return $emails;
    }

    private function onlyEmail(): Email
    {
        $emails = $this->emails();
        self::assertCount(1, $emails);

        return $emails[0];
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $recipients = [];
        foreach ($this->emails() as $email) {
            foreach ($email->getTo() as $address) {
                $recipients[] = $address->getAddress();
            }
        }
        sort($recipients);

        return $recipients;
    }

    /**
     * Logs are written through the connection: always read their current state from the database.
     *
     * @return list<NotificationLog>
     */
    private function logs(): array
    {
        /** @var list<NotificationLog> $logs */
        $logs = self::getContainer()->get(NotificationLogRepository::class)->createQueryBuilder('l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $logs;
    }

    private function money(string $amount): string
    {
        return $amount.self::NBSP.'€';
    }
}
