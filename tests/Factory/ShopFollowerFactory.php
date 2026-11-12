<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Customer\Entity\ShopFollower;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a follower who agreed to receive e-mails.
 *
 * @extends PersistentObjectFactory<ShopFollower>
 */
final class ShopFollowerFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ShopFollower::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'customer' => CustomerFactory::new(),
            'shop' => ShopFactory::new(),
            'emailConsent' => true,
            'createdAt' => new \DateTimeImmutable('-10 days'),
        ];
    }
}
