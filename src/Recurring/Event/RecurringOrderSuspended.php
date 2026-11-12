<?php

declare(strict_types=1);

namespace App\Recurring\Event;

use App\Recurring\Entity\RecurringOrder;

/**
 * Dispatched once the producer suspended the subscription (e-mail to the subscriber).
 */
final readonly class RecurringOrderSuspended
{
    public function __construct(
        public RecurringOrder $recurringOrder,
    ) {
    }
}
