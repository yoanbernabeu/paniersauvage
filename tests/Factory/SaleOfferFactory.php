<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a new product of the sale's shop; price, VAT and limit copied from the product.
 * Pass 'maxQty' / 'reservedQty' to set the stock counters, in thousandths of the unit (3 pieces = 3000).
 *
 * @extends PersistentObjectFactory<SaleOffer>
 */
final class SaleOfferFactory extends PersistentObjectFactory
{
    use FactoryHelper;

    public static function class(): string
    {
        return SaleOffer::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'sale' => SaleFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $sale = $parameters['sale'] = self::resolve($parameters['sale'], Sale::class);
            $parameters['product'] ??= ProductFactory::new(['shop' => $sale->getShop()]);

            return $parameters;
        });
    }
}
