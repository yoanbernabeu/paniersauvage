<?php

declare(strict_types=1);

namespace App\Recurring\Event;

use App\Recurring\Entity\RecurringOrder;

/**
 * Dispatched once the subscription ended: end of contract, stopped by the producer or cancelled by the subscriber (e-mail, except when the subscriber cancelled it themselves).
 */
final readonly class RecurringOrderEnded
{
    public function __construct(
        public RecurringOrder $recurringOrder,
    ) {
    }
}
