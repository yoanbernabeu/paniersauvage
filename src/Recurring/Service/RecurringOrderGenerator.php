<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderManager;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Event\RecurringOrderGenerated;
use App\Recurring\Event\RecurringShortagesDetected;
use App\Recurring\Repository\RecurringOccurrenceRepository;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Repository\SaleRepository;
use App\Shared\Enum\Actor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the orders of the subscribers when a sale of their recurrence opens (SPEC « lot H »).
 *
 * - **Through the usual doors**: OrderManager places the order and ReservationService reserves the
 *   stock (`SELECT … FOR UPDATE`), so a subscription never oversells.
 * - **Stock rule**: each product short is reduced to what is left (within its selling step and
 *   minimum) or left out; a product the sale does not offer is left out. The order is created with
 *   what remains (outcome `partial`), or not at all when nothing remains (`out_of_stock`). The
 *   subscriber (RecurringOrderGenerated) and the producer (RecurringShortagesDetected) are told.
 * - **Idempotent**: one RecurringOccurrence per subscription and sale (unique constraint), checked
 *   under a lock on the subscription row, so concurrent runs never create two orders.
 * - **Robust**: each subscription is generated in its own transaction; a failure is logged, the
 *   entity manager is reset when needed and the next subscribers are still served. Nothing is
 *   recorded for the failed one: the next run tries again.
 *
 * Runs when a sale opens (OpenSaleListener), right after a subscription (for the sales already
 * open) and every 15 minutes as a catch-up (RecurringOrderTask).
 */
