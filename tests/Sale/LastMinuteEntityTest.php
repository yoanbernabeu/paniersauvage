<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductUnit;
use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

/**
 * The last-minute sale on the entities (SPEC §21, lot F): a closed sale takes orders again on its
 * leftovers, without going back in its life cycle.
 */
final class LastMinuteEntityTest extends TestCase
{
    private Shop $shop;

    protected function setUp(): void
    {
        $this->shop = new Shop('Fournil du Pont', 'fournil-du-pont');
    }

    public function testAClosedSaleTakesOrdersUntilTheEndOfItsLastMinuteSale(): void
    {
        $sale = $this->closedSale();
        $bread = new SaleOffer($sale, new Product($this->shop, 'Pain', 500), maxQty: 30_000);
        new SaleOffer($sale, new Product($this->shop, 'Brioche', 700), maxQty: 10_000);
        $bread->reserve(26_000);
        $now = new \DateTimeImmutable('2026-11-15 10:00');
        $until = new \DateTimeImmutable('2026-11-16 16:00');

        self::assertFalse($sale->isLastMinute());
        self::assertFalse($sale->acceptsOrders($now));
        self::assertSame($sale->getDeadlineAt(), $sale->getOrderDeadline());
        self::assertCount(2, $sale->getOrderableOffers());

        $bread->putBackOnSale(3000);
        self::assertSame($sale, $sale->openLastMinute($until, $now));

        self::assertTrue($sale->isClosed(), 'The status stays closed.');
        self::assertTrue($sale->isLastMinute());
        self::assertTrue($sale->isLastMinuteOpen($now));
        self::assertTrue($sale->acceptsOrders($now));
        self::assertFalse($sale->acceptsOrders($until), 'It ends at $until, excluded.');
        self::assertTrue($sale->acceptsOrders($until->modify('-1 second')));
        self::assertSame($until, $sale->getOrderDeadline());
        self::assertSame($until, $sale->getLastMinuteUntil());
        self::assertSame($now, $sale->getLastMinuteOpenedAt());
        self::assertSame([$bread], $sale->getOrderableOffers());
        self::assertSame(29_000, $bread->getMaxQty());
        self::assertSame(3000, $bread->remaining());
        self::assertFalse($sale->isCustomerEditable($now), 'Orders placed during the last-minute sale are not edited online.');
    }

    public function testItOpensOnceOnAClosedSaleOnly(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00');
        $until = new \DateTimeImmutable('2026-11-16 16:00');

        $open = $this->sale()->open();
        try {
            $open->openLastMinute($until, $now);
            self::fail('An open sale has no last-minute sale.');
        } catch (\DomainException $exception) {
            self::assertSame('Sale "ABC234" cannot open a last-minute sale.', $exception->getMessage());
        }

        $sale = $this->closedSale()->openLastMinute($until, $now);
        $this->expectException(\DomainException::class);
        $sale->openLastMinute($until, $now);
    }

    public function testItEndsInTheFuture(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00');

        $this->expectExceptionObject(new \DomainException('A last-minute sale must end in the future.'));

        $this->closedSale()->openLastMinute($now, $now);
    }

    public function testEndingItStopsTheOrdersAndKeepsItsTrace(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 10:00');
        $sale = $this->closedSale();

        // Nothing to end yet.
        $sale->endLastMinute($now);
        self::assertNull($sale->getLastMinuteUntil());

        $sale->openLastMinute(new \DateTimeImmutable('2026-11-16 16:00'), $now);
        $later = $now->modify('+1 hour');
        self::assertSame($sale, $sale->endLastMinute($later));

        self::assertSame($later, $sale->getLastMinuteUntil());
        self::assertFalse($sale->isLastMinuteOpen($later));
        self::assertTrue($sale->isLastMinute());

        // Ending it again later does not move its end.
        $sale->endLastMinute($later->modify('+1 hour'));
        self::assertSame($later, $sale->getLastMinuteUntil());
    }

    public function testADoneSaleIsNoLongerALastMinuteSale(): void
    {
        $sale = $this->closedSale()->openLastMinute(new \DateTimeImmutable('2026-11-16 16:00'), new \DateTimeImmutable('2026-11-15 10:00'));
        new SaleOffer($sale, new Product($this->shop, 'Pain', 500));

        $sale->markDone();

        self::assertFalse($sale->isLastMinute());
        self::assertFalse($sale->acceptsOrders(new \DateTimeImmutable('2026-11-15 11:00')));
        self::assertSame($sale->getDeadlineAt(), $sale->getOrderDeadline());
        self::assertCount(1, $sale->getOrderableOffers());
    }

    public function testTheFirstDeliveryStartIsTheEarliest(): void
    {
        $sale = $this->closedSale();
        self::assertNull($sale->getFirstDeliveryStart());

        $point = new DeliveryPoint($this->shop, 'Place du village');
        new SaleDelivery($sale, $point, new \DateTimeImmutable('2026-11-16 18:00'), new \DateTimeImmutable('2026-11-16 19:00'));
        new SaleDelivery($sale, $point, new \DateTimeImmutable('2026-11-16 08:00'), new \DateTimeImmutable('2026-11-16 09:00'));
        new SaleDelivery($sale, $point, new \DateTimeImmutable('2026-11-16 12:00'), new \DateTimeImmutable('2026-11-16 13:00'));

        self::assertEquals(new \DateTimeImmutable('2026-11-16 08:00'), $sale->getFirstDeliveryStart());
    }

    public function testPuttingBackOnSaleNeedsAWholePositiveQuantity(): void
    {
        $sale = $this->closedSale();
        $tomatoes = new SaleOffer($sale, new Product($this->shop, 'Tomates', 450)->setUnit(ProductUnit::Kg));
        $tomatoes->putBackOnSale(1250);
        self::assertSame(1250, $tomatoes->getMaxQty());
        self::assertTrue($tomatoes->isLimited());

        $bread = new SaleOffer($sale, new Product($this->shop, 'Pain', 500));
        foreach ([0, -1000, 1500] as $quantity) {
            try {
                $bread->putBackOnSale($quantity);
                self::fail(\sprintf('%d cannot be put back on sale.', $quantity));
            } catch (\DomainException $exception) {
                self::assertSame(\sprintf('Cannot put %d thousandths of "Pain" back on sale.', $quantity), $exception->getMessage());
            }
        }
        self::assertFalse($bread->isLastMinute());
        self::assertNull($bread->getMaxQty());
    }

    private function sale(): Sale
    {
        return new Sale($this->shop, 'ABC234', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-09 08:00'), new \DateTimeImmutable('2026-11-13 20:00'), new \DateTimeImmutable('2026-11-02 10:00'));
    }

    private function closedSale(): Sale
    {
        return $this->sale()->open()->close();
    }
}
