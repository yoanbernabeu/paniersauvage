<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Exception\BillingGatewayException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;

/**
 * Stripe Billing: hosted Checkout, customer portal, signed webhooks.
 * Replaced by {@see FakeBillingGateway} in the test environment.
 */
#[WhenNot(env: 'test')]
#[AsAlias(BillingGateway::class)]
final readonly class StripeBillingGateway implements BillingGateway
{
    public function __construct(
        private StripeClient $stripe,
        private StripeWebhookDecoder $decoder,
        #[Autowire(env: 'STRIPE_PRICE_MONTHLY')]
        private string $monthlyPriceId,
        #[Autowire(env: 'STRIPE_PRICE_YEARLY')]
        private string $yearlyPriceId,
        #[Autowire(env: 'default::STRIPE_PRODUCT_SOLIDARITY')]
        private ?string $solidarityProductId = null,
    ) {
    }

    public function createCheckoutSession(CheckoutRequest $request): string
    {
        $metadata = ['shop_id' => (string) $request->shopId, 'plan' => $request->plan->value];
        if (SubscriptionPlan::Solidarity === $request->plan) {
            $metadata['amount_cents'] = (string) $request->amountCents;
        }

        $params = [
            'mode' => 'subscription',
            'locale' => 'fr',
            'line_items' => [$this->lineItem($request)],
            'client_reference_id' => (string) $request->shopId,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
        ];

        if (null !== $request->stripeCustomerId) {
            $params['customer'] = $request->stripeCustomerId;
        } else {
            $params['customer_email'] = $request->customerEmail;
        }

        try {
            $url = $this->stripe->checkout->sessions->create($params)->url;
        } catch (ApiErrorException $exception) {
            throw new BillingGatewayException('Stripe refused to create the Checkout session.', 0, $exception);
        }

        return $url ?? throw new BillingGatewayException('Stripe returned a Checkout session without URL.');
    }

    public function createPortalSession(string $stripeCustomerId, string $returnUrl): string
    {
        try {
            return $this->stripe->billingPortal->sessions->create([
                'customer' => $stripeCustomerId,
                'return_url' => $returnUrl,
                'locale' => 'fr',
            ])->url;
        } catch (ApiErrorException $exception) {
            throw new BillingGatewayException('Stripe refused to create the customer portal session.', 0, $exception);
        }
    }

    public function changeSolidarityAmount(string $stripeSubscriptionId, int $shopId, int $amountCents): void
    {
        try {
            $subscription = $this->stripe->subscriptions->retrieve($stripeSubscriptionId);
            $itemId = $subscription->items->data[0]->id ?? null;
            if (!\is_string($itemId)) {
                throw new BillingGatewayException('The Stripe subscription has no item to change.');
            }

            $this->stripe->subscriptions->update($stripeSubscriptionId, [
                'items' => [['id' => $itemId, 'price_data' => $this->solidarityPriceData($amountCents)]],
                // The new amount applies from the next invoice: no prorated charge or credit.
                'proration_behavior' => 'none',
                'metadata' => ['shop_id' => (string) $shopId, 'plan' => SubscriptionPlan::Solidarity->value, 'amount_cents' => (string) $amountCents],
            ]);
        } catch (ApiErrorException $exception) {
            throw new BillingGatewayException('Stripe refused to change the amount of the subscription.', 0, $exception);
        }
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        try {
            $this->stripe->subscriptions->cancel($stripeSubscriptionId);
        } catch (ApiErrorException $exception) {
            throw new BillingGatewayException('Stripe refused to cancel the subscription.', 0, $exception);
        }
    }

    public function decodeWebhook(string $payload, string $signatureHeader): BillingEvent
    {
        return $this->decoder->decode($payload, $signatureHeader);
    }

    /**
     * @return array{price?: string, price_data?: array{currency: string, product: string, unit_amount: int, recurring: array{interval: string}}, quantity: int}
     */
    private function lineItem(CheckoutRequest $request): array
    {
        return match ($request->plan) {
            SubscriptionPlan::Monthly => ['price' => $this->monthlyPriceId, 'quantity' => 1],
            SubscriptionPlan::Yearly => ['price' => $this->yearlyPriceId, 'quantity' => 1],
            // Pay what you can: a monthly price created on the fly for the amount chosen.
            SubscriptionPlan::Solidarity => ['price_data' => $this->solidarityPriceData($request->amountCents ?? 0), 'quantity' => 1],
        };
    }

    /**
     * @return array{currency: string, product: string, unit_amount: int, recurring: array{interval: string}}
     */
    private function solidarityPriceData(int $amountCents): array
    {
        if (null === $this->solidarityProductId || '' === $this->solidarityProductId) {
            throw new BillingGatewayException('STRIPE_PRODUCT_SOLIDARITY is not configured.');
        }

        if ($amountCents <= 0) {
            throw new BillingGatewayException('A solidarity amount billed through Stripe must be positive.');
        }

        return [
            'currency' => 'eur',
            'product' => $this->solidarityProductId,
            'unit_amount' => $amountCents,
            'recurring' => ['interval' => 'month'],
        ];
    }
}
