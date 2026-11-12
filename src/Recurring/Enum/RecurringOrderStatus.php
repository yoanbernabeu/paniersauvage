<?php

declare(strict_types=1);

namespace App\Recurring\Enum;

/**
 * active: an order is generated for each delivery (except the paused ones); suspended: the producer
 * stopped the generation for now; ended: nothing more is generated, ever.
 */
enum RecurringOrderStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Ended => 'Terminé',
        };
    }

    /** Badge variant of the UI kit. */
    public function badge(): string
    {
        return match ($this) {
            self::Active => 'sage',
            self::Suspended => 'default',
            self::Ended => 'muted',
        };
    }
}
