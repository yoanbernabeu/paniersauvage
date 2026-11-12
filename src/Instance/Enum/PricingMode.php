<?php

declare(strict_types=1);

namespace App\Instance\Enum;

/**
 * Who pays the producers' subscription on this instance (INSTANCE_PRICING).
 */
enum PricingMode: string
{
    /** Each producer subscribes (free trial, then Stripe): Panier Sauvage as it is. */
    case Standard = 'standard';

    /** The carrier of the instance (a local authority) pays for every producer: no trial, no Stripe. */
    case Sponsored = 'sponsored';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Abonnement payé par chaque producteur',
            self::Sponsored => 'Abonnement offert par l\'organisme porteur',
        };
    }
}
