<?php

declare(strict_types=1);

namespace App\Recurring\Event;

use App\Recurring\Entity\RecurringOrder;

/**
 * Dispatched once a customer subscribed (confirmation e-mail).
 */
final readonly class RecurringOrderStarted
{
    public function __construct(
        public RecurringOrder $recurringOrder,
    ) {
    }
}
