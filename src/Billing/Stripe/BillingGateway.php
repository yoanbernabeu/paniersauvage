<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Exception\BillingGatewayException;
use App\Billing\Exception\InvalidWebhookException;

/**
 * Everything Panier Sauvage needs from the billing provider (Stripe Billing).
 * The application only talks to this interface; tests use {@see FakeBillingGateway}.
 */
interface BillingGateway
{
    /**
     * Creates a hosted Checkout session for a subscription.
     *
     * @return string the URL to redirect the producer to
     *
     * @throws BillingGatewayException when the provider cannot be reached or refuses the request
     */
    public function createCheckoutSession(CheckoutRequest $request): string;

    /**
     * Creates a customer portal session (payment method, invoices, cancellation).
     *
     * @return string the URL to redirect the producer to
     *
     * @throws BillingGatewayException when the provider cannot be reached or refuses the request
     */
    public function createPortalSession(string $stripeCustomerId, string $returnUrl): string;

    /**
     * Moves a running subscription to the solidarity plan at this monthly amount (whole euros, > 0),
     * from the next invoice on, without proration. The webhook confirms the change.
     *
     * @throws BillingGatewayException when the provider cannot be reached or refuses the request
     */
    public function changeSolidarityAmount(string $stripeSubscriptionId, int $shopId, int $amountCents): void;

    /**
     * Cancels a subscription right away (the producer moves to the free solidarity plan).
     *
     * @throws BillingGatewayException when the provider cannot be reached or refuses the request
     */
    public function cancelSubscription(string $stripeSubscriptionId): void;

    /**
     * Checks the signature of a webhook call and decodes its payload.
     *
     * @throws InvalidWebhookException when the signature is missing or wrong, or the payload is malformed
     */
    public function decodeWebhook(string $payload, string $signatureHeader): BillingEvent;
}
