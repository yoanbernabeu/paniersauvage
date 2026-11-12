<?php

declare(strict_types=1);

namespace App\Ordering\Event;

use App\Ordering\Entity\Order;
use App\Shared\Enum\Actor;

/**
 * Dispatched once an order is modified: the customer receives the new summary.
 */
final readonly class OrderUpdated
{
    public function __construct(
        public Order $order,
        public Actor $actor,
    ) {
    }
}
