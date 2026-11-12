<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationType;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Scheduler\ReconcileCancelledSalesTask;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Sale\Event\SaleCancelled;
use App\Sale\Event\SaleCancelling;
use App\Sale\Service\SaleLifecycle;
use App\Shared\Enum\Actor;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

final class SaleCancellationTest extends KernelTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testCancellingASaleCancelsItsOrdersReleasesStockAndWarnsTheCustomerOnce(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();

        $order = self::getContainer()->get(OrderManager::class)->placeOrder(
            $sale,
            $customer,
            new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 2000]),
            Actor::Customer,
        );
        self::assertSame(2000, $brioche->getReservedQty());

        self::getContainer()->get(SaleLifecycle::class)->cancel($sale);

        self::assertSame(SaleStatus::Cancelled, $sale->getStatus());
        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame(0, $brioche->getReservedQty());

        $logs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(NotificationLog::class);
        self::assertSame(1, $logs->count(['type' => NotificationType::SaleCancelled->value, 'customer' => $customer->getId()]));
        self::assertSame(0, $logs->count(['type' => NotificationType::OrderCancelled->value, 'customer' => $customer->getId()]));
    }

    public function testTheCustomerNoticeLeavesOnlyAfterTheCommit(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 1]);

        $connection = self::getContainer()->get(Connection::class);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $baseline = $connection->getTransactionNestingLevel();
        $levels = [];
        $dispatcher->addListener(SaleCancelling::class, static function () use ($connection, &$levels): void {
            $levels['cancelling'] = $connection->getTransactionNestingLevel();
        });
        $dispatcher->addListener(SaleCancelled::class, static function () use ($connection, &$levels): void {
            $levels['cancelled'] = $connection->getTransactionNestingLevel();
        }, 100);

        self::getContainer()->get(SaleLifecycle::class)->cancel($sale);

        self::assertGreaterThan($baseline, $levels['cancelling']);
        self::assertSame($baseline, $levels['cancelled']);
    }

    public function testAFailureRollsTheWholeCancellationBack(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2]);

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(SaleCancelling::class, static function (): never {
            throw new \RuntimeException('Boom');
        }, -100);
        $notified = false;
        $dispatcher->addListener(SaleCancelled::class, static function () use (&$notified): void {
            $notified = true;
        });

        try {
            self::getContainer()->get(SaleLifecycle::class)->cancel($sale);
            self::fail('The failure must surface.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($notified);
        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(SaleStatus::Open, $this->reload(Sale::class, self::idOf($sale))->getStatus());
        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertSame(OrderStatus::Confirmed, $stored->getStatus());
        self::assertCount(1, $stored->getEvents());
    }

    public function testTheSafetyNetCancelsOrdersLeftActiveInACancelledSale(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $early = $this->createCustomer('early@example.test');
        $late = $this->createCustomer('late@example.test');
        $this->placeOrder($sale, $early, [self::idOf($brioche) => 1]);
        self::getContainer()->get(SaleLifecycle::class)->cancel($sale);

        // An order committed right after the cancellation.
        $lateOrder = OrderFactory::createOne(['sale' => $sale, 'customer' => $late, 'saleDelivery' => $this->deliveryOf($sale)]);
        OrderLineFactory::createOne(['order' => $lateOrder, 'saleOffer' => $brioche, 'quantity' => 3000]);
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE sale_offer SET reserved_qty = 3000 WHERE id = ?', [self::idOf($brioche)]);

        $task = self::getContainer()->get(ReconcileCancelledSalesTask::class);
        self::assertSame(1, $task());
        self::assertSame(0, $task(), 'idempotent');

        $stored = $this->reload(Order::class, self::idOf($lateOrder));
        self::assertSame(OrderStatus::Cancelled, $stored->getStatus());
        $last = $stored->getEvents()->last();
        self::assertNotFalse($last);
        self::assertSame(OrderEventType::Cancelled, $last->getType());
        self::assertSame(Actor::System, $last->getActor());
        self::assertSame(0, $this->reservedQty($brioche));

        // Each customer got the sale notice exactly once.
        $logs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(NotificationLog::class);
        foreach (['early@example.test', 'late@example.test'] as $email) {
            self::assertSame(1, $logs->count(['type' => NotificationType::SaleCancelled->value, 'recipient' => $email]), $email);
        }
    }
}
