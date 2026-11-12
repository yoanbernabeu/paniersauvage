<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Entity\StripeEvent;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Repository\StripeEventRepository;
use App\Billing\Stripe\BillingEvent;
use App\Billing\Stripe\BillingEventType;
use App\Shop\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies a verified Stripe event to the ShopSubscription it is about.
 *
 * - Idempotent: a processed event id is stored ({@see StripeEvent}) and never applied twice.
 * - Out of order: an event older than the latest one already processed for the same Stripe
 *   subscription is dropped; events about a Stripe subscription the shop has since replaced
 *   are dropped too.
 * - Tolerant: unknown types and events without a known shop are acknowledged and ignored.
 *
 * The shop is found by Stripe subscription id, then by the `shop_id` we put in the Checkout
 * session and subscription metadata, then by Stripe customer id.
 */
final readonly class StripeWebhookHandler
{
    public function __construct(
        private ShopSubscriptionRepository $subscriptions,
        private StripeEventRepository $events,
        private ShopRepository $shops,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(BillingEvent $event): WebhookOutcome
    {
        $type = $event->handledType();
        if (!$type instanceof BillingEventType) {
            return WebhookOutcome::Ignored;
        }

        if (null !== $this->events->find($event->id)) {
            return WebhookOutcome::Duplicate;
        }

        $subscription = $this->resolve($event, $type);
        if (!$subscription instanceof ShopSubscription) {
            $this->logger->warning('Stripe event {id} ({type}) matches no shop: ignored.', ['id' => $event->id, 'type' => $event->type]);

            return WebhookOutcome::Ignored;
        }

        $applied = match ($type) {
            BillingEventType::CheckoutCompleted => $this->applyCheckout($subscription, $event),
            BillingEventType::SubscriptionCreated,
            BillingEventType::SubscriptionUpdated => $this->applySnapshot($subscription, $event, $this->mapStatus($event->subscriptionStatus)),
            BillingEventType::SubscriptionDeleted => $this->applySnapshot($subscription, $event, SubscriptionStatus::Canceled),
            BillingEventType::InvoicePaymentFailed => $this->applyPaymentFailed($subscription, $event),
            BillingEventType::InvoicePaid => $this->applyInvoicePaid($subscription, $event),
        };

        if ($applied) {
            $this->entityManager->persist($subscription);
        }

        // A concurrent delivery of the same event fails here on the primary key and rolls
        // everything back; Stripe retries and gets "duplicate".
        $this->entityManager->persist(new StripeEvent($event->id, $event->type, $event->subscriptionId, $event->createdAt, $this->clock->now()));
        $this->entityManager->flush();

        return $applied ? WebhookOutcome::Applied : WebhookOutcome::Skipped;
    }

    /**
     * Checkout finished: links the shop to its Stripe customer and subscription. The status is
     * only set here when no subscription event has been processed yet (they carry the real one).
     */
    private function applyCheckout(ShopSubscription $subscription, BillingEvent $event): bool
    {
        if (null === $event->customerId && null === $event->subscriptionId) {
            return false;
        }

        if (null !== $event->customerId) {
            $subscription->setStripeCustomerId($event->customerId);
        }

        if (null === $event->subscriptionId || ($this->isAboutAnotherSubscription($subscription, $event) && $this->isLive($subscription))) {
            return true;
        }

        $alreadyDescribed = $this->events->findLatestForSubscription($event->subscriptionId) instanceof StripeEvent;
        $subscription->setStripeSubscriptionId($event->subscriptionId);

        $this->applyPlan($subscription, $event);

        if (!$alreadyDescribed && true === $event->settled) {
            $subscription->setStatus(SubscriptionStatus::Active);
        }

        return true;
    }

    /**
     * `customer.subscription.created|updated|deleted`: the event carries the whole subscription.
     */
    private function applySnapshot(ShopSubscription $subscription, BillingEvent $event, ?SubscriptionStatus $status): bool
    {
        // `incomplete` (first payment still pending) and unknown statuses change nothing.
        if (null === $event->subscriptionId || !$status instanceof SubscriptionStatus || $this->isStale($event)) {
            return false;
        }

        $isLinked = $subscription->getStripeSubscriptionId() === $event->subscriptionId;
        if (!$isLinked) {
            // Never let a dead Stripe subscription end a free trial or replace a live one.
            if (SubscriptionStatus::Canceled === $status || ($this->isAboutAnotherSubscription($subscription, $event) && $this->isLive($subscription))) {
                return false;
            }
        }

        $subscription->setStripeSubscriptionId($event->subscriptionId);
        $subscription->setStatus($status);

        if (null !== $event->customerId) {
            $subscription->setStripeCustomerId($event->customerId);
        }

        $this->applyPlan($subscription, $event);

        if ($event->currentPeriodEnd instanceof \DateTimeImmutable) {
            $subscription->setCurrentPeriodEnd($event->currentPeriodEnd);
        }

        if (SubscriptionStatus::Trialing === $status && $event->trialEnd instanceof \DateTimeImmutable) {
            $subscription->setTrialEndsAt($event->trialEnd);
        }

        return true;
    }

    private function applyPaymentFailed(ShopSubscription $subscription, BillingEvent $event): bool
    {
        // A failed first payment (subscription not linked yet) must not end a free trial.
        if (null === $event->subscriptionId
            || $subscription->getStripeSubscriptionId() !== $event->subscriptionId
            || SubscriptionStatus::Canceled === $subscription->getStatus()
            || $this->isStale($event)
        ) {
            return false;
        }

        $subscription->setStatus(SubscriptionStatus::PastDue);

        return true;
    }

    private function applyInvoicePaid(ShopSubscription $subscription, BillingEvent $event): bool
    {
        if (null === $event->subscriptionId || $this->isAboutAnotherSubscription($subscription, $event) || $this->isStale($event)) {
            return false;
        }

        $isLinked = $subscription->getStripeSubscriptionId() === $event->subscriptionId;
        if ($isLinked && SubscriptionStatus::Canceled === $subscription->getStatus()) {
            // Last invoice of a subscription that has ended: it does not revive it.
            return false;
        }

        $subscription->setStripeSubscriptionId($event->subscriptionId);
        $subscription->setStatus(SubscriptionStatus::Active);

        if (null !== $event->customerId) {
            $subscription->setStripeCustomerId($event->customerId);
        }

        $periodEnd = $subscription->getCurrentPeriodEnd();
        if ($event->currentPeriodEnd instanceof \DateTimeImmutable && (!$periodEnd instanceof \DateTimeImmutable || $event->currentPeriodEnd > $periodEnd)) {
            $subscription->setCurrentPeriodEnd($event->currentPeriodEnd);
        }

        return true;
    }

    /**
     * Plan of the event, with the monthly amount of a solidarity subscription (kept when the event
     * does not carry it; cleared for the fixed-price plans).
     */
    private function applyPlan(ShopSubscription $subscription, BillingEvent $event): void
    {
        if (!$event->plan instanceof SubscriptionPlan) {
            return;
        }

        $subscription->setPlan($event->plan);
        if (SubscriptionPlan::Solidarity !== $event->plan) {
            $subscription->setAmountCents(null);
        } elseif (null !== $event->amountCents) {
            $subscription->setAmountCents($event->amountCents);
        }
    }

    private function resolve(BillingEvent $event, BillingEventType $type): ?ShopSubscription
    {
        if (BillingEventType::CheckoutCompleted !== $type && null !== $event->subscriptionId) {
            $subscription = $this->subscriptions->findOneByStripeSubscriptionId($event->subscriptionId);
            if ($subscription instanceof ShopSubscription) {
                return $subscription;
            }
        }

        if (null !== $event->shopId) {
            $shop = $this->shops->find($event->shopId);
            if (null !== $shop) {
                // Not persisted here: the caller only keeps it when the event changes something.
                return $this->subscriptions->findOneByShop($shop) ?? new ShopSubscription($shop);
            }
        }

        if (BillingEventType::CheckoutCompleted !== $type && null !== $event->customerId) {
            return $this->subscriptions->findOneByStripeCustomerId($event->customerId);
        }

        return null;
    }

    /**
     * Is there a more recent event already processed for the same Stripe subscription?
     * (A last invoice may still follow a deletion: applyInvoicePaid() never revives it either.)
     * Events of the same second are not stale: Stripe often emits several at once.
     */
    private function isStale(BillingEvent $event): bool
    {
        if (null === $event->subscriptionId) {
            return false;
        }

        // Nothing follows the deletion of a subscription, whatever the timestamps say.
        if ($this->events->hasDeletion($event->subscriptionId)) {
            return true;
        }

        $latest = $this->events->findLatestForSubscription($event->subscriptionId);

        return $latest instanceof StripeEvent && $event->createdAt < $latest->getEventCreatedAt();
    }

    private function isAboutAnotherSubscription(ShopSubscription $subscription, BillingEvent $event): bool
    {
        return null !== $subscription->getStripeSubscriptionId()
            && null !== $event->subscriptionId
            && $subscription->getStripeSubscriptionId() !== $event->subscriptionId;
    }

    private function isLive(ShopSubscription $subscription): bool
    {
        return \in_array($subscription->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);
    }

    /**
     * Stripe subscription status → ours. Null: nothing to conclude from this status.
     */
    private function mapStatus(?string $stripeStatus): ?SubscriptionStatus
    {
        return match ($stripeStatus) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled', 'incomplete_expired', 'paused' => SubscriptionStatus::Canceled,
            default => null,
        };
    }
}
