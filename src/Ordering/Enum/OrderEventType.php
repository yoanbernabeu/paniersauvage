<?php

declare(strict_types=1);

namespace App\Ordering\Enum;

/**
 * Entries of an order's history.
 */
enum OrderEventType: string
{
    case Placed = 'placed';
    case Updated = 'updated';
    case Cancelled = 'cancelled';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';
    case Reconfirmed = 'reconfirmed';
    case Weighed = 'weighed';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Commande passée',
            self::Updated => 'Commande modifiée',
            self::Cancelled => 'Commande annulée',
            self::Delivered => 'Marquée livrée',
            self::NotDelivered => 'Marquée non livrée',
            self::Reconfirmed => 'Remise en attente de livraison',
            self::Weighed => 'Poids réel saisi',
        };
    }
}
