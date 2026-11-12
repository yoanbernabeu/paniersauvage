<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a new delivery point of the sale's shop, 18:00–19:00 on the delivery date.
 *
 * @extends PersistentObjectFactory<SaleDelivery>
 */
final class SaleDeliveryFactory extends PersistentObjectFactory
{
    use FactoryHelper;

    public static function class(): string
    {
        return SaleDelivery::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'sale' => SaleFactory::new(),
            'position' => 0,
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $sale = $parameters['sale'] = self::resolve($parameters['sale'], Sale::class);
            $parameters['deliveryPoint'] ??= DeliveryPointFactory::new(['shop' => $sale->getShop()]);
            $parameters['startsAt'] ??= $sale->getDeliveryDate()->setTime(18, 0);
            $parameters['endsAt'] ??= $sale->getDeliveryDate()->setTime(19, 0);

            return $parameters;
        });
    }
}
