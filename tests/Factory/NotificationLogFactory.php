<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<NotificationLog>
 */
final class NotificationLogFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return NotificationLog::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'type' => 'order_placed',
            'customer' => CustomerFactory::new(),
            'channel' => NotificationChannel::Email,
            'status' => NotificationStatus::Sent,
            'relatedType' => 'order',
            'relatedId' => 1,
            'sentAt' => new \DateTimeImmutable('-1 hour'),
        ];
    }
}
