<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShopSubscription>
 */
final class ShopSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopSubscription::class);
    }

    public function findOneByShop(Shop $shop): ?ShopSubscription
    {
        return $this->findOneBy(['shop' => $shop]);
    }

    public function findOneByStripeCustomerId(string $stripeCustomerId): ?ShopSubscription
    {
        return $this->findOneBy(['stripeCustomerId' => $stripeCustomerId]);
    }

    public function findOneByStripeSubscriptionId(string $stripeSubscriptionId): ?ShopSubscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    /**
     * Free solidarity periods still running that end before $limit and whose renewal reminder has
     * not left yet.
     *
     * @return list<ShopSubscription>
     */
    public function findFreeSolidarityToRemind(\DateTimeImmutable $now, \DateTimeImmutable $limit): array
    {
        $result = $this->createQueryBuilder('sub')
            ->andWhere('sub.plan = :solidarity')
            ->andWhere('sub.status = :active')
            ->andWhere('sub.stripeSubscriptionId IS NULL')
            ->andWhere('sub.solidarityReminderSentAt IS NULL')
            ->andWhere('sub.currentPeriodEnd > :now')
            ->andWhere('sub.currentPeriodEnd < :limit')
            ->setParameter('solidarity', SubscriptionPlan::Solidarity)
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('now', $now)
            ->setParameter('limit', $limit)
            ->orderBy('sub.id', 'ASC')
            ->getQuery()
            ->getResult();

        $subscriptions = [];
        foreach (\is_array($result) ? $result : [] as $row) {
            if ($row instanceof ShopSubscription) {
                $subscriptions[] = $row;
            }
        }

        return $subscriptions;
    }

    /**
     * SQL version of ShopSubscription::allowsNewSales(), for queries over many shops (the producer
     * search). Keep both in step: ShopSubscriptionSqlTest compares them case by case.
     *
     * @return array{0: string, 1: array<string, string>} the condition on the `shop_subscription`
     *                                                    row aliased $alias, and its parameters
     */
    public static function allowsNewSalesSql(string $alias, \DateTimeImmutable $now): array
    {
        $condition = \sprintf(
            '((%1$s.status = :sub_active AND (%1$s.plan <> :sub_solidarity OR %1$s.stripe_subscription_id IS NOT NULL OR %1$s.current_period_end > :sub_now)) OR (%1$s.status = :sub_trialing AND %1$s.trial_ends_at > :sub_now) OR (%1$s.status = :sub_past_due AND %1$s.current_period_end > :sub_past_due_since))',
            $alias,
        );

        return [$condition, [
            'sub_active' => SubscriptionStatus::Active->value,
            'sub_solidarity' => SubscriptionPlan::Solidarity->value,
            'sub_trialing' => SubscriptionStatus::Trialing->value,
            'sub_past_due' => SubscriptionStatus::PastDue->value,
            'sub_now' => $now->format('Y-m-d H:i:s'),
            // now < period end + grace  <=>  period end > now - grace
            'sub_past_due_since' => $now->modify(\sprintf('-%d days', ShopSubscription::PAST_DUE_GRACE_DAYS))->format('Y-m-d H:i:s'),
        ]];
    }
}
