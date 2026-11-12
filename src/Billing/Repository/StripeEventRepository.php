<?php

declare(strict_types=1);

namespace App\Billing\Repository;

use App\Billing\Entity\StripeEvent;
use App\Billing\Stripe\BillingEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeEvent>
 */
final class StripeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeEvent::class);
    }

    /**
     * The most recent event (by Stripe's own clock) already processed for a subscription.
     */
    public function findLatestForSubscription(string $stripeSubscriptionId): ?StripeEvent
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId], ['eventCreatedAt' => 'DESC']);
    }

    public function hasDeletion(string $stripeSubscriptionId): bool
    {
        return $this->count(['stripeSubscriptionId' => $stripeSubscriptionId, 'type' => BillingEventType::SubscriptionDeleted->value]) > 0;
    }
}
