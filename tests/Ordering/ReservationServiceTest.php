<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ReservationServiceTest extends KernelTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testReservesUntilTheLimitThenRefuses(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Brioche', 650, 5);
        $service = self::getContainer()->get(ReservationService::class);

        $service->reserve($offer, 3000);
        $service->reserve($offer, 2000);
        self::assertSame(5, $this->reservedQty($offer));
        self::assertTrue($offer->isSoldOut());

        try {
            $service->reserve($offer, 1000);
            self::fail('The sixth brioche must be refused.');
        } catch (InsufficientStockException $exception) {
            $shortage = $exception->getShortageFor(self::idOf($offer));
            self::assertNotNull($shortage);
            self::assertSame('Brioche', $shortage->label);
            self::assertSame(1000, $shortage->requested);
            self::assertSame(0, $shortage->remaining);
        }

        self::assertSame(5, $this->reservedQty($offer));
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->isOpen());
    }

    public function testRefusesAQuantityLargerThanWhatIsLeftAndSaysHowManyRemain(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Brioche', 650, 5);
        $service = self::getContainer()->get(ReservationService::class);
        $service->reserve($offer, 3000);

        try {
            $service->reserve($offer, 3000);
            self::fail('Only two brioches are left.');
        } catch (InsufficientStockException $exception) {
            self::assertSame(2000, $exception->getShortages()[0]->remaining);
            self::assertStringContainsString('Brioche', $exception->getMessage());
        }

        self::assertSame(3, $this->reservedQty($offer));
    }

    public function testAnUnlimitedOfferNeverRunsOut(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Baguette', 120);
        $service = self::getContainer()->get(ReservationService::class);

        $service->reserve($offer, 500000);
        $service->reserve($offer, 500000);

        self::assertNull($offer->remaining());
        self::assertSame(1000, $this->reservedQty($offer));
    }

    public function testReleaseGivesTheStockBack(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Brioche', 650, 5);
        $service = self::getContainer()->get(ReservationService::class);

        $service->reserve($offer, 5000);
        $service->release($offer, 2000);
        self::assertSame(3, $this->reservedQty($offer));

        $service->reserve($offer, 2000);
        self::assertSame(5, $this->reservedQty($offer));

        // Releasing more than reserved never goes below zero.
        $service->release($offer, 50000);
        self::assertSame(0, $this->reservedQty($offer));
    }

    public function testSeveralChangesAreAppliedAllOrNothing(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $campagne = $this->createOffer($sale, 'Campagne', 520, 2);
        $baguette = $this->createOffer($sale, 'Baguette', 120, 10);
        $service = self::getContainer()->get(ReservationService::class);
        $service->reserve($baguette, 4000);

        try {
            $service->apply([self::idOf($brioche) => 2000, self::idOf($campagne) => 3000, self::idOf($baguette) => -4000]);
            self::fail('Three "campagne" loaves are not available.');
        } catch (InsufficientStockException $exception) {
            self::assertCount(1, $exception->getShortages());
            self::assertSame('Campagne', $exception->getShortages()[0]->label);
        }

        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($campagne));
        self::assertSame(4, $this->reservedQty($baguette));
        self::assertSame(0, $brioche->getReservedQty());

        $service->apply([self::idOf($brioche) => 2000, self::idOf($campagne) => 2000, self::idOf($baguette) => -4000]);

        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(2, $this->reservedQty($campagne));
        self::assertSame(0, $this->reservedQty($baguette));
    }

    public function testDecidesOnTheDatabaseCounterNotOnAStaleEntity(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Brioche', 650, 5);
        $service = self::getContainer()->get(ReservationService::class);

        // Someone else took four brioches behind this entity manager's back.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE sale_offer SET reserved_qty = 4000 WHERE id = ?', [self::idOf($offer)]);
        self::assertSame(0, $offer->getReservedQty());

        try {
            $service->reserve($offer, 2000);
            self::fail('Only one brioche is left in the database.');
        } catch (InsufficientStockException $exception) {
            self::assertSame(1000, $exception->getShortages()[0]->remaining);
        }

        // The refusal refreshed the entity: the form can show the right quantity again.
        self::assertSame(1000, $offer->remaining());

        $service->reserve($offer, 1000);
        self::assertSame(5, $this->reservedQty($offer));
    }

    public function testNegativeQuantitiesAreRejected(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $offer = $this->createOffer($sale, 'Brioche', 650, 5);

        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(ReservationService::class)->reserve($offer, -1);
    }
}
