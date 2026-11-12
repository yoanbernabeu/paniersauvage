<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Enum\SubscriptionPlan;

final readonly class CheckoutRequest
{
    /**
     * @param int         $shopId           sent as `client_reference_id` and in the subscription metadata,
     *                                      so webhooks can find the shop again
     * @param string|null $stripeCustomerId reuse the existing Stripe customer when the shop already has one
     * @param int|null    $amountCents      solidarity plan only: the monthly amount chosen (whole euros, > 0)
     */
    public function __construct(
        public int $shopId,
        public SubscriptionPlan $plan,
        public string $customerEmail,
        public ?string $stripeCustomerId,
        public string $successUrl,
        public string $cancelUrl,
        public ?int $amountCents = null,
    ) {
    }
}
