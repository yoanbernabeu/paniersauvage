<?php

declare(strict_types=1);

namespace App\Accounting\Enum;

/**
 * Account debited by the sales entries. Panier Sauvage records no payment (SPEC §1): either a
 * generic customer account that the accountant letters against the receipts, or the cash account
 * for producers who are paid on the spot.
 */
enum AccountingCounterpart: string
{
    case Customer = 'customer';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Compte client générique (411), à lettrer par ton comptable',
            self::Cash => 'Compte de caisse (530), si tes clients te paient au retrait',
        };
    }
}
