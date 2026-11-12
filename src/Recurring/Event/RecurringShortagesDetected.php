<?php

declare(strict_types=1);

namespace App\Recurring\Event;

use App\Recurring\Entity\RecurringOccurrence;
use App\Sale\Entity\Sale;

/**
 * Dispatched after a generation run on a sale when some subscriptions could not get everything
 * (summary e-mail to the producer).
 */
final readonly class RecurringShortagesDetected
{
    /**
     * @param non-empty-list<RecurringOccurrence> $occurrences
     */
    public function __construct(
        public Sale $sale,
        public array $occurrences,
    ) {
    }
}
