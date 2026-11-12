<?php

declare(strict_types=1);

namespace App\Shop\Enum;

/**
 * VAT regime of a shop. Under the "franchise en base" (art. 293 B of the French tax code), the
 * producer charges no VAT: orders are frozen at 0 % and carry the legal mention.
 */
enum VatRegime: string
{
    case Normal = 'normal';
    case Franchise = 'franchise';

    /** Legal mention printed on order summaries, PDF and accounting exports under the franchise. */
    public const string FRANCHISE_MENTION = 'TVA non applicable, art. 293 B du CGI';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Régime normal : la TVA de chaque produit s\'applique',
            self::Franchise => 'Franchise en base de TVA (art. 293 B du CGI) : pas de TVA',
        };
    }
}
