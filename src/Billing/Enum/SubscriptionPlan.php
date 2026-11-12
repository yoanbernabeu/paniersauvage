<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum SubscriptionPlan: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** Pay what you can, from 0 to 10 € a month, on a declaration on honour (App\Billing\Service\SolidarityPlan). */
    case Solidarity = 'solidarity';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensuel',
            self::Yearly => 'Annuel',
            self::Solidarity => 'Solidaire',
        };
    }
}
