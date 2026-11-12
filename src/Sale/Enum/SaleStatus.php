<?php

declare(strict_types=1);

namespace App\Sale\Enum;

enum SaleStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programmée',
            self::Open => 'Ouverte',
            self::Closed => 'Commandes closes',
            self::Done => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Still ahead of us: scheduled, open or closed but not yet delivered.
     */
    public function isOngoing(): bool
    {
        return \in_array($this, [self::Scheduled, self::Open, self::Closed], true);
    }
}
