<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Essai gratuit',
            self::Active => 'Actif',
            self::PastDue => 'Règlement en attente',
            self::Canceled => 'Résilié',
        };
    }
}
