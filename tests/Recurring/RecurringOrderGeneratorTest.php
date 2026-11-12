<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Repository\OrderRepository;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Scheduler\RecurringOrderTask;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Sale\Event\SaleOpened;
use App\Sale\Service\SaleLifecycle;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use App\Tests\Factory\CustomerFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

final class RecurringOrderGeneratorTest extends KernelTestCase
{
    use Factories;
    use RecurringScenario;

    public function testTheOpeningOfASaleCreatesTheOrdersOfTheSubscribersFirst(): void
    {
        $plan = self::basketPlan();
        $claire = self::subscription($plan, CustomerFactory::createOne(['firstName' => 'Claire']), shares: 2);
        $sale = self::openSale($plan, attributes: ['status' => SaleStatus::Scheduled, 'opensAt' => self::now()->modify('-1 hour')]);

        self::assertSame(1, self::getContainer()->get(SaleLifecycle::class)->openDueSales());

        $occurrence = self::onlyOccurrence($sale);
        self::assertSame(OccurrenceOutcome::Placed, $occurrence->getOutcome());
        self::assertSame([], $occurrence->getMissing());
        $order = $occurrence->getOrder();
        self::assertInstanceOf(Order::class, $order);
        self::assertSame($claire->getId(), $occurrence->getRecurringOrder()->getId());
        self::assertSame(OrderSource::Subscription, $order->getSource());
        self::assertSame(3600, $order->getTotalCents(), '2 baskets of 18 €.');
        self::assertSame(Quantity::units(2), self::first($order->getLines())->getQuantity());
        self::assertSame('Place du marché', $order->getSaleDelivery()->getDeliveryPoint()->getName());
        self::assertSame($sale->getDeliveryDate()->format('Y-m-d'), $occurrence->getDeliveryDate()->format('Y-m-d'));
        self::assertSame(Actor::System, self::first($order->getEvents())->getActor());
        $offer = self::first($order->getLines())->getSaleOffer();
        self::assertInstanceOf(SaleOffer::class, $offer);
        self::assertSame(Quantity::units(2), $offer->getReservedQty(), 'Stock reserved through ReservationService.');

        // Its own e-mail, never the usual order confirmation; then the followers' announcement.
        self::assertSame(['recurring_order_generated'], array_values(array_filter(self::sentTypes(), static fn (string $type): bool => 'sale_opened' !== $type)));
    }

    public function testTheGenerationIsIdempotent(): void
    {
        $plan = self::basketPlan();
        self::subscription($plan);
        $sale = self::openSale($plan);

        self::assertCount(1, self::generator()->generateForSale($sale));
        self::assertSame([], self::generator()->generateForSale(self::reload(Sale::class, $sale->getId())));
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new SaleOpened(self::reload(Sale::class, $sale->getId())));
        self::assertSame(0, self::getContainer()->get(RecurringOrderTask::class)()['generated']);

        self::assertCount(1, self::occurrencesOf($sale));
        self::assertCount(1, self::getContainer()->get(OrderRepository::class)->findBy(['sale' => $sale->getId()]));
        self::assertSame(['recurring_order_generated'], self::sentTypes());
    }

    public function testTheDatabaseRefusesASecondOccurrenceForTheSameSale(): void
    {
        $plan = self::basketPlan();
        $recurringOrder = self::subscription($plan);
        $sale = self::openSale($plan);
        self::generator()->generateForSale($sale);
        $sale = self::reload(Sale::class, $sale->getId());
        $recurringOrder = self::entityManager()->find(RecurringOrder::class, $recurringOrder->getId());
        self::assertInstanceOf(RecurringOrder::class, $recurringOrder);

        $this->expectException(UniqueConstraintViolationException::class);
        self::entityManager()->persist(new RecurringOccurrence($recurringOrder, $sale, OccurrenceOutcome::Paused, self::now()));
        self::entityManager()->flush();
    }
}
