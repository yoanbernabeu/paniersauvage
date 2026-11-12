<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

/**
 * How full the limited offers of a product were: quantities in thousandths of the unit.
 */
final readonly class ProductFill
{
    public function __construct(
        public string $name,
        public int $offers,
        public Rate $fill,
        /** Offers sold out (everything reserved). */
        public int $soldOut,
    ) {
    }
}
