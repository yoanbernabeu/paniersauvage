<?php

declare(strict_types=1);

namespace App\Recurring\Enum;

/**
 * Open-ended subscription (stopped by either side, after a notice), or season contract (commitment
 * from its first to its last delivery, like an AMAP contract).
 */
enum RecurringTerm: string
{
    case OpenEnded = 'open_ended';
    case Season = 'season';

    public function label(): string
    {
        return match ($this) {
            self::OpenEnded => 'Sans engagement',
            self::Season => 'Contrat de saison',
        };
    }
}
