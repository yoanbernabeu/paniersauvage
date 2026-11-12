<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Ordering\Entity\OrderEvent;
use App\Ordering\Enum\OrderEventType;
use App\Shared\Enum\Actor;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<OrderEvent>
 */
final class OrderEventFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return OrderEvent::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'order' => OrderFactory::new(),
            'type' => OrderEventType::Placed,
            'actor' => Actor::Customer,
            'detail' => [],
            'createdAt' => new \DateTimeImmutable('-1 hour'),
        ];
    }
}
