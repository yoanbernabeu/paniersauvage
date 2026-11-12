<?php

declare(strict_types=1);

namespace App\Instance;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Repository\ShopSubscriptionRepository;

/**
 * SPEC §7 seen from the instance: may a shop open or create new sales?
 *
 * Standard mode: what its subscription says (`ShopSubscription::allowsNewSales()`; no subscription
 * record, no new sale). Sponsored mode (INSTANCE_PRICING=sponsored): the carrier pays for every
 * producer of the instance, so every shop may, whatever its subscription record.
 *
 * `allowsNewSales()` is the rule, `shopAllowsNewSalesSql()` its SQL translation for queries over
 * many shops (the directory): SubscriptionPolicyTest compares them in both modes.
 */
final readonly class SubscriptionPolicy
{
    public function __construct(
        private InstanceSettings $instance,
    ) {
    }

    public function allowsNewSales(?ShopSubscription $subscription, \DateTimeImmutable $now): bool
    {
        if ($this->instance->isSponsored()) {
            return true;
        }

        return $subscription?->allowsNewSales($now) ?? false;
    }

    /**
     * SQL condition on the shop row aliased $shopAlias.
     *
     * @param string $subscriptionTable table of ShopSubscription
     *
     * @return array{0: string, 1: array<string, string>} the condition and its parameters
     */
    public function shopAllowsNewSalesSql(string $shopAlias, string $subscriptionTable, \DateTimeImmutable $now): array
    {
        if ($this->instance->isSponsored()) {
            return ['TRUE', []];
        }

        [$condition, $parameters] = ShopSubscriptionRepository::allowsNewSalesSql('sub', $now);

        return [\sprintf('EXISTS (SELECT 1 FROM %s sub WHERE sub.shop_id = %s.id AND %s)', $subscriptionTable, $shopAlias, $condition), $parameters];
    }
}
