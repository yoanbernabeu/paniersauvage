<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Sale\Entity\SaleDelivery;

/**
 * What a producer has to prepare for a sale: production totals and orders per delivery slot.
 */
final readonly class SaleSummary
{
    /**
     * @param list<array{label: string, unit: ProductUnit, quantity: int}> $production
     * @param list<array{delivery: SaleDelivery, orders: list<Order>}>     $groups
     */
    public function __construct(
        public int $orderCount,
        public int $totalCents,
        public array $production,
        public array $groups,
    ) {
    }
}
