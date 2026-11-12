<?php

declare(strict_types=1);

namespace App\Recurring\Model;

use App\Delivery\Entity\DeliveryPoint;

/**
 * What a subscriber wants at each delivery: pick-up point, baskets (basket mode) or quantities
 * (choice mode), home address when delivered at home.
 */
final readonly class RecurringOrderRequest
{
    /**
     * @param array<int, int> $quantities product id => quantity in thousandths (choice mode; 0 = none)
     */
    public function __construct(
        public DeliveryPoint $deliveryPoint,
        public int $shares = 1,
        public array $quantities = [],
        public ?string $deliveryAddress = null,
    ) {
    }
}
