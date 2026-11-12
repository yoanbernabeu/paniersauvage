<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Shared\Service\Quantity;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: quantity 1 (1000 thousandths, App\Shared\Service\Quantity) of a new offer of the order's sale. Price, VAT and label are copied
 * from the offer, and the order total is recomputed.
 *
 * @extends PersistentObjectFactory<OrderLine>
 */
final class OrderLineFactory extends PersistentObjectFactory
{
    use FactoryHelper;

    public static function class(): string
    {
        return OrderLine::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'order' => OrderFactory::new(),
            'quantity' => Quantity::UNIT,
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $order = $parameters['order'] = self::resolve($parameters['order'], Order::class);
            $parameters['saleOffer'] ??= SaleOfferFactory::new(['sale' => $order->getSale()]);

            return $parameters;
        });
    }
}
