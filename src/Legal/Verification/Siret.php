<?php

declare(strict_types=1);

namespace App\Legal\Verification;

/**
 * Format of a SIRET: 14 digits whose Luhn checksum is valid. Establishments of La Poste
 * (SIREN 356 000 000) follow another rule: the sum of their digits is a multiple of 5.
 */
final class Siret
{
    private const string LA_POSTE_SIREN = '356000000';

    public static function isValid(?string $siret): bool
    {
        if (null === $siret || 1 !== preg_match('/^\d{14}$/', $siret)) {
            return false;
        }

        if (str_starts_with($siret, self::LA_POSTE_SIREN)) {
            return 0 === array_sum(array_map(intval(...), str_split($siret))) % 5;
        }

        $sum = 0;
        foreach (str_split(strrev($siret)) as $position => $digit) {
            $value = (int) $digit * (1 === $position % 2 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return 0 === $sum % 10;
    }
}
