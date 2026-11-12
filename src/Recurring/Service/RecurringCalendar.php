<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Ordering\Entity\Order;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Enum\OccurrenceOutcome;
use App\Recurring\Model\UpcomingDelivery;
use App\Sale\Entity\Sale;
use App\Sale\Repository\SaleRepository;

/**
 * The coming deliveries of a subscription and the absence rules (pause, resume):
 *
 * - a delivery can be paused until the edit cutoff of its sale (the deadline by default), within
 *   the number of absences of the plan;
 * - once its order is generated, pausing cancels that order, which the subscriber must still be
 *   allowed to edit (the shop may forbid customer edits);
 * - a paused delivery can be resumed as long as no generation took place for it.
 */
final readonly class RecurringCalendar
{
    /** Deliveries listed ahead. */
    public const int WEEKS = 6;

    public function __construct(
        private SaleRepository $sales,
    ) {
    }

    /**
     * @return list<UpcomingDelivery>
     */
    public function upcoming(RecurringOrder $recurringOrder, \DateTimeImmutable $now, int $count = self::WEEKS): array
    {
        if ($recurringOrder->isEnded()) {
            return [];
        }

        $schedule = $recurringOrder->getPlan()->getSchedule();
        $salesByDay = [];
        foreach ($this->sales->findBySchedule($schedule) as $sale) {
            // One sale per recurrence and day (unique constraint).
            $salesByDay[$sale->getDeliveryDate()->format('Y-m-d')] = $sale;
        }

        $occurrencesBySale = [];
        foreach ($recurringOrder->getOccurrences() as $occurrence) {
            $occurrencesBySale[$occurrence->getSale()->getId()] = $occurrence;
        }

        $from = max($now, $recurringOrder->getStartsOn());
        $deliveries = [];
        foreach ($schedule->nextDeliveryDates($from->modify('-1 day'), $count) as $date) {
            if (!$recurringOrder->coversDate($date)) {
                continue;
            }

            $sale = $salesByDay[$date->format('Y-m-d')] ?? null;
            $occurrence = null === $sale ? null : ($occurrencesBySale[$sale->getId()] ?? null);
            $cutoffAt = $sale instanceof Sale ? $sale->getEffectiveEditCutoffAt() : ($schedule->editCutoffAtFor($date) ?? $schedule->deadlineAtFor($date));

            // A day whose orders are closed and that never got a sale will not happen.
            if (!$sale instanceof Sale && $now >= $cutoffAt) {
                continue;
            }

            $deliveries[] = $this->entry($recurringOrder, $date, $sale, $occurrence, $cutoffAt, $now);
        }

        return $deliveries;
    }

    /**
     * The coming delivery of that day, when it is listed.
     */
    public function find(RecurringOrder $recurringOrder, \DateTimeImmutable $day, \DateTimeImmutable $now): ?UpcomingDelivery
    {
        foreach ($this->upcoming($recurringOrder, $now) as $delivery) {
            if ($delivery->date->format('Y-m-d') === $day->format('Y-m-d')) {
                return $delivery;
            }
        }

        return null;
    }

    private function entry(RecurringOrder $recurringOrder, \DateTimeImmutable $date, ?Sale $sale, ?RecurringOccurrence $occurrence, \DateTimeImmutable $cutoffAt, \DateTimeImmutable $now): UpcomingDelivery
    {
        $order = $occurrence?->getOrder();
        $liveOrder = $order instanceof Order && $order->isActive() ? $order : null;
        $paused = $recurringOrder->isPausedOn($date);
        $open = $now < $cutoffAt && true !== $sale?->isCancelled() && true !== $sale?->isDone();

        $canPause = $recurringOrder->isActive() && !$paused && $open && $recurringOrder->canPauseAgain() && match (true) {
            // Not generated yet: the day is simply skipped.
            !$occurrence instanceof RecurringOccurrence => true,
            // Generated: its order is cancelled, which the subscriber must be allowed to do.
            \in_array($occurrence->getOutcome(), [OccurrenceOutcome::Placed, OccurrenceOutcome::Partial], true) => $liveOrder instanceof Order && $liveOrder->isCustomerEditable($now),
            default => false,
        };

        return new UpcomingDelivery(
            $date,
            $sale,
            $occurrence,
            $liveOrder,
            $cutoffAt,
            $paused,
            $canPause,
            $paused && $open && !$occurrence instanceof RecurringOccurrence,
        );
    }
}
