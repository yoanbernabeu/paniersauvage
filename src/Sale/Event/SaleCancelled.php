<?php

declare(strict_types=1);

namespace App\Sale\Event;

use App\Sale\Entity\Sale;

/**
 * Dispatched when a sale is cancelled: customers who ordered are told.
 */
final readonly class SaleCancelled
{
    public function __construct(
        public Sale $sale,
    ) {
    }
}
