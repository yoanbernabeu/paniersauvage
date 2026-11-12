<?php

declare(strict_types=1);

namespace App\Legal\Enum;

/**
 * Why a visitor reports a shop (shop_report).
 */
enum ReportReason: string
{
    case NotAProducer = 'not_a_producer';
    case Misleading = 'misleading';
    case IllegalProduct = 'illegal_product';
    case OrderProblem = 'order_problem';
    case Impersonation = 'impersonation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotAProducer => 'Ce n\'est pas un producteur (revente, faux profil)',
            self::Misleading => 'Informations trompeuses (origine, labels, prix)',
            self::IllegalProduct => 'Produit interdit ou dangereux',
            self::OrderProblem => 'Problème avec une commande',
            self::Impersonation => 'Usurpation d\'identité',
            self::Other => 'Autre raison',
        };
    }
}
