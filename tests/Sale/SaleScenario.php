<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Catalog\Entity\Product;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Ordering\Entity\Order;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\SaleScheduleFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Shared set-up of the Sale tests: producers with a subscription, recurrences, orders.
 */
trait SaleScenario
{
    /**
     * A producer whose shop is in its free trial (new sales allowed).
     */
    private static function subscribedProducer(): User
    {
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::createOne(['shop' => self::shopOf($producer), 'trialEndsAt' => self::now()->modify('+30 days')]);

        return $producer;
    }

    /**
     * A producer whose trial has expired (no new sale).
     */
    private static function expiredProducer(): User
    {
        $producer = UserFactory::createOne();
        ShopSubscriptionFactory::new()->trialExpired()->create(['shop' => self::shopOf($producer), 'trialEndsAt' => self::now()->modify('-1 day')]);

        return $producer;
    }

    /**
     * The application's current time: the real one, or the frozen one of ClockSensitiveTrait::mockTime().
     */
    private static function now(): \DateTimeImmutable
    {
        return Clock::get()->now();
    }

    private static function shopOf(User $producer): Shop
    {
        return $producer->getShop() ?? throw new \LogicException('The producer has no shop.');
    }

    /**
     * An active recurrence delivering in $daysAhead days (then every week), deadline the day before at 20:00.
     *
     * @param list<DeliveryPoint>|null $points   null = one new delivery point
     * @param list<Product>            $products
     */
    private static function schedule(Shop $shop, array $products, ?array $points = null, int $daysAhead = 5, bool $active = true): SaleSchedule
    {
        $schedule = SaleScheduleFactory::createOne([
            'shop' => $shop,
            'name' => 'Fournée de la semaine',
            'active' => $active,
            'weekday' => (int) self::now()->modify(\sprintf('+%d days', $daysAhead))->format('N'),
            'deadlineDaysBefore' => 1,
            'opensDaysBefore' => 6,
        ]);

        foreach ($points ?? [DeliveryPointFactory::createOne(['shop' => $shop])] as $point) {
            $schedule->addDeliveryPoint($point);
        }
        foreach ($products as $product) {
            $schedule->addProduct($product);
        }
        self::entityManager()->flush();

        return $schedule;
    }

    /**
     * A confirmed order with one line of `$quantity` whole units, on the first delivery of the sale.
     *
     * @param array<string, mixed> $attributes
     */
    private static function order(Sale $sale, SaleOffer $offer, int $quantity = 1, array $attributes = [], ?Customer $customer = null): Order
    {
        $order = OrderFactory::createOne(['sale' => $sale, 'customer' => $customer ?? CustomerFactory::createOne()] + $attributes);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => Quantity::units($quantity)]);

        return $order;
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Reads the entity again from the database (after a request handled by another entity manager state).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function reload(string $class, ?int $id): object
    {
        $entityManager = self::entityManager();
        $entityManager->clear();

        return $entityManager->find($class, $id ?? throw new \LogicException('The entity has no id.'))
            ?? throw new \LogicException(\sprintf('%s #%d no longer exists.', $class, $id));
    }
}
