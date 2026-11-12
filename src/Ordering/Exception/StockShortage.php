<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

use App\Catalog\Enum\ProductUnit;
use App\Shared\Service\Quantity;

/**
 * One product that could not be reserved: how much more was asked, how much is left (thousandths
 * of the unit), and the selling step and minimum of the product, to suggest what can still be ordered.
 */
final readonly class StockShortage
{
    public function __construct(
        public int $offerId,
        public string $label,
        public int $requested,
        public int $remaining,
        public ProductUnit $unit = ProductUnit::Piece,
        public int $step = Quantity::UNIT,
        public int $minimum = Quantity::UNIT,
    ) {
    }

    /**
     * Largest quantity a customer can still order out of `$available`: a multiple of the step, at
     * least the minimum, or 0.
     */
    public function orderable(int $available): int
    {
        $quantity = $available - $available % $this->step;

        return $quantity >= $this->minimum ? $quantity : 0;
    }
}
