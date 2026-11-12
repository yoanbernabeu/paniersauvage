<?php

declare(strict_types=1);

namespace App\Sale\Event;

use App\Sale\Entity\Sale;

/**
 * Dispatched when a sale switches to `closed`: the producer receives the summary.
 */
final readonly class SaleClosed
{
    public function __construct(
        public Sale $sale,
    ) {
    }
}
