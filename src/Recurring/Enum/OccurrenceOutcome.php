<?php

declare(strict_types=1);

namespace App\Recurring\Enum;

/**
 * What the generation did for one subscription and one sale (exactly one row each, unique in the database).
 */
enum OccurrenceOutcome: string
{
    /** Order created with everything wanted. */
    case Placed = 'placed';
    /** Order created, but some products were short or not offered in this sale. */
    case Partial = 'partial';
    /** The subscriber paused this delivery: no order (or their generated order was cancelled). */
    case Paused = 'paused';
    /** Nothing wanted was left: no order. */
    case OutOfStock = 'out_of_stock';
    /** The sale does not serve the subscriber's pick-up point: no order. */
    case Unavailable = 'unavailable';
    /** The subscriber had already ordered in this sale: their order is kept as is. */
    case AlreadyOrdered = 'already_ordered';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Commande créée',
            self::Partial => 'Commande incomplète',
            self::Paused => 'En pause',
            self::OutOfStock => 'Rupture : pas de commande',
            self::Unavailable => 'Point non desservi : pas de commande',
            self::AlreadyOrdered => 'Déjà commandé',
        };
    }

    /** Something wanted is missing: the subscriber and the producer are told. */
    public function isShortage(): bool
    {
        return \in_array($this, [self::Partial, self::OutOfStock, self::Unavailable], true);
    }

    /** Badge variant of the UI kit. */
    public function badge(): string
    {
        return match ($this) {
            self::Placed, self::AlreadyOrdered => 'sage',
            self::Paused => 'muted',
            self::Partial => 'default',
            self::OutOfStock, self::Unavailable => 'destructive',
        };
    }
}