final readonly class RecurringOrderGenerator
{
    /** A concurrent order may take the stock between two attempts: retried a few times only. */
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private RecurringPlanRepository $plans,
        private RecurringOrderRepository $recurringOrders,
        private RecurringOccurrenceRepository $occurrences,
        private SaleRepository $sales,
        private OrderRepository $orders,
        private OrderManager $orderManager,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generates the missing orders of the sale for every active subscriber of its recurrence.
     *
     * @return list<RecurringOccurrence> the occurrences recorded by this call
     */
    public function generateForSale(Sale $sale): array
    {
        $saleId = $sale->getId();
        $schedule = $sale->getSchedule();
        if (null === $saleId || !$schedule instanceof SaleSchedule || !$sale->acceptsOrders($this->clock->now())) {
            return [];
        }

        $plan = $this->plans->findOneBySchedule($schedule);
        if (!$plan instanceof RecurringPlan) {
            return [];
        }

        return $this->run($saleId, $this->recurringOrders->findIdsToGenerate($plan, $sale));
    }

    /**
     * Generates the orders of a new subscription in the sales of its recurrence already open.
     *
     * @return list<RecurringOccurrence>
     */
    public function generateForRecurringOrder(RecurringOrder $recurringOrder): array
    {
        $id = $recurringOrder->getId() ?? throw new \LogicException('The recurring order must be saved.');

        $created = [];
        foreach ($this->plans->findSalesTakingOrders($recurringOrder->getPlan(), $this->clock->now()) as $sale) {
            if (null !== $sale->getId() && $recurringOrder->coversDate($sale->getDeliveryDate())) {
                $created = [...$created, ...$this->run($sale->getId(), [$id])];
            }
        }

        return $created;
    }

    /**
     * Catch-up of every open sale of a recurrence with a plan (missed openings, failed runs).
     *
     * @return int number of occurrences recorded
     */
    public function generateAll(): int
    {
        $count = 0;
        foreach ($this->plans->findSaleIdsToGenerate($this->clock->now()) as $saleId) {
            try {
                $sale = $this->sales->find($saleId);
                if ($sale instanceof Sale) {
                    $count += \count($this->generateForSale($sale));
                }
            } catch (\Throwable $exception) {
                $this->recover($exception, 'Recurring orders of sale #{id} could not be generated: {message}', $saleId);
            }
        }

        return $count;
    }

    /**
     * @param list<int> $recurringOrderIds
     *
     * @return list<RecurringOccurrence>
     */
    private function run(int $saleId, array $recurringOrderIds): array
    {
        $created = [];
        $shortageIds = [];

        foreach ($recurringOrderIds as $id) {
            try {
                // Fetched again: after a reset, the entities of the previous manager are detached.
                $sale = $this->sales->find($saleId);
                $recurringOrder = $this->recurringOrders->find($id);
                if (!$sale instanceof Sale || !$recurringOrder instanceof RecurringOrder) {
                    continue;
                }

                $occurrence = $this->generate($recurringOrder, $sale);
                if (!$occurrence instanceof RecurringOccurrence) {
                    continue;
                }

                $created[] = $occurrence;
                if ($occurrence->getOutcome()->isShortage()) {
                    $shortageIds[] = $occurrence->getId();
                }
                if (!\in_array($occurrence->getOutcome(), [OccurrenceOutcome::Paused, OccurrenceOutcome::AlreadyOrdered], true)) {
                    $this->eventDispatcher->dispatch(new RecurringOrderGenerated($occurrence));
                }
            } catch (\Throwable $exception) {
                $this->recover($exception, 'Recurring order #{id} could not be generated: {message}', $id);
            }
        }

        $sale = [] === $shortageIds ? null : $this->sales->find($saleId);
        $shortages = $sale instanceof Sale ? $this->occurrences->findBy(['id' => $shortageIds], ['id' => 'ASC']) : [];
        if ($sale instanceof Sale && [] !== $shortages) {
            $this->eventDispatcher->dispatch(new RecurringShortagesDetected($sale, $shortages));
        }

        return $created;
    }

    /**
     * One subscription, one sale, one transaction.
     */
    private function generate(RecurringOrder $recurringOrder, Sale $sale): ?RecurringOccurrence
    {
        $now = $this->clock->now();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Locks the subscription: a second run waits here, then sees the occurrence of the first one.
            $this->entityManager->refresh($recurringOrder, LockMode::PESSIMISTIC_WRITE);
            foreach ($recurringOrder->getLines() as $line) {
                $this->entityManager->refresh($line);
            }

            // The callers chose sales taking orders and covered days; status and occurrence are checked again under the lock.
            if (!$recurringOrder->isActive() || $this->occurrences->findOneFor($recurringOrder, $sale) instanceof RecurringOccurrence) {
                $connection->commit();

                return null;
            }

            $occurrence = $this->decide($recurringOrder, $sale, $now);
            $this->entityManager->persist($occurrence);
            $this->entityManager->flush();
            $connection->commit();

            return $occurrence;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function decide(RecurringOrder $recurringOrder, Sale $sale, \DateTimeImmutable $now): RecurringOccurrence
    {
        if ($recurringOrder->isPausedOn($sale->getDeliveryDate())) {
            return new RecurringOccurrence($recurringOrder, $sale, OccurrenceOutcome::Paused, $now);
        }

        $customer = $recurringOrder->getCustomer();
        $existing = $this->orders->findActiveForCustomerInSale($customer, $sale);
        if ($existing instanceof Order) {
            return new RecurringOccurrence($recurringOrder, $sale, OccurrenceOutcome::AlreadyOrdered, $now)->setOrder($existing);
        }

        $wanted = [];
        $missing = [];
        foreach ($recurringOrder->wantedQuantities() as [$product, $quantity]) {
            $offer = $sale->getOfferFor($product);
            if ($offer instanceof SaleOffer && null !== $offer->getId()) {
                $wanted[$offer->getId()] = [$offer, $quantity];
            } else {
                $missing[] = self::missing($product->getName(), $quantity, 0, $product->getUnit()->value);
            }
        }

        $delivery = $this->deliveryFor($recurringOrder, $sale);
        if (!$delivery instanceof SaleDelivery) {
            $all = array_map(static fn (array $entry): array => self::missing($entry[0]->getProduct()->getName(), $entry[1], 0, $entry[0]->getProduct()->getUnit()->value), $wanted);

            return new RecurringOccurrence($recurringOrder, $sale, OccurrenceOutcome::Unavailable, $now)->setMissing([...$all, ...$missing]);
        }

        $quantities = array_map(static fn (array $entry): int => $entry[1], $wanted);
        $order = $this->place($recurringOrder, $sale, $delivery, $quantities);

        foreach ($wanted as [$offer, $quantity]) {
            $given = $order?->getLineFor($offer)?->getQuantity() ?? 0;
            if ($given < $quantity) {
                $missing[] = self::missing($offer->getProduct()->getName(), $quantity, $given, $offer->getProduct()->getUnit()->value);
            }
        }

        $outcome = match (true) {
            !$order instanceof Order => OccurrenceOutcome::OutOfStock,
            [] === $missing => OccurrenceOutcome::Placed,
            default => OccurrenceOutcome::Partial,
        };

        return new RecurringOccurrence($recurringOrder, $sale, $outcome, $now)->setOrder($order)->setMissing($missing);
    }

    /**
     * Places the order, reducing each short product to what is left; null when nothing is left.
     *
     * @param array<int, int> $quantities sale offer id => quantity in thousandths
     */
    private function place(RecurringOrder $recurringOrder, Sale $sale, SaleDelivery $delivery, array $quantities): ?Order
    {
        for ($attempt = 1;; ++$attempt) {
            $quantities = array_filter($quantities, static fn (int $quantity): bool => $quantity > 0);
            if ([] === $quantities) {
                return null;
            }

            try {
                return $this->orderManager->placeOrder(
                    $sale,
                    $recurringOrder->getCustomer(),
                    new OrderRequest($delivery, $quantities, null, $delivery->isHome() ? $recurringOrder->getDeliveryAddress() : null),
                    Actor::System,
                    OrderSource::Subscription,
                );
            } catch (InsufficientStockException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                foreach ($exception->getShortages() as $shortage) {
                    $quantities[$shortage->offerId] = $shortage->orderable($shortage->remaining);
                }
            }
        }
    }

    private function deliveryFor(RecurringOrder $recurringOrder, Sale $sale): ?SaleDelivery
    {
        foreach ($sale->getDeliveries() as $delivery) {
            if ($delivery->getDeliveryPoint() === $recurringOrder->getDeliveryPoint()) {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * @return array{label: string, wanted: int, given: int, unit: string}
     */
    private static function missing(string $label, int $wanted, int $given, string $unit): array
    {
        return ['label' => $label, 'wanted' => $wanted, 'given' => $given, 'unit' => $unit];
    }

    private function recover(\Throwable $exception, string $message, int $id): void
    {
        $this->logger->error($message, [
            'id' => $id,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        // A failed flush closes the entity manager: a new one serves the next subscribers. An open one
        // is left as is (never cleared): the generation also runs inside the opening of a sale, whose
        // caller keeps working with its entities.
        if (!$this->entityManager->isOpen()) {
            $this->registry->resetManager();
        }
    }
}
