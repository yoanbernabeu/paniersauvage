<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Enum\SubscriptionPlan;

/**
 * A verified webhook event, reduced to what the application uses. Fields the event does not
 * carry are null.
 */
final readonly class BillingEvent
{
    /**
     * @param string      $id                 provider event id (`evt_…`), used for idempotence
     * @param string      $type               raw event type; see {@see BillingEventType} for the handled ones
     * @param int         $createdAt          Unix timestamp of the event, used to drop late events
     * @param int|null    $shopId             from `client_reference_id` or the `shop_id` metadata
     * @param string|null $subscriptionStatus raw Stripe subscription status (`active`, `past_due`…)
     * @param bool|null   $settled            Checkout only: the first invoice is settled (or nothing was due)
     * @param int|null    $amountCents        solidarity plan: monthly amount of the subscription, in cents
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $createdAt,
        public ?int $shopId = null,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        public ?string $subscriptionStatus = null,
        public ?SubscriptionPlan $plan = null,
        public ?\DateTimeImmutable $currentPeriodEnd = null,
        public ?\DateTimeImmutable $trialEnd = null,
        public ?bool $settled = null,
        public ?int $amountCents = null,
    ) {
    }

    public function handledType(): ?BillingEventType
    {
        return BillingEventType::tryFrom($this->type);
    }
}
