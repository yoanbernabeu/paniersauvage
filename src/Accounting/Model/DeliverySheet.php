<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Sale\Entity\Sale;

/**
 * Printable delivery sheet of a sale: orders grouped by delivery point, sorted by customer name.
 */
final readonly class DeliverySheet
{
    /**
     * @param list<DeliveryGroup> $groups in the order of the sale's deliveries; empty ones are left out
     */
    public function __construct(
        public Sale $sale,
        public int $orderCount,
        public int $totalCents,
        public array $groups,
    ) {
    }
}
