<?php

declare(strict_types=1);

namespace App\Catalog\Enum;

enum ProductKind: string
{
    /** Offered by default in every sale. */
    case Permanent = 'permanent';

    /** "Du moment": offered when the delivery date falls within its availability period. */
    case Seasonal = 'seasonal';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Gamme permanente',
            self::Seasonal => 'Du moment',
        };
    }
}
