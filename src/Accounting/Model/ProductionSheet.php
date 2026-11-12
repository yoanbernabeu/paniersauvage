<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Sale\Entity\Sale;

/**
 * What the producer has to make for a sale: one total per product, cancelled orders left out.
 */
final readonly class ProductionSheet
{
    /**
     * @param list<ProductionLine> $lines sorted by label
     */
    public function __construct(
        public Sale $sale,
        public int $orderCount,
        public int $itemCount,
        public int $totalCents,
        public array $lines,
    ) {
    }
}
