<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderEvent;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Event\OrderCancelled;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Event\OrderUpdated;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderManager;
use App\Ordering\Service\OrderReferenceGenerator;
use App\Shared\Enum\Actor;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

final class OrderManagerTest extends KernelTestCase
{
    use Factories;
    use OrderingFixtures;

    /** @var list<OrderPlaced|OrderUpdated|OrderCancelled> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->forgetDispatched();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        foreach ([OrderPlaced::class, OrderUpdated::class, OrderCancelled::class] as $eventClass) {
            $dispatcher->addListener($eventClass, function (OrderPlaced|OrderUpdated|OrderCancelled $event): void {
                $this->recorded[] = $event;
            });
        }
    }

    private function forgetDispatched(): void
    {
        $this->recorded = [];
    }

    public function testPlacingAnOrderReservesStockFreezesLinesAndDispatchesOrderPlaced(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $customer = $this->createCustomer();
        $manager = self::getContainer()->get(OrderManager::class);

        $order = $manager->placeOrder($sale, $customer, new OrderRequest(
            $this->deliveryOf($sale),
            [self::idOf($brioche) => 2000, self::idOf($baguette) => 3000],
            '  Bien cuites, merci !  ',
        ), Actor::Customer);

        self::assertMatchesRegularExpression(OrderReferenceGenerator::PATTERN, $order->getReference());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertSame($customer, $order->getCustomer());
        self::assertSame('Bien cuites, merci !', $order->getNote());
        self::assertNull($order->getDeliveryAddress());
        self::assertSame(2 * 650 + 3 * 120, $order->getTotalCents());
        self::assertSame(5, $order->getItemCount());
        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(3, $this->reservedQty($baguette));

        self::assertCount(1, $this->recorded);
        self::assertInstanceOf(OrderPlaced::class, $this->recorded[0]);
        self::assertSame($order, $this->recorded[0]->order);
        self::assertSame(Actor::Customer, $this->recorded[0]->actor);

        // The producer changes prices, VAT and names afterwards: the order does not move.
        $brioche->setPriceCents(900)->setVatRateBp(2000);
        $brioche->getProduct()->setName('Brioche XXL')->setPriceCents(900);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertSame(1660, $stored->getTotalCents());
        self::assertCount(2, $stored->getLines());
        $line = $stored->getLines()->first();
        self::assertNotFalse($line);
        self::assertSame('Brioche', $line->getLabel());
        self::assertSame(650, $line->getUnitPriceCents());
        self::assertSame(550, $line->getVatRateBp());
        self::assertSame(2000, $line->getQuantity());

        $events = $stored->getEvents();
        self::assertCount(1, $events);
        $event = $events->first();
        self::assertNotFalse($event);
        self::assertSame(OrderEventType::Placed, $event->getType());
        self::assertSame(Actor::Customer, $event->getActor());
        self::assertSame(1660, $event->getDetail()['total_cents'] ?? null);
    }

    public function testPlacingBeyondTheStockCreatesNothing(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 2);
        $baguette = $this->createOffer($sale, 'Baguette', 120, 10);
        $manager = self::getContainer()->get(OrderManager::class);

        try {
            $manager->placeOrder($sale, $this->createCustomer(), new OrderRequest(
                $this->deliveryOf($sale),
                [self::idOf($baguette) => 4000, self::idOf($brioche) => 3000],
            ), Actor::Customer);
            self::fail('Three brioches are not available.');
        } catch (InsufficientStockException $exception) {
            self::assertSame(2000, $exception->getShortageFor(self::idOf($brioche))?->remaining);
        }

        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($baguette));
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
        self::assertSame([], $this->recorded);
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->isOpen());
    }

    public function testUpdatingAppliesOnlyTheStockDifferences(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $campagne = $this->createOffer($sale, 'Campagne', 520, 4);
        $baguette = $this->createOffer($sale, 'Baguette', 120, 10);
        $noix = $this->createOffer($sale, 'Pain aux noix', 700, 3);
        $manager = self::getContainer()->get(OrderManager::class);

        $order = $manager->placeOrder($sale, $this->createCustomer(), new OrderRequest(
            $this->deliveryOf($sale),
            [self::idOf($brioche) => 2000, self::idOf($campagne) => 3000, self::idOf($baguette) => 4000],
        ), Actor::Customer);
        $this->forgetDispatched();

        // New prices: they apply to lines added from now on, never to existing lines.
        $brioche->setPriceCents(900);
        $noix->setPriceCents(750);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $manager->updateOrder($order, new OrderRequest(
            $this->deliveryOf($sale),
            [self::idOf($brioche) => 5000, self::idOf($campagne) => 1000, self::idOf($baguette) => 0, self::idOf($noix) => 2000],
            'Finalement plus de brioche',
        ), Actor::Customer);

        self::assertSame(5, $this->reservedQty($brioche));
        self::assertSame(1, $this->reservedQty($campagne));
        self::assertSame(0, $this->reservedQty($baguette));
        self::assertSame(2, $this->reservedQty($noix));

        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertSame(OrderStatus::Confirmed, $stored->getStatus());
        self::assertSame('Finalement plus de brioche', $stored->getNote());

        $lines = [];
        foreach ($stored->getLines() as $line) {
            $lines[$line->getLabel()] = [$line->getQuantity(), $line->getUnitPriceCents()];
        }
        self::assertSame([
            'Brioche' => [5000, 650],
            'Campagne' => [1000, 520],
            'Pain aux noix' => [2000, 750],
        ], $lines);
        self::assertSame(5 * 650 + 520 + 2 * 750, $stored->getTotalCents());

        $types = array_map(static fn (OrderEvent $event): OrderEventType => $event->getType(), $stored->getEvents()->toArray());
        self::assertSame([OrderEventType::Placed, OrderEventType::Updated], array_values($types));

        self::assertCount(1, $this->recorded);
        self::assertInstanceOf(OrderUpdated::class, $this->recorded[0]);
        self::assertSame(Actor::Customer, $this->recorded[0]->actor);
    }

    public function testAnUpdateWithoutAnyChangeRecordsAndDispatchesNothing(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $manager = self::getContainer()->get(OrderManager::class);

        $order = $manager->placeOrder($sale, $this->createCustomer(), new OrderRequest(
            $this->deliveryOf($sale),
            [self::idOf($brioche) => 2000],
            'Bien cuite',
        ), Actor::Customer);
        $this->forgetDispatched();
        $updatedAt = $order->getUpdatedAt();

        // Same quantities, same delivery, same note (blank spaces apart).
        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 2000], '  Bien cuite '), Actor::Customer);

        self::assertSame([], $this->recorded);
        self::assertSame(2, $this->reservedQty($brioche));
        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertCount(1, $stored->getEvents());
        self::assertSame($updatedAt->format('Y-m-d H:i:s'), $stored->getUpdatedAt()->format('Y-m-d H:i:s'));

        // A note change alone is a change.
        $manager->updateOrder($stored, new OrderRequest($stored->getSaleDelivery(), [self::idOf($brioche) => 2000], 'Pas trop cuite'), Actor::Customer);

        self::assertCount(1, $this->recorded);
        self::assertCount(2, $this->reload(Order::class, self::idOf($order))->getEvents());
    }

    public function testUpdatingBeyondTheStockChangesNothing(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120, 10);
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2, self::idOf($baguette) => 4]);
        $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($brioche) => 2]);
        $this->forgetDispatched();

        try {
            // 2 of mine + 1 left = 3 at most.
            $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 4000, self::idOf($baguette) => 1000]), Actor::Customer);
            self::fail('Only one more brioche is available.');
        } catch (InsufficientStockException $exception) {
            $shortage = $exception->getShortageFor(self::idOf($brioche));
            self::assertNotNull($shortage);
            self::assertSame(2000, $shortage->requested);
            self::assertSame(1000, $shortage->remaining);
        }

        self::assertSame(4, $this->reservedQty($brioche));
        self::assertSame(4, $this->reservedQty($baguette));
        self::assertSame([], $this->recorded);

        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertSame(2 * 650 + 4 * 120, $stored->getTotalCents());
        self::assertCount(1, $stored->getEvents());
    }

    public function testUpdatingReliesOnTheDatabaseWhenTheOrderInMemoryIsStale(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 10);
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2]);

        // Another request already raised this order to 6 brioches (line and counter).
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('UPDATE order_line SET quantity = 6000 WHERE order_id = ?', [self::idOf($order)]);
        $connection->executeStatement('UPDATE sale_offer SET reserved_qty = 6000 WHERE id = ?', [self::idOf($brioche)]);

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 3000]), Actor::Producer);

        self::assertSame(3, $this->reservedQty($brioche));
        self::assertSame(3 * 650, $this->reload(Order::class, self::idOf($order))->getTotalCents());
    }

    public function testAnOrderLeftWithoutAnyItemIsCancelled(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 3]);
        $this->forgetDispatched();

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 0]), Actor::Customer);

        self::assertTrue($order->isCancelled());
        self::assertSame(0, $this->reservedQty($brioche));
        self::assertCount(1, $this->recorded);
        self::assertInstanceOf(OrderCancelled::class, $this->recorded[0]);
    }

    public function testCancellingReleasesTheStockOnceEvenWhenAskedTwice(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 3, self::idOf($baguette) => 2]);
        $other = $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($brioche) => 2]);
        $this->forgetDispatched();

        $manager->cancelOrder($order, Actor::Customer);
        $manager->cancelOrder($order, Actor::Customer);

        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($baguette));
        self::assertCount(1, $this->recorded);
        self::assertInstanceOf(OrderCancelled::class, $this->recorded[0]);
        self::assertSame($order, $this->recorded[0]->order);
        self::assertSame(Actor::Customer, $this->recorded[0]->actor);

        $stored = $this->reload(Order::class, self::idOf($order));
        self::assertSame(OrderStatus::Cancelled, $stored->getStatus());
        self::assertCount(2, $stored->getLines(), 'A cancelled order keeps its lines for the record.');
        $types = array_map(static fn (OrderEvent $event): OrderEventType => $event->getType(), $stored->getEvents()->toArray());
        self::assertSame([OrderEventType::Placed, OrderEventType::Cancelled], array_values($types));
        self::assertTrue($this->reload(Order::class, self::idOf($other))->isConfirmed());
    }

    public function testACancelledOrderCannotBeUpdated(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $manager = self::getContainer()->get(OrderManager::class);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 3]);
        $manager->cancelOrder($order, Actor::Producer);

        try {
            $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 1000]), Actor::Producer);
            self::fail('A cancelled order must stay cancelled.');
        } catch (OrderNotAllowedException) {
        }

        self::assertSame(0, $this->reservedQty($brioche));
    }

    public function testACustomerCannotOrderOnceTheSaleIsClosedButTheProducerCan(): void
    {
        $closed = SaleFactory::new()->closed()->create(['shop' => $this->shopOf($this->createProducer())]);
        $delivery = SaleDeliveryFactory::createOne(['sale' => $closed]);
        $brioche = $this->createOffer($closed, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $manager = self::getContainer()->get(OrderManager::class);
        $request = new OrderRequest($delivery, [self::idOf($brioche) => 1000]);

        try {
            $manager->placeOrder($closed, $customer, $request, Actor::Customer);
            self::fail('The deadline has passed for customers.');
        } catch (OrderNotAllowedException) {
        }
        self::assertSame(0, $this->reservedQty($brioche));

        $order = $manager->placeOrder($closed, $customer, $request, Actor::Producer);
        self::assertSame(1, $this->reservedQty($brioche));
        self::assertInstanceOf(OrderPlaced::class, $this->recorded[0]);
        self::assertSame(Actor::Producer, $this->recorded[0]->actor);

        // After the deadline the customer can neither edit nor cancel; the producer still can.
        foreach (['update', 'cancel'] as $operation) {
            try {
                'update' === $operation
                    ? $manager->updateOrder($order, new OrderRequest($delivery, [self::idOf($brioche) => 2000]), Actor::Customer)
                    : $manager->cancelOrder($order, Actor::Customer);
                self::fail('The customer can no longer change the order.');
            } catch (OrderNotAllowedException) {
            }
        }
        self::assertSame(1, $this->reservedQty($brioche));

        $manager->updateOrder($order, new OrderRequest($delivery, [self::idOf($brioche) => 4000]), Actor::Producer);
        self::assertSame(4, $this->reservedQty($brioche));
        $manager->cancelOrder($order, Actor::Producer);
        self::assertSame(0, $this->reservedQty($brioche));
    }

    public function testACustomerCannotEditAfterTheEditCutoffOrWhenEditingIsDisabled(): void
    {
        $shop = $this->shopOf($this->createProducer());
        $cutoffPassed = $this->createSale($shop, ['editCutoffAt' => new \DateTimeImmutable('-1 hour')]);
        $noEdit = $this->createSale($shop, ['allowCustomerEdit' => false]);
        $manager = self::getContainer()->get(OrderManager::class);
        $customer = $this->createCustomer();

        foreach ([$cutoffPassed, $noEdit] as $sale) {
            $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
            // Ordering is still possible until the deadline…
            $order = $manager->placeOrder($sale, $customer, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 1000]), Actor::Customer);

            // …but not editing.
            try {
                $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 2000]), Actor::Customer);
                self::fail('The customer can no longer edit.');
            } catch (OrderNotAllowedException) {
            }
            try {
                $manager->cancelOrder($order, Actor::Customer);
                self::fail('The customer can no longer cancel.');
            } catch (OrderNotAllowedException) {
            }

            self::assertSame(1, $this->reservedQty($brioche));
            self::assertTrue($order->isConfirmed());
        }
    }

    public function testNobodyButTheSystemChangesOrdersOfADoneOrCancelledSale(): void
    {
        $shop = $this->shopOf($this->createProducer());
        $manager = self::getContainer()->get(OrderManager::class);
        $customer = $this->createCustomer();

        foreach ([SaleFactory::new()->done(), SaleFactory::new()->cancelled()] as $factory) {
            $sale = $factory->create(['shop' => $shop]);
            $delivery = SaleDeliveryFactory::createOne(['sale' => $sale]);
            $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
            $request = new OrderRequest($delivery, [self::idOf($brioche) => 1000]);

            try {
                $manager->placeOrder($sale, $customer, $request, Actor::Producer);
                self::fail('The sale is over.');
            } catch (OrderNotAllowedException) {
            }

            $order = $manager->placeOrder($sale, $customer, $request, Actor::System);

            try {
                $manager->cancelOrder($order, Actor::Producer);
                self::fail('The sale is over.');
            } catch (OrderNotAllowedException) {
            }

            $manager->cancelOrder($order, Actor::System);
            self::assertTrue($order->isCancelled());
            self::assertSame(0, $this->reservedQty($brioche));
        }
    }

    public function testHomeDeliveryRequiresAnAddressAndOtherDeliveriesIgnoreIt(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $home = $this->addHomeDelivery($sale);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $manager = self::getContainer()->get(OrderManager::class);
        $customer = $this->createCustomer();
        $quantities = [self::idOf($brioche) => 1000];

        try {
            $manager->placeOrder($sale, $customer, new OrderRequest($home, $quantities, null, '   '), Actor::Customer);
            self::fail('A home delivery needs an address.');
        } catch (OrderNotAllowedException) {
        }
        self::assertSame(0, $this->reservedQty($brioche));

        $order = $manager->placeOrder($sale, $customer, new OrderRequest($home, $quantities, null, "12 route des Écoliers\n26400 Saoû"), Actor::Customer);
        self::assertSame("12 route des Écoliers\n26400 Saoû", $order->getDeliveryAddress());

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($sale), $quantities, null, 'ignored'), Actor::Customer);
        self::assertNull($order->getDeliveryAddress());
        self::assertSame($this->deliveryOf($sale), $order->getSaleDelivery());
    }

    public function testRefusesEmptyOrdersForeignOffersAndForeignDeliveries(): void
    {
        $shop = $this->shopOf($this->createProducer());
        $sale = $this->createSale($shop);
        $otherSale = $this->createSale($shop);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $foreign = $this->createOffer($otherSale, 'Campagne', 520, 5);
        $manager = self::getContainer()->get(OrderManager::class);
        $customer = $this->createCustomer();

        $requests = [
            new OrderRequest($this->deliveryOf($sale), []),
            new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => 0]),
            new OrderRequest($this->deliveryOf($sale), [self::idOf($brioche) => -2000]),
            new OrderRequest($this->deliveryOf($sale), [self::idOf($foreign) => 1000]),
            new OrderRequest($this->deliveryOf($otherSale), [self::idOf($brioche) => 1000]),
        ];

        foreach ($requests as $request) {
            try {
                $manager->placeOrder($sale, $customer, $request, Actor::Customer);
                self::fail('This request must be refused.');
            } catch (OrderNotAllowedException) {
            }
        }

        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($foreign));
        self::assertSame([], $this->recorded);
    }
}
