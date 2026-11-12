<?php

declare(strict_types=1);

namespace App\Recurring\Event;

use App\Recurring\Entity\RecurringOccurrence;

/**
 * Dispatched once the generation for one subscription and one sale is committed and the subscriber
 * has something to know: an order was created (complete or not), or none could be (stock, point).
 * Not dispatched for a paused delivery nor when the subscriber had already ordered.
 */
final readonly class RecurringOrderGenerated
{
    public function __construct(
        public RecurringOccurrence $occurrence,
    ) {
    }
}
