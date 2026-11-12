<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Catalog\Enum\ProductUnit;

/**
 * Total of one product to prepare for a sale. The quantity is in thousandths of the unit.
 */
final readonly class ProductionLine
{
    public function __construct(
        public string $label,
        public ProductUnit $unit,
        public int $quantity,
        public int $orderCount,
        public int $totalCents,
    ) {
    }
}
