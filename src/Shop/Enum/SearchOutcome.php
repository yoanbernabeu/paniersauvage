<?php

declare(strict_types=1);

namespace App\Shop\Enum;

/**
 * How a search of the producer directory ended, as counted by SearchStatRepository.
 */
enum SearchOutcome: string
{
    case Results = 'results';
    case Empty = 'empty';
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Results => 'Avec résultats',
            self::Empty => 'Sans résultat',
            self::NotFound => 'Lieu introuvable',
        };
    }
}
