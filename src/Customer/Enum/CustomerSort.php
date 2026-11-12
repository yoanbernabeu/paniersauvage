<?php

declare(strict_types=1);

namespace App\Customer\Enum;

/**
 * Orders of the "Mes clients" list (`?tri=`): by name, or the biggest figure first.
 */
enum CustomerSort: string
{
    case Name = 'nom';
    case LastOrder = 'derniere-commande';
    case Orders = 'commandes';
    case Delivered = 'total';
    case Absences = 'absences';
    case Followed = 'abonnement';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Nom',
            self::LastOrder => 'Dernière commande',
            self::Orders => 'Nombre de commandes',
            self::Delivered => 'Total retiré',
            self::Absences => 'Absences',
            self::Followed => 'Abonnement le plus récent',
        };
    }

    /** Tie-breaker of every order: by name, then e-mail, then id. */
    public const string BY_NAME = 'lower(coalesce(last_name, \'\')), lower(coalesce(first_name, \'\')), lower(coalesce(email, \'\')), id';

    /**
     * First key of the ORDER BY clause over the columns of ShopCustomers (a fixed string, never
     * user input); BY_NAME always follows.
     */
    public function orderBy(): string
    {
        return match ($this) {
            self::Name => self::BY_NAME,
            self::LastOrder => 'last_order_date DESC NULLS LAST',
            self::Orders => 'order_count DESC',
            self::Delivered => 'delivered_cents DESC',
            self::Absences => 'absences DESC',
            self::Followed => 'followed_at DESC NULLS LAST',
        };
    }
}
