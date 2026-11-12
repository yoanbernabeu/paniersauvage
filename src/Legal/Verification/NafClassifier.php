<?php

declare(strict_types=1);

namespace App\Legal\Verification;

/**
 * Does an activity code (NAF rev. 2) match a producer selling food? Codes outside these lists are
 * not refused: the shop is put aside for a human review.
 *
 * - Section A, divisions 01 to 03: agriculture, forestry, fishing and aquaculture.
 * - Divisions 10 and 11: food and beverage manufacturing (bakers 10.71C, cheese makers 10.51C,
 *   winemakers 11.02B, brewers 11.05Z...).
 * - A few retail codes used by craftsmen and producers who sell their own goods: food retail in
 *   specialised stores (47.21Z fruit and vegetables, 47.22Z meat, 47.23Z fish, 47.24Z bread and
 *   pastry, 47.25Z beverages, 47.29Z other food) and retail on stalls and markets (47.81Z), the
 *   usual code of market gardeners registered as traders.
 */
final class NafClassifier
{
    /** @var list<string> NAF divisions (two first digits) */
    public const array FOOD_DIVISIONS = ['01', '02', '03', '10', '11'];

    /** @var list<string> full NAF codes */
    public const array FOOD_RETAIL_CODES = ['47.21Z', '47.22Z', '47.23Z', '47.24Z', '47.25Z', '47.29Z', '47.81Z'];

    public static function isFoodOrFarming(?string $nafCode): bool
    {
        if (null === $nafCode) {
            return false;
        }

        $code = strtoupper(trim($nafCode));
        if (1 !== preg_match('/^(\d{2})\.\d{2}[A-Z]$/', $code, $matches)) {
            return false;
        }

        return \in_array($matches[1], self::FOOD_DIVISIONS, true) || \in_array($code, self::FOOD_RETAIL_CODES, true);
    }
}
