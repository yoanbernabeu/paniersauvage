<?php

declare(strict_types=1);

namespace App\Shared\Enum;

enum Actor: string
{
    case Customer = 'customer';
    case Producer = 'producer';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Client',
            self::Producer => 'Producteur',
            self::System => 'Automatique',
        };
    }
}
