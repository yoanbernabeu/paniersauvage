<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Exception\InvalidWebhookException;
use App\Kernel;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verifies the `Stripe-Signature` header and turns the JSON payload into a {@see BillingEvent}.
 * Pure computation: no call to Stripe.
 *
 * Reads both payload shapes Stripe has used: before and after API version 2025-03-31, which
 * moved `current_period_end` to the subscription items and the invoice's subscription under
 * `parent.subscription_details`.
 */
final readonly class StripeWebhookDecoder
{
    /** Maximum age of a signature, in seconds (Stripe's own default). */
    public const int TOLERANCE = 300;

    public function __construct(
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        #[\SensitiveParameter]
        private string $webhookSecret,
        #[Autowire(env: 'STRIPE_PRICE_MONTHLY')]
        private string $monthlyPriceId,
        #[Autowire(env: 'STRIPE_PRICE_YEARLY')]
        private string $yearlyPriceId,
    ) {
    }

    /**
     * @throws InvalidWebhookException
     */
    public function decode(string $payload, string $signatureHeader): BillingEvent
    {
        if (!self::isUsableSecret($this->webhookSecret)) {
            // Anybody can sign with an empty or published example secret: refuse every event.
            throw new InvalidWebhookException('STRIPE_WEBHOOK_SECRET is empty or still an example value.');
        }

        if ('' === $signatureHeader) {
            throw new InvalidWebhookException('Missing Stripe-Signature header.');
        }

        try {
            WebhookSignature::verifyHeader($payload, $signatureHeader, $this->webhookSecret, self::TOLERANCE);
        } catch (SignatureVerificationException $exception) {
            throw new InvalidWebhookException('Invalid Stripe signature.', 0, $exception);
        }

        try {
            $event = json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidWebhookException('The webhook payload is not valid JSON.', 0, $exception);
        }

        $id = $this->string($event, 'id');
        $type = $this->string($event, 'type');
        $createdAt = $this->int($event, 'created');
        $object = $this->at($event, 'data', 'object');

        if (in_array(null, [$id, $type, $createdAt], true) || !\is_array($object)) {
            throw new InvalidWebhookException('The webhook payload is not a Stripe event.');
        }

        return match (BillingEventType::tryFrom($type)) {
            BillingEventType::CheckoutCompleted => $this->fromCheckoutSession($id, $type, $createdAt, $object),
            BillingEventType::SubscriptionCreated,
            BillingEventType::SubscriptionUpdated,
            BillingEventType::SubscriptionDeleted => $this->fromSubscription($id, $type, $createdAt, $object),
            BillingEventType::InvoicePaid,
            BillingEventType::InvoicePaymentFailed => $this->fromInvoice($id, $type, $createdAt, $object),
            null => new BillingEvent($id, $type, $createdAt),
        };
    }

    /**
     * @param array<mixed> $session
     */
    private function fromCheckoutSession(string $id, string $type, int $createdAt, array $session): BillingEvent
    {
        if ('subscription' !== $this->string($session, 'mode')) {
            return new BillingEvent($id, $type, $createdAt);
        }

        return new BillingEvent(
            $id,
            $type,
            $createdAt,
            shopId: $this->shopId($this->string($session, 'client_reference_id') ?? $this->string($session, 'metadata', 'shop_id')),
            customerId: $this->reference($session, 'customer'),
            subscriptionId: $this->reference($session, 'subscription'),
            plan: SubscriptionPlan::tryFrom($this->string($session, 'metadata', 'plan') ?? ''),
            settled: \in_array($this->string($session, 'payment_status'), ['paid', 'no_payment_required'], true),
            amountCents: $this->cents($this->string($session, 'metadata', 'amount_cents')),
        );
    }

    /**
     * @param array<mixed> $subscription
     */
    private function fromSubscription(string $id, string $type, int $createdAt, array $subscription): BillingEvent
    {
        $plan = $this->plan($subscription);

        return new BillingEvent(
            $id,
            $type,
            $createdAt,
            shopId: $this->shopId($this->string($subscription, 'metadata', 'shop_id')),
            customerId: $this->reference($subscription, 'customer'),
            subscriptionId: $this->string($subscription, 'id'),
            subscriptionStatus: $this->string($subscription, 'status'),
            plan: $plan,
            currentPeriodEnd: $this->date($this->int($subscription, 'current_period_end') ?? $this->int($subscription, 'items', 'data', '0', 'current_period_end')),
            trialEnd: $this->date($this->int($subscription, 'trial_end')),
            // The price really billed wins over our metadata (the amount may change in Stripe).
            amountCents: SubscriptionPlan::Solidarity === $plan
                ? $this->int($subscription, 'items', 'data', '0', 'price', 'unit_amount') ?? $this->cents($this->string($subscription, 'metadata', 'amount_cents'))
                : null,
        );
    }

    /**
     * @param array<mixed> $invoice
     */
    private function fromInvoice(string $id, string $type, int $createdAt, array $invoice): BillingEvent
    {
        return new BillingEvent(
            $id,
            $type,
            $createdAt,
            shopId: $this->shopId($this->string($invoice, 'parent', 'subscription_details', 'metadata', 'shop_id')
            ?? $this->string($invoice, 'subscription_details', 'metadata', 'shop_id')),
            customerId: $this->reference($invoice, 'customer'),
            subscriptionId: $this->reference($invoice, 'subscription') ?? $this->reference($invoice, 'parent', 'subscription_details', 'subscription'),
            currentPeriodEnd: $this->date($this->int($invoice, 'lines', 'data', '0', 'period', 'end')),
        );
    }

    /**
     * The plan comes from the price id, then from our own metadata, then from the billing interval.
     *
     * @param array<mixed> $subscription
     */
    private function plan(array $subscription): ?SubscriptionPlan
    {
        $priceId = $this->string($subscription, 'items', 'data', '0', 'price', 'id');

        if (null !== $priceId && $priceId === $this->monthlyPriceId) {
            return SubscriptionPlan::Monthly;
        }

        if (null !== $priceId && $priceId === $this->yearlyPriceId) {
            return SubscriptionPlan::Yearly;
        }

        return SubscriptionPlan::tryFrom($this->string($subscription, 'metadata', 'plan') ?? '')
            ?? match ($this->string($subscription, 'items', 'data', '0', 'price', 'recurring', 'interval')) {
                'month' => SubscriptionPlan::Monthly,
                'year' => SubscriptionPlan::Yearly,
                default => null,
            };
    }

    /**
     * A real signing secret starts with "whsec_" and is not one of the placeholders of the
     * documentation (.env.prod.example, former .env defaults).
     */
    public static function isUsableSecret(string $secret): bool
    {
        return str_starts_with($secret, 'whsec_')
            && \strlen($secret) >= 16
            && 1 !== preg_match('/change_?me|example/i', $secret);
    }

    private function at(mixed $data, string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private function string(mixed $data, string ...$path): ?string
    {
        $value = $this->at($data, ...$path);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function int(mixed $data, string ...$path): ?int
    {
        $value = $this->at($data, ...$path);

        return \is_int($value) ? $value : null;
    }

    /**
     * A related object is either its id or, when expanded, the object itself.
     */
    private function reference(mixed $data, string ...$path): ?string
    {
        return $this->string($data, ...$path) ?? $this->string($data, ...[...$path, 'id']);
    }

    private function cents(?string $value): ?int
    {
        return null !== $value && ctype_digit($value) && \strlen($value) < 9 ? (int) $value : null;
    }

    private function shopId(?string $value): ?int
    {
        return null !== $value && ctype_digit($value) && \strlen($value) < 18 ? (int) $value : null;
    }

    private function date(?int $timestamp): ?\DateTimeImmutable
    {
        return null === $timestamp ? null : new \DateTimeImmutable('@'.$timestamp)->setTimezone(new \DateTimeZone(Kernel::TIMEZONE));
    }
}
