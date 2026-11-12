<?php

declare(strict_types=1);

namespace App\Ordering\Enum;

/**
 * Lifecycle of an order. There is deliberately no payment-related status (SPEC §1).
 */
enum OrderStatus: string
{
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmée',
            self::Delivered => 'Livrée',
            self::NotDelivered => 'Non livrée',
            self::Cancelled => 'Annulée',
        };
    }
}
