<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Sale\Entity\SaleSchedule;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: delivery on Monday 18:00–19:00, deadline on Friday 20:00, opening a week before.
 *
 * @extends PersistentObjectFactory<SaleSchedule>
 */
final class SaleScheduleFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return SaleSchedule::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'shop' => ShopFactory::new(),
            'name' => 'Livraison du lundi',
            'active' => true,
            'weekday' => 1,
            'deliveryStartTime' => new \DateTimeImmutable('1970-01-01 18:00'),
            'deliveryEndTime' => new \DateTimeImmutable('1970-01-01 19:00'),
            'deadlineDaysBefore' => 3,
            'deadlineTime' => new \DateTimeImmutable('1970-01-01 20:00'),
            'opensDaysBefore' => 7,
        ];
    }
}
