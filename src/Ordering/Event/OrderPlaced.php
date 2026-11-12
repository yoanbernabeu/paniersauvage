<?php

declare(strict_types=1);

namespace App\Ordering\Event;

use App\Ordering\Entity\Order;
use App\Shared\Enum\Actor;

/**
 * Dispatched once an order is created: the customer receives the confirmation (with the .ics).
 */
final readonly class OrderPlaced
{
    public function __construct(
        public Order $order,
        public Actor $actor,
    ) {
    }
}
