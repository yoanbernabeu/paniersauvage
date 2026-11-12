<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

use App\Shared\Service\Quantity;

/**
 * How a product is sold. Quantities are in thousandths of this unit (App\Shared\Service\Quantity):
 * a weighed unit (the kilogram) takes fractions, the others only whole numbers.
 */
enum ProductUnit: string
{
    case Piece = 'piece';
    case Kg = 'kg';
    case Bunch = 'bunch';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'la pièce',
            self::Kg => 'le kilo',
            self::Bunch => 'la botte',
        };
    }

    /**
     * Short form used next to a price ("5,20 € / pièce").
     */
    public function short(): string
    {
        return match ($this) {
            self::Piece => 'pièce',
            self::Kg => 'kg',
            self::Bunch => 'botte',
        };
    }

    /**
     * Sold by weight: fractional quantities, price per kilogram, actual weight entered at delivery.
     */
    public function isWeighed(): bool
    {
        return self::Kg === $this;
    }

    /**
     * Can this many thousandths of the unit exist? Whole units only, except by weight.
     */
    public function accepts(int $quantity): bool
    {
        return $quantity >= 0 && ($this->isWeighed() || Quantity::isWhole($quantity));
    }
}
