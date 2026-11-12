<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Catalog\Entity\Product;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaleTest extends TestCase
{
    private function sale(): Sale
    {
        // Delivery on Monday 16 November 2026, orders from Monday 9th 08:00 to Friday 13th 20:00.
        return new Sale(
            new Shop('Fournil du Pont', 'fournil-du-pont'),
            'ABC234',
            new \DateTimeImmutable('2026-11-16 15:00'),
            new \DateTimeImmutable('2026-11-09 08:00'),
            new \DateTimeImmutable('2026-11-13 20:00'),
            new \DateTimeImmutable('2026-11-02 10:00'),
            'Livraison du lundi',
        );
    }

    public function testANewSaleIsScheduled(): void
    {
        $sale = $this->sale();

        self::assertSame(SaleStatus::Scheduled, $sale->getStatus());
        self::assertFalse($sale->isOpen());
        self::assertSame('2026-11-16 00:00', $sale->getDeliveryDate()->format('Y-m-d H:i'));
        self::assertTrue($sale->isAllowCustomerEdit());
    }

    public function testIsOpenFollowsTheStatus(): void
    {
        $sale = $this->sale();

        self::assertTrue($sale->open()->isOpen());
        self::assertFalse($sale->close()->isOpen());
        self::assertTrue($sale->isClosed());
        self::assertTrue($sale->markDone()->isDone());
        self::assertTrue($this->sale()->cancel()->isCancelled());
    }

    /**
     * @return iterable<string, array{\Closure(Sale): mixed}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'close a scheduled sale' => [static fn (Sale $sale): Sale => $sale->close()];
        yield 'finish an open sale' => [static fn (Sale $sale): Sale => $sale->open()->markDone()];
        yield 'open an open sale' => [static fn (Sale $sale): Sale => $sale->open()->open()];
        yield 'reopen a closed sale' => [static fn (Sale $sale): Sale => $sale->open()->close()->open()];
        yield 'cancel a done sale' => [static fn (Sale $sale): Sale => $sale->open()->close()->markDone()->cancel()];
        yield 'cancel twice' => [static fn (Sale $sale): Sale => $sale->cancel()->cancel()];
        yield 'open a cancelled sale' => [static fn (Sale $sale): Sale => $sale->cancel()->open()];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitionsThrow(\Closure $transition): void
    {
        $this->expectException(\DomainException::class);

        $transition($this->sale());
    }

    public function testAcceptsOrdersOnlyWhileOpenAndBeforeTheDeadline(): void
    {
        $sale = $this->sale();
        $before = new \DateTimeImmutable('2026-11-12 10:00');
        $after = new \DateTimeImmutable('2026-11-13 20:00');

        self::assertFalse($sale->acceptsOrders($before), 'a scheduled sale takes no order');
        $sale->open();
        self::assertTrue($sale->acceptsOrders($before));
        self::assertFalse($sale->acceptsOrders($after), 'the deadline itself is too late');
    }

    public function testCustomerCanEditUntilTheDeadlineByDefault(): void
    {
        $sale = $this->sale()->open();

        self::assertEquals($sale->getDeadlineAt(), $sale->getEffectiveEditCutoffAt());
        self::assertTrue($sale->isCustomerEditable(new \DateTimeImmutable('2026-11-13 19:59')));
        self::assertFalse($sale->isCustomerEditable(new \DateTimeImmutable('2026-11-13 20:00')));
    }

    public function testCustomerEditStopsAtTheCutoff(): void
    {
        $sale = $this->sale()->open()->setEditCutoffAt(new \DateTimeImmutable('2026-11-12 20:00'));

        self::assertTrue($sale->isCustomerEditable(new \DateTimeImmutable('2026-11-12 19:00')));
        self::assertFalse($sale->isCustomerEditable(new \DateTimeImmutable('2026-11-12 21:00')));
        self::assertTrue($sale->acceptsOrders(new \DateTimeImmutable('2026-11-12 21:00')), 'new orders are still welcome');
    }

    public function testCustomerCannotEditWhenTheProducerDisallowsItOrTheSaleIsNotOpen(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 10:00');

        self::assertFalse($this->sale()->open()->setAllowCustomerEdit(false)->isCustomerEditable($now));
        self::assertFalse($this->sale()->isCustomerEditable($now));
        self::assertFalse($this->sale()->open()->close()->isCustomerEditable($now));
        self::assertFalse($this->sale()->cancel()->isCustomerEditable($now));
    }

    public function testANewSaleInheritsTheShopEditPolicy(): void
    {
        $shop = new Shop('Fournil', 'fournil')->setAllowCustomerEdit(false);
        $sale = new Sale($shop, 'ABC234', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-09 08:00'), new \DateTimeImmutable('2026-11-13 20:00'), new \DateTimeImmutable('2026-11-02 10:00'));

        self::assertFalse($sale->isAllowCustomerEdit());
    }

    public function testSchedulerTransitions(): void
    {
        $sale = $this->sale();

        self::assertFalse($sale->isDueToOpen(new \DateTimeImmutable('2026-11-09 07:59')));
        self::assertTrue($sale->isDueToOpen(new \DateTimeImmutable('2026-11-09 08:00')));

        $sale->open();
        self::assertFalse($sale->isDueToOpen(new \DateTimeImmutable('2026-11-10 08:00')));
        self::assertFalse($sale->isDueToClose(new \DateTimeImmutable('2026-11-13 19:59')));
        self::assertTrue($sale->isDueToClose(new \DateTimeImmutable('2026-11-13 20:00')));

        $sale->close();
        self::assertFalse($sale->isDueToFinish(new \DateTimeImmutable('2026-11-16 23:00')));
        self::assertTrue($sale->isDueToFinish(new \DateTimeImmutable('2026-11-17 00:00')));
    }

    public function testOffersAttachThemselvesAndCopyTheProduct(): void
    {
        $sale = $this->sale();
        $bread = new Product($sale->getShop(), 'Pain de campagne', 520)->setVatRateBp(550);
        $brioche = new Product($sale->getShop(), 'Brioche au beurre', 800)->setMaxQtyDefault(12000);

        $breadOffer = new SaleOffer($sale, $bread);
        $briocheOffer = new SaleOffer($sale, $brioche);

        self::assertCount(2, $sale->getOffers());
        self::assertSame($breadOffer, $sale->getOfferFor($bread));
        self::assertSame(520, $breadOffer->getPriceCents());
        self::assertSame(550, $breadOffer->getVatRateBp());
        self::assertNull($breadOffer->getMaxQty());
        self::assertSame(12000, $briocheOffer->getMaxQty());

        // The offer keeps its price when the product changes afterwards.
        $bread->setPriceCents(600);
        self::assertSame(520, $breadOffer->getPriceCents());
    }

    public function testScheduleComputesTheDatesOfTheRunningExample(): void
    {
        $schedule = new SaleSchedule(new Shop('Fournil', 'fournil'), 'Livraison du lundi');
        $monday = new \DateTimeImmutable('2026-11-16');

        self::assertSame('2026-11-13 20:00', $schedule->deadlineAtFor($monday)->format('Y-m-d H:i'), 'Friday 8 pm');
        self::assertSame('2026-11-09 08:00', $schedule->opensAtFor($monday)->format('Y-m-d H:i'));
        self::assertSame('2026-11-16 18:00', $schedule->deliveryStartsAtFor($monday)->format('Y-m-d H:i'));
        self::assertSame('2026-11-16 19:00', $schedule->deliveryEndsAtFor($monday)->format('Y-m-d H:i'));
        self::assertNull($schedule->editCutoffAtFor($monday));
        self::assertSame('2026-11-12 20:00', $schedule->setEditCutoffHours(24)->editCutoffAtFor($monday)?->format('Y-m-d H:i'));
    }

    public function testScheduleListsTheNextDeliveryDates(): void
    {
        $schedule = new SaleSchedule(new Shop('Fournil', 'fournil'), 'Livraison du lundi');

        $dates = array_map(
            static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d H:i'),
            $schedule->nextDeliveryDates(new \DateTimeImmutable('2026-11-09 10:00'), 4),
        );

        // From a Monday, the first date is the following Monday.
        self::assertSame(['2026-11-16 00:00', '2026-11-23 00:00', '2026-11-30 00:00', '2026-12-07 00:00'], $dates);
    }
}
