<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Sale\Service\SaleGenerator;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * The time is frozen on Wednesday 25 March 2026, 09:00 in Paris: the four weeks generated cross the
 * change to summer time (Sunday 29 March), and the results never depend on the day the tests run.
 */
final class SaleGeneratorTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use SaleScenario;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-03-25 09:00:00', new \DateTimeZone('Europe/Paris')));
    }

    public function testTheFourWeeksKeepTheirLocalTimesAcrossTheChangeToSummerTime(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        // Delivery on Mondays from 18:00, deadline on Sundays at 20:00.
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])], daysAhead: 5);

        $sales = $this->generator()->generateForSchedule($schedule);

        self::assertSame(
            ['2026-03-30', '2026-04-06', '2026-04-13', '2026-04-20'],
            array_map(static fn (Sale $sale): string => $sale->getDeliveryDate()->format('Y-m-d'), $sales),
        );
        foreach ($sales as $sale) {
            self::assertSame('20:00 +02:00', $sale->getDeadlineAt()->format('H:i P'));
            $delivery = $sale->getDeliveries()->first();
            self::assertNotFalse($delivery);
            self::assertSame('18:00', $delivery->getStartsAt()->format('H:i'));
        }
        self::assertEquals(new \DateTimeImmutable('2026-03-25 09:00:00'), $sales[0]->getCreatedAt());
    }

    public function testGeneratesTheSalesOfTheNextFourWeeks(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::new()->limitedTo(20)->create(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520]);
        $brioche = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 750]);
        $square = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']);
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $shop]);
        $schedule = self::schedule($shop, [$bread, $brioche], [$square, $home]);

        $sales = $this->generator()->generateForSchedule($schedule);

        self::assertCount(4, $sales);
        $expectedDates = $schedule->nextDeliveryDates(self::now(), 4);
        $codes = [];
        foreach ($sales as $index => $sale) {
            $deliveryDate = $expectedDates[$index];
            self::assertNotNull($sale->getId());
            self::assertSame($schedule, $sale->getSchedule());
            self::assertSame($shop, $sale->getShop());
            self::assertSame('Fournée de la semaine', $sale->getTitle());
            self::assertSame(SaleStatus::Scheduled, $sale->getStatus());
            self::assertEquals($deliveryDate, $sale->getDeliveryDate());
            self::assertEquals($schedule->opensAtFor($deliveryDate), $sale->getOpensAt());
            self::assertEquals($schedule->deadlineAtFor($deliveryDate), $sale->getDeadlineAt());
            self::assertNull($sale->getEditCutoffAt());

            self::assertCount(2, $sale->getDeliveries());
            $delivery = $sale->getDeliveries()->first();
            self::assertNotFalse($delivery);
            self::assertSame($square, $delivery->getDeliveryPoint());
            self::assertSame($deliveryDate->format('Y-m-d').' 18:00', $delivery->getStartsAt()->format('Y-m-d H:i'));
            self::assertSame($deliveryDate->format('Y-m-d').' 19:00', $delivery->getEndsAt()->format('Y-m-d H:i'));

            self::assertCount(2, $sale->getOffers());
            $offer = $sale->getOfferFor($bread);
            self::assertNotNull($offer);
            self::assertSame(520, $offer->getPriceCents());
            self::assertSame(20000, $offer->getMaxQty());
            self::assertSame(0, $offer->getReservedQty());
            self::assertNotNull($sale->getOfferFor($brioche));

            $codes[] = $sale->getCode();
        }
        self::assertCount(4, array_unique($codes));
    }

    public function testIsIdempotent(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        self::assertCount(4, $this->generator()->generateForSchedule($schedule));
        self::assertSame([], $this->generator()->generateForSchedule($schedule));
        self::assertSame(0, $this->generator()->generateAll());
        self::assertCount(4, $this->sales()->findBySchedule($schedule));
    }

    public function testACancelledSaleIsNotGeneratedAgain(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        $sales = $this->generator()->generateForSchedule($schedule);
        $sales[0]->cancel();
        self::entityManager()->flush();

        self::assertSame([], $this->generator()->generateForSchedule($schedule));
    }

    public function testGeneratesOnlyTheMissingWeeks(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        self::assertCount(2, $this->generator()->generateForSchedule($schedule, 2));
        self::assertCount(2, $this->generator()->generateForSchedule($schedule, 4));
        self::assertCount(4, $this->sales()->findBySchedule($schedule));
    }

    public function testSkipsADeliveryWhoseDeadlineHasAlreadyPassed(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        // Delivery tomorrow, deadline the day before at 00:00: already over for the first date.
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])], daysAhead: 1);
        $schedule->setDeadlineTime(new \DateTimeImmutable('1970-01-01 00:00'));
        self::entityManager()->flush();

        $sales = $this->generator()->generateForSchedule($schedule);

        self::assertCount(3, $sales);
        self::assertEquals($schedule->nextDeliveryDates(self::now(), 2)[1], $sales[0]->getDeliveryDate());
    }

    public function testGeneratesNothingForAnInactiveRecurrence(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])], active: false);

        self::assertSame([], $this->generator()->generateForSchedule($schedule));
        self::assertSame(0, $this->generator()->generateAll());
        self::assertSame(0, $this->sales()->count([]));
    }

    public function testGeneratesNothingWhenTheSubscriptionDoesNotAllowNewSales(): void
    {
        $shop = self::shopOf(self::expiredProducer());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        self::assertSame([], $this->generator()->generateForSchedule($schedule));
        self::assertSame(0, $this->generator()->generateAll());
        self::assertSame(0, $this->sales()->count([]));
    }

    public function testGeneratesNothingForAShopWithoutSubscription(): void
    {
        $shop = self::shopOf(UserFactory::createOne());
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        self::assertSame([], $this->generator()->generateForSchedule($schedule));
    }

    public function testSeasonalProductsAreOfferedOnlyWithinTheirPeriod(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop]);
        $archived = ProductFactory::new()->archived()->create(['shop' => $shop]);

        $schedule = self::schedule($shop, [$bread, $archived]);
        $dates = $schedule->nextDeliveryDates(self::now(), 4);

        // In season for the first two deliveries only.
        $galette = ProductFactory::new()->seasonal(null, $dates[1])->create(['shop' => $shop, 'name' => 'Galette des rois']);
        // Season starting with the last delivery.
        $stollen = ProductFactory::new()->seasonal($dates[3])->create(['shop' => $shop, 'name' => 'Stollen']);
        $schedule->addProduct($galette)->addProduct($stollen);
        self::entityManager()->flush();

        $sales = $this->generator()->generateForSchedule($schedule);

        self::assertCount(4, $sales);
        $offered = static fn (Sale $sale): array => [
            $sale->getOfferFor($bread) instanceof SaleOffer,
            $sale->getOfferFor($galette) instanceof SaleOffer,
            $sale->getOfferFor($stollen) instanceof SaleOffer,
            $sale->getOfferFor($archived) instanceof SaleOffer,
        ];
        self::assertSame([true, true, false, false], $offered($sales[0]));
        self::assertSame([true, true, false, false], $offered($sales[1]));
        self::assertSame([true, false, false, false], $offered($sales[2]));
        self::assertSame([true, false, true, false], $offered($sales[3]));
    }

    public function testARecurrenceWithoutTickedProductOffersTheDefaultRange(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $bread = ProductFactory::createOne(['shop' => $shop]);
        $inSeason = ProductFactory::new()->seasonal(self::now()->modify('-1 month'), self::now()->modify('+3 months'))->create(['shop' => $shop]);
        $outOfSeason = ProductFactory::new()->seasonal(self::now()->modify('+6 months'))->create(['shop' => $shop]);
        $archived = ProductFactory::new()->archived()->create(['shop' => $shop]);
        $foreign = ProductFactory::createOne();
        $schedule = self::schedule($shop, []);

        $sales = $this->generator()->generateForSchedule($schedule, 1);

        self::assertCount(1, $sales);
        self::assertCount(2, $sales[0]->getOffers());
        self::assertNotNull($sales[0]->getOfferFor($bread));
        self::assertNotNull($sales[0]->getOfferFor($inSeason));
        self::assertNull($sales[0]->getOfferFor($outOfSeason));
        self::assertNull($sales[0]->getOfferFor($archived));
        self::assertNull($sales[0]->getOfferFor($foreign));
    }

    public function testArchivedDeliveryPointsAreLeftOut(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $square = DeliveryPointFactory::createOne(['shop' => $shop]);
        $closedMarket = DeliveryPointFactory::createOne(['shop' => $shop, 'archived' => true]);
        $schedule = self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])], [$closedMarket, $square]);

        $sales = $this->generator()->generateForSchedule($schedule, 1);

        self::assertCount(1, $sales);
        self::assertCount(1, $sales[0]->getDeliveries());
        $delivery = $sales[0]->getDeliveries()->first();
        self::assertNotFalse($delivery);
        self::assertSame($square, $delivery->getDeliveryPoint());
    }

    public function testGeneratesNothingWithoutAnyProductToOffer(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $schedule = self::schedule($shop, [ProductFactory::new()->archived()->create(['shop' => $shop])]);

        self::assertSame([], $this->generator()->generateForSchedule($schedule));
    }

    public function testGenerateAllCoversEveryActiveRecurrence(): void
    {
        $firstShop = self::shopOf(self::subscribedProducer());
        $secondShop = self::shopOf(self::subscribedProducer());
        $expiredShop = self::shopOf(self::expiredProducer());

        self::schedule($firstShop, [ProductFactory::createOne(['shop' => $firstShop])]);
        self::schedule($firstShop, [ProductFactory::createOne(['shop' => $firstShop])], daysAhead: 3);
        self::schedule($secondShop, [ProductFactory::createOne(['shop' => $secondShop])]);
        self::schedule($secondShop, [ProductFactory::createOne(['shop' => $secondShop])], active: false);
        self::schedule($expiredShop, [ProductFactory::createOne(['shop' => $expiredShop])]);

        self::assertSame(12, $this->generator()->generateAll());
        self::assertSame(0, $this->generator()->generateAll());
        self::assertSame(8, $this->sales()->countByShop($firstShop));
        self::assertSame(4, $this->sales()->countByShop($secondShop));
        self::assertSame(0, $this->sales()->countByShop($expiredShop));
    }

    public function testAFailingRecurrenceDoesNotStopTheOthers(): void
    {
        $firstShop = self::shopOf(self::subscribedProducer());
        $secondShop = self::shopOf(self::subscribedProducer());
        $failing = self::schedule($firstShop, [ProductFactory::createOne(['shop' => $firstShop])]);
        self::schedule($secondShop, [ProductFactory::createOne(['shop' => $secondShop])]);
        $failingId = $failing->getId();

        // Another run creates the same sale between the check and the flush: unique violation.
        $connection = self::entityManager()->getConnection();
        $listener = new class($connection, (int) $failingId) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly int $scheduleId)
            {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($this->done || !$entity instanceof Sale || $entity->getSchedule()?->getId() !== $this->scheduleId) {
                        continue;
                    }
                    $this->done = true;
                    $this->connection->executeStatement(
                        "INSERT INTO sale (shop_id, schedule_id, code, title, status, opens_at, deadline_at, allow_customer_edit, delivery_date, created_at)
                         VALUES (?, ?, 'ZZZZZZ', 'Concurrente', 'scheduled', ?, ?, true, ?, NOW())",
                        [$entity->getShop()->getId(), $this->scheduleId, $entity->getOpensAt()->format('Y-m-d H:i:s'), $entity->getDeadlineAt()->format('Y-m-d H:i:s'), $entity->getDeliveryDate()->format('Y-m-d')],
                    );
                }
            }
        };
        self::entityManager()->getEventManager()->addEventListener(Events::preFlush, $listener);

        try {
            self::assertSame(4, $this->generator()->generateAll());
        } finally {
            self::entityManager()->getEventManager()->removeEventListener(Events::preFlush, $listener);
        }

        self::assertSame(4, $this->sales()->countByShop($secondShop));
        // Nothing half-written for the failing recurrence (the concurrent row went with the rollback here).
        self::assertSame(0, $this->sales()->countByShop($firstShop));

        // The next run completes the failed recurrence.
        self::assertSame(4, $this->generator()->generateAll());
        self::assertSame(4, $this->sales()->countByShop($firstShop));
    }

    private function generator(): SaleGenerator
    {
        return self::getContainer()->get(SaleGenerator::class);
    }

    private function sales(): SaleRepository
    {
        return self::getContainer()->get(SaleRepository::class);
    }
}
