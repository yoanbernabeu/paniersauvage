<?php

declare(strict_types=1);

namespace App\Accounting\Service;

/**
 * Splits a VAT-inclusive amount (TTC) into net (HT) and VAT, in whole cents.
 *
 * Rounding rule, applied everywhere in the accounting journal:
 *  - prices are VAT-inclusive, so the VAT is extracted from the TTC amount ("TVA en dedans");
 *  - the split is done once per order line: HT = TTC × 10 000 / (10 000 + rate in basis points),
 *    rounded to the nearest cent, halves rounded up; VAT = TTC − HT, so HT + VAT always equals TTC;
 *  - every total (by VAT rate, by product, by sale, for the month) is the sum of those line
 *    amounts, so all breakdowns add up to the same cent. The price of that consistency: a total
 *    HT may differ by a few cents from the HT recomputed on the total TTC.
 *
 * Integer arithmetic only: no float ever holds an amount.
 */
final class VatCalculator
{
    /**
     * Net amount (HT) included in a VAT-inclusive amount.
     */
    public function netFromGross(int $grossCents, int $vatRateBp): int
    {
        if ($vatRateBp < 0) {
            throw new \InvalidArgumentException(\sprintf('A VAT rate cannot be negative, got %d basis points.', $vatRateBp));
        }

        $divisor = 10000 + $vatRateBp;
        $net = intdiv(2 * abs($grossCents) * 10000 + $divisor, 2 * $divisor);

        return $grossCents < 0 ? -$net : $net;
    }

    /**
     * VAT included in a VAT-inclusive amount.
     */
    public function vatFromGross(int $grossCents, int $vatRateBp): int
    {
        return $grossCents - $this->netFromGross($grossCents, $vatRateBp);
    }
}
