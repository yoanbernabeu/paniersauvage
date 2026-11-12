<?php

declare(strict_types=1);

namespace App\Sale\Event;

use App\Sale\Entity\Sale;

/**
 * Dispatched when a sale switches to `open`: followers who consented get notified.
 */
final readonly class SaleOpened
{
    public function __construct(
        public Sale $sale,
    ) {
    }
}
