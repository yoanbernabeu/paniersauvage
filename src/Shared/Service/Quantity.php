<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Quantities are whole numbers of thousandths of a unit, everywhere (order lines, stock counters,
 * quantity limits): 1 loaf = 1000, 1.5 kg = 1500, 250 g = 250. Integer arithmetic only, like
 * amounts in cents: no float ever holds a quantity.
 */
final class Quantity
{
    /** One whole unit (a piece, a bunch, a kilogram). */
    public const int UNIT = 1000;

    /** Thousandths: at most three decimals ("1,125 kg"). */
    private const int DECIMALS = 3;

    private function __construct()
    {
    }

    /**
     * 3 (pieces) → 3000.
     */
    public static function units(int $units): int
    {
        return $units * self::UNIT;
    }

    public static function isWhole(int $quantity): bool
    {
        return 0 === $quantity % self::UNIT;
    }

    /**
     * Price of a quantity at a unit price (price per piece, per kilogram…), in cents.
     *
     * Rounding rule: to the nearest cent, halves rounded away from zero (1,5 kg × 2,99 € = 4,485 €
     * → 4,49 €). Whole quantities are exact (3 × 2,99 € = 8,97 €).
     */
    public static function priceCents(int $quantity, int $unitPriceCents): int
    {
        $thousandths = $quantity * $unitPriceCents;
        $cents = intdiv(2 * abs($thousandths) + self::UNIT, 2 * self::UNIT);

        return $thousandths < 0 ? -$cents : $cents;
    }

    /**
     * What someone typed ("1,5", "1.5", "2", " 0,250 ") → 1500, 2000, 250. Null when it is not a
     * non-negative number with at most three decimals. Spaces (thousands separators) are ignored.
     */
    public static function parse(string $value): ?int
    {
        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $value);

        if (1 !== preg_match('/^(\d{1,6})(?:\.(\d{0,3}))?$/D', $value, $matches)) {
            return null;
        }

        // "1" and "5" → "1" . "500" → 1500: digits only, no arithmetic on a decimal.
        return (int) ($matches[1].str_pad($matches[2] ?? '', self::DECIMALS, '0'));
    }

    /**
     * 1500 → "1,5", 250 → "0,25", 2000 → "2" (French decimal comma, no trailing zero).
     */
    public static function decimal(int $quantity, string $separator = ','): string
    {
        $sign = $quantity < 0 ? '-' : '';
        $whole = intdiv(abs($quantity), self::UNIT);
        $fraction = rtrim(\sprintf('%03d', abs($quantity) % self::UNIT), '0');

        return $sign.$whole.('' === $fraction ? '' : $separator.$fraction);
    }

    /**
     * 1500 → "1.5": the value of an `<input type="number">`.
     */
    public static function inputValue(int $quantity): string
    {
        return self::decimal($quantity, '.');
    }
}
