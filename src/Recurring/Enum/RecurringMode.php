<?php

declare(strict_types=1);

namespace App\Recurring\Enum;

/**
 * How a subscriber chooses what they receive: a basket composed by the producer (the subscriber
 * picks how many), or products picked one by one, up to the quantity the producer proposes.
 */
enum RecurringMode: string
{
    case Basket = 'basket';
    case Choice = 'choice';

    public function label(): string
    {
        return match ($this) {
            self::Basket => 'Panier composé',
            self::Choice => 'Produits au choix',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Basket => 'Tu composes le panier ; chaque abonné choisit combien de paniers il veut.',
            self::Choice => 'Chaque abonné choisit ses produits, jusqu\'à la quantité que tu proposes.',
        };
    }
}
