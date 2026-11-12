<?php

declare(strict_types=1);

namespace App\Shop\Enum;

/**
 * What a shop sells, as the producer directory shows and filters it. A shop picks one or more.
 */
enum ShopCategory: string
{
    case Bread = 'bread';
    case Vegetables = 'vegetables';
    case Fruits = 'fruits';
    case Dairy = 'dairy';
    case Meat = 'meat';
    case EggsPoultry = 'eggs_poultry';
    case Honey = 'honey';
    case Drinks = 'drinks';
    case Grocery = 'grocery';
    case Plants = 'plants';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bread => 'Pain et viennoiseries',
            self::Vegetables => 'Légumes',
            self::Fruits => 'Fruits',
            self::Dairy => 'Fromages et laitages',
            self::Meat => 'Viandes et charcuteries',
            self::EggsPoultry => 'Œufs et volailles',
            self::Honey => 'Miel et produits de la ruche',
            self::Drinks => 'Boissons',
            self::Grocery => 'Épicerie',
            self::Plants => 'Plantes et fleurs',
            self::Other => 'Autre',
        };
    }
}
