<?php

declare(strict_types=1);

namespace App\Recurring\Enum;

enum RecurringEndReason: string
{
    case Customer = 'customer';
    case Producer = 'producer';
    case ContractEnd = 'contract_end';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Résilié par l\'abonné',
            self::Producer => 'Arrêté par le producteur',
            self::ContractEnd => 'Fin du contrat',
        };
    }
}
