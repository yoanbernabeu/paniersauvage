<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Shared\Service\RandomCode;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Sale has no status setter (transitions only): the status is forced into the property.
 *
 * Default: an open sale, delivered in 7 days, deadline in 4 days. No delivery nor offer:
 * add them with SaleDeliveryFactory / SaleOfferFactory (['sale' => $sale]).
 *
 * @extends PersistentObjectFactory<Sale>
 */
final class SaleFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Sale::class;
    }

    public function scheduled(): static
    {
        return $this->with([
            'status' => SaleStatus::Scheduled,
            'opensAt' => new \DateTimeImmutable('+2 days 08:00'),
            'deadlineAt' => new \DateTimeImmutable('+6 days 20:00'),
            'deliveryDate' => new \DateTimeImmutable('+9 days'),
        ]);
    }

    /**
     * Deadline passed, delivery still to come.
     */
    public function closed(): static
    {
        return $this->with([
            'status' => SaleStatus::Closed,
            'opensAt' => new \DateTimeImmutable('-6 days 08:00'),
            'deadlineAt' => new \DateTimeImmutable('-1 day 20:00'),
            'deliveryDate' => new \DateTimeImmutable('+2 days'),
        ]);
    }

    /**
     * Delivered in the past.
     */
    public function done(): static
    {
        return $this->with([
            'status' => SaleStatus::Done,
            'opensAt' => new \DateTimeImmutable('-14 days 08:00'),
            'deadlineAt' => new \DateTimeImmutable('-10 days 20:00'),
            'deliveryDate' => new \DateTimeImmutable('-7 days'),
        ]);
    }

    public function cancelled(): static
    {
        return $this->with(['status' => SaleStatus::Cancelled]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'shop' => ShopFactory::new(),
            'code' => RandomCode::generate(Sale::CODE_LENGTH),
            'title' => 'Livraison du lundi',
            'status' => SaleStatus::Open,
            'opensAt' => new \DateTimeImmutable('-1 day 08:00'),
            'deadlineAt' => new \DateTimeImmutable('+4 days 20:00'),
            'deliveryDate' => new \DateTimeImmutable('+7 days'),
            'createdAt' => new \DateTimeImmutable('-2 days'),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce('status'));
    }
}
