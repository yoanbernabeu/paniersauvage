<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Shared\Service\RandomCode;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a confirmed order without lines, on the first delivery of the sale (created if the
 * sale has none). Add lines with OrderLineFactory (['order' => $order]).
 *
 * Order has no status setter (transitions only): the status is forced into the property.
 *
 * Factories write rows directly: they do not reserve stock. Go through ReservationService
 * (or pass 'reservedQty' to SaleOfferFactory) when a test depends on the counters.
 *
 * @extends PersistentObjectFactory<Order>
 */
final class OrderFactory extends PersistentObjectFactory
{
    use FactoryHelper;

    public static function class(): string
    {
        return Order::class;
    }

    public function cancelled(): static
    {
        return $this->with(['status' => OrderStatus::Cancelled]);
    }

    public function delivered(): static
    {
        return $this->with(['status' => OrderStatus::Delivered]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'sale' => SaleFactory::new(),
            'customer' => CustomerFactory::new(),
            'reference' => \sprintf('PS-%s-%s', RandomCode::generate(4), RandomCode::generate(4)),
            'status' => OrderStatus::Confirmed,
            'createdAt' => new \DateTimeImmutable('-1 hour'),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce('status'))->beforeInstantiate(static function (array $parameters): array {
            $sale = $parameters['sale'] = self::resolve($parameters['sale'], Sale::class);
            $parameters['saleDelivery'] ??= $sale->getDeliveries()->first() ?: SaleDeliveryFactory::new(['sale' => $sale]);

            return $parameters;
        });
    }
}
