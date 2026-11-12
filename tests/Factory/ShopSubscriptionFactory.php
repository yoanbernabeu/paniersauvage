<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a trial ending in 30 days.
 *
 * @extends PersistentObjectFactory<ShopSubscription>
 */
final class ShopSubscriptionFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ShopSubscription::class;
    }

    public function active(): static
    {
        return $this->with([
            'status' => SubscriptionStatus::Active,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('+20 days'),
            'stripeCustomerId' => 'cus_'.self::faker()->regexify('[A-Za-z0-9]{14}'),
            'stripeSubscriptionId' => 'sub_'.self::faker()->regexify('[A-Za-z0-9]{14}'),
        ]);
    }

    public function trialExpired(): static
    {
        return $this->with(['status' => SubscriptionStatus::Trialing, 'trialEndsAt' => new \DateTimeImmutable('-1 day')]);
    }

    public function canceled(): static
    {
        return $this->with(['status' => SubscriptionStatus::Canceled, 'trialEndsAt' => null, 'currentPeriodEnd' => new \DateTimeImmutable('-3 days')]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'shop' => ShopFactory::new(),
            'plan' => SubscriptionPlan::Monthly,
            'status' => SubscriptionStatus::Trialing,
            'trialEndsAt' => new \DateTimeImmutable('+30 days'),
        ];
    }
}
