<?php

declare(strict_types=1);

namespace App\Shop\Enum;

/**
 * Role of a producer account in its shop. The owner created the shop: only the owner manages the
 * subscription and the team. A member runs the shop day to day (sales, products, orders, customers).
 */
enum ShopRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Member => 'Membre',
        };
    }
}
