<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Exception\BillingGatewayException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Test double: never calls Stripe. It records what the application asked for and returns
 * predictable URLs. Webhooks go through the real decoder, so signatures are really checked.
 */
#[When(env: 'test')]
#[AsAlias(BillingGateway::class, public: true)]
final class FakeBillingGateway implements BillingGateway
{
    public const string CHECKOUT_URL = 'https://checkout.stripe.test/c/pay/cs_test_fake';
    public const string PORTAL_URL = 'https://billing.stripe.test/p/session/bps_test_fake';

    /** @var list<CheckoutRequest> */
    public array $checkoutRequests = [];

    /** @var list<array{customerId: string, returnUrl: string}> */
    public array $portalRequests = [];

    /** @var list<array{subscriptionId: string, shopId: int, amountCents: int}> */
    public array $amountChanges = [];

    /** @var list<string> */
    public array $cancellations = [];

    /** Makes session creations fail, as when Stripe is unreachable. */
    public bool $unavailable = false;

    public function __construct(
        private readonly StripeWebhookDecoder $decoder,
    ) {
    }

    public function createCheckoutSession(CheckoutRequest $request): string
    {
        $this->failIfUnavailable();
        $this->checkoutRequests[] = $request;

        return self::CHECKOUT_URL;
    }

    public function createPortalSession(string $stripeCustomerId, string $returnUrl): string
    {
        $this->failIfUnavailable();
        $this->portalRequests[] = ['customerId' => $stripeCustomerId, 'returnUrl' => $returnUrl];

        return self::PORTAL_URL;
    }

    public function changeSolidarityAmount(string $stripeSubscriptionId, int $shopId, int $amountCents): void
    {
        $this->failIfUnavailable();
        $this->amountChanges[] = ['subscriptionId' => $stripeSubscriptionId, 'shopId' => $shopId, 'amountCents' => $amountCents];
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        $this->failIfUnavailable();
        $this->cancellations[] = $stripeSubscriptionId;
    }

    public function decodeWebhook(string $payload, string $signatureHeader): BillingEvent
    {
        return $this->decoder->decode($payload, $signatureHeader);
    }

    private function failIfUnavailable(): void
    {
        if ($this->unavailable) {
            throw new BillingGatewayException('The fake billing gateway was told to be unavailable.');
        }
    }
}
