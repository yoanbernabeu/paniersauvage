<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Repository\SaleRepository;
use App\Sale\Repository\SaleScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns active recurrences into the sales of the coming weeks (SPEC §4).
 */
final readonly class SaleGenerator
{
    public function __construct(
        private SaleRepository $sales,
        private SaleScheduleRepository $schedules,
        private SaleCodeGenerator $codes,
        private ProductRepository $products,
        private SubscriptionGate $subscriptionGate,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the missing sales of the next delivery days. Idempotent: one sale per recurrence
     * and per delivery date, whatever happened to it since (a cancelled sale is not recreated).
     *
     * Nothing is generated when the recurrence is inactive, when the subscription does not allow
     * new sales, for a date whose deadline has already passed, or when the sale would have no
     * delivery point or no product to offer.
     *
     * @return list<Sale> the sales created by this call
     */
    public function generateForSchedule(SaleSchedule $schedule, int $weeks = 4): array
    {
        if ($weeks < 1 || !$schedule->isActive() || !$this->subscriptionGate->allowsNewSales($schedule->getShop())) {
            return [];
        }

        $now = $this->clock->now();

        $existingDays = [];
        foreach ($this->sales->findBySchedule($schedule) as $sale) {
            $existingDays[$sale->getDeliveryDate()->format('Y-m-d')] = true;
        }

        $usedCodes = [];
        $created = [];
        foreach ($schedule->nextDeliveryDates($now, $weeks) as $deliveryDate) {
            if (isset($existingDays[$deliveryDate->format('Y-m-d')]) || $schedule->deadlineAtFor($deliveryDate) <= $now) {
                continue;
            }

            do {
                $code = $this->codes->generate();
            } while (isset($usedCodes[$code]));

            $sale = $this->buildSale($schedule, $deliveryDate, $code, $now);
            if (!$sale instanceof Sale) {
                continue;
            }

            $usedCodes[$code] = true;
            $this->entityManager->persist($sale);
            $created[] = $sale;
        }

        if ([] !== $created) {
            // Its own transaction (a savepoint when nested), so a failure leaves the connection usable.
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();

            try {
                $this->entityManager->flush();
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();

                throw $exception;
            }
        }

        return $created;
    }

    /**
     * Generates the coming sales of every active recurrence. Each recurrence is isolated: a
     * failure (e.g. a sale created at the same time by another run, which breaks a unique
     * constraint and closes the entity manager) is logged and the next recurrences still run.
     *
     * @return int number of sales created
     */
    public function generateAll(): int
    {
        $ids = array_map(static fn (SaleSchedule $schedule): ?int => $schedule->getId(), $this->schedules->findActive());

        $count = 0;
        foreach (array_filter($ids) as $id) {
            try {
                // Fetched again: after a reset, the entities of the previous manager are detached.
                $schedule = $this->schedules->find($id);
                if (null !== $schedule) {
                    $count += \count($this->generateForSchedule($schedule));
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Sales of recurrence #{id} could not be generated: {message}', [
                    'id' => $id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                if ($this->entityManager->isOpen()) {
                    $this->entityManager->clear();
                } else {
                    $this->registry->resetManager();
                }
            }
        }

        return $count;
    }

    private function buildSale(SaleSchedule $schedule, \DateTimeImmutable $deliveryDate, string $code, \DateTimeImmutable $now): ?Sale
    {
        $points = [];
        foreach ($schedule->getDeliveryPoints() as $point) {
            if (!$point->isArchived()) {
                $points[] = $point;
            }
        }

        // Products ticked on the recurrence; none ticked = the default range of the shop (SPEC §3).
        // Either way, archived products are left out and seasonal ones are offered only within their period.
        $candidates = $schedule->getProducts()->isEmpty()
            ? $this->products->findByShop($schedule->getShop())
            : $schedule->getProducts()->toArray();
        $products = array_values(array_filter($candidates, static fn (Product $product): bool => $product->isAvailableOn($deliveryDate)));

        if ([] === $points || [] === $products) {
            return null;
        }

        usort($products, static fn (Product $a, Product $b): int => [$a->getPosition(), $a->getName()] <=> [$b->getPosition(), $b->getName()]);

        $sale = new Sale(
            $schedule->getShop(),
            $code,
            $deliveryDate,
            $schedule->opensAtFor($deliveryDate),
            $schedule->deadlineAtFor($deliveryDate),
            $now,
            $schedule->getName(),
        );
        $sale->setSchedule($schedule);
        $sale->setEditCutoffAt($schedule->editCutoffAtFor($deliveryDate));

        foreach ($points as $position => $point) {
            new SaleDelivery(
                $sale,
                $point,
                $schedule->deliveryStartsAtFor($deliveryDate),
                $schedule->deliveryEndsAtFor($deliveryDate),
                $position,
            );
        }

        foreach ($products as $product) {
            new SaleOffer($sale, $product);
        }

        return $sale;
    }
}
