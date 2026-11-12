<?php

declare(strict_types=1);

namespace App\Recurring\Model;

use App\Ordering\Entity\Order;
use App\Recurring\Entity\RecurringOccurrence;
use App\Sale\Entity\Sale;

/**
 * One coming delivery of a subscription, as shown to the subscriber: its sale once created, what the
 * generation did, and whether it can still be paused or resumed.
 */
final readonly class UpcomingDelivery
{
    public function __construct(
        public \DateTimeImmutable $date,
        public ?Sale $sale,
        public ?RecurringOccurrence $occurrence,
        /** Its order, when one was generated and is still active. */
        public ?Order $order,
        /** Until when the subscriber can pause it (edit cutoff of the sale). */
        public \DateTimeImmutable $cutoffAt,
        public bool $paused,
        public bool $canPause,
        public bool $canResume,
    ) {
    }

    public function isCancelled(): bool
    {
        return true === $this->sale?->isCancelled();
    }
}
