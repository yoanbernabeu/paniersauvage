<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Billing\Repository\ShopSubscriptionRepository;
use App\Instance\SubscriptionPolicy;
use App\Shop\Entity\Shop;
use Psr\Clock\ClockInterface;

/**
 * SPEC §7 seen from the sales: without a subscription that allows it, no sale is created or opened.
 * A shop without any subscription record is treated as not allowed, unless the instance pays for
 * every producer (SubscriptionPolicy, sponsored mode).
 */
final readonly class SubscriptionGate
{
    public function __construct(
        private ShopSubscriptionRepository $subscriptions,
        private ClockInterface $clock,
        private SubscriptionPolicy $policy,
    ) {
    }

    public function allowsNewSales(Shop $shop): bool
    {
        return $this->policy->allowsNewSales($this->subscriptions->findOneByShop($shop), $this->clock->now());
    }
}
