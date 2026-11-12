<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Repository\StripeEventRepository;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * No network: payloads are built here and signed with the webhook secret, exactly as Stripe does.
 */
final class StripeWebhookTest extends WebTestCase
{
    use Factories;

    private const int T0 = 1_800_000_000;
    private const int PERIOD_END = 1_802_592_000;
    private const int NEXT_PERIOD_END = 1_805_270_400;

    private KernelBrowser $client;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRejectsAnInvalidSignature(): void
    {
        $shop = $this->trialShop();
        $payload = json_encode($this->subscriptionEvent('customer.subscription.created', self::T0, ['metadata' => ['shop_id' => (string) $shop->getId()]]), \JSON_THROW_ON_ERROR);

        $attempts = [
            'wrong secret' => ['HTTP_STRIPE_SIGNATURE' => $this->sign($payload, 'whsec_not_the_right_one')],
            'missing header' => [],
            'garbage header' => ['HTTP_STRIPE_SIGNATURE' => 'v1=abc'],
            'signature of another payload' => ['HTTP_STRIPE_SIGNATURE' => $this->sign($payload.' ', $this->secret())],
            'signature too old' => ['HTTP_STRIPE_SIGNATURE' => $this->sign($payload, $this->secret(), time() - 3600)],
        ];

        foreach ($attempts as $case => $headers) {
            $this->client->request('POST', '/webhooks/stripe', server: $headers, content: $payload);

            self::assertResponseStatusCodeSame(400, $case);
            self::assertSame(['error' => 'invalid_webhook'], $this->json(), $case);
        }

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertNull($subscription->getStripeSubscriptionId());
        self::assertSame(0, self::getContainer()->get(StripeEventRepository::class)->count());
    }

    public function testRejectsASignedButMalformedPayload(): void
    {
        foreach (['not json', '[]', '{"id":"evt_1","type":"invoice.paid"}'] as $payload) {
            $this->client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $this->sign($payload, $this->secret())], content: $payload);

            self::assertResponseStatusCodeSame(400, $payload);
        }
    }

    public function testOnlyAcceptsPost(): void
    {
        $this->client->request('GET', '/webhooks/stripe');

        self::assertResponseStatusCodeSame(405);
    }

    public function testCheckoutSessionCompletedLinksTheShopToStripe(): void
    {
        $shop = $this->trialShop();

        self::assertSame('applied', $this->send($this->event('checkout.session.completed', self::T0, [
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'payment_status' => 'paid',
            'client_reference_id' => (string) $shop->getId(),
            'customer' => 'cus_A',
            'subscription' => 'sub_A',
            'metadata' => ['shop_id' => (string) $shop->getId(), 'plan' => 'yearly'],
        ])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(SubscriptionPlan::Yearly, $subscription->getPlan());
        self::assertSame('cus_A', $subscription->getStripeCustomerId());
        self::assertSame('sub_A', $subscription->getStripeSubscriptionId());
        self::assertTrue($subscription->allowsNewSales(new \DateTimeImmutable()));
    }

    public function testCheckoutSessionOfAnotherModeIsIgnored(): void
    {
        $shop = $this->trialShop();

        self::assertSame('ignored', $this->send($this->event('checkout.session.completed', self::T0, [
            'id' => 'cs_test_2',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'client_reference_id' => (string) $shop->getId(),
            'customer' => 'cus_A',
        ])));
        self::assertNull($this->subscriptionOf($shop)->getStripeCustomerId());
    }

    public function testSubscriptionCreatedSetsPlanStatusAndPeriod(): void
    {
        $shop = $this->trialShop();

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, [
            'metadata' => ['shop_id' => (string) $shop->getId()],
            'items' => ['data' => [['current_period_end' => self::PERIOD_END, 'price' => ['id' => $this->env('STRIPE_PRICE_YEARLY'), 'recurring' => ['interval' => 'month']]]]],
        ])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(SubscriptionPlan::Yearly, $subscription->getPlan(), 'The price id wins over the interval.');
        self::assertSame('cus_A', $subscription->getStripeCustomerId());
        self::assertSame('sub_A', $subscription->getStripeSubscriptionId());
        self::assertSame(self::PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());
        self::assertSame('Europe/Paris', $subscription->getCurrentPeriodEnd()->getTimezone()->getName());
    }

    public function testSubscriptionUpdatedFollowsStripe(): void
    {
        $shop = $this->subscribedShop();

        // Older payload shape: the period end is on the subscription itself; plan from the interval.
        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0, [
            'status' => 'past_due',
            'current_period_end' => self::NEXT_PERIOD_END,
            'items' => ['data' => [['price' => ['id' => 'price_unknown', 'recurring' => ['interval' => 'year']]]]],
        ])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::PastDue, $subscription->getStatus());
        self::assertSame(SubscriptionPlan::Yearly, $subscription->getPlan());
        self::assertSame(self::NEXT_PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 + 60, ['status' => 'active'])));
        self::assertSame(SubscriptionStatus::Active, $this->subscriptionOf($shop)->getStatus());
    }

    public function testSubscriptionDeletedCancels(): void
    {
        $shop = $this->subscribedShop();

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.deleted', self::T0, ['status' => 'canceled'])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Canceled, $subscription->getStatus());
        self::assertFalse($subscription->allowsNewSales(new \DateTimeImmutable()));
        self::assertSame('sub_A', $subscription->getStripeSubscriptionId());
    }

    public function testInvoicePaymentFailedMarksTheSubscriptionPastDue(): void
    {
        $shop = $this->subscribedShop();

        self::assertSame('applied', $this->send($this->invoiceEvent('invoice.payment_failed', self::T0)));

        self::assertSame(SubscriptionStatus::PastDue, $this->subscriptionOf($shop)->getStatus());
    }

    public function testInvoicePaidReactivatesAndExtendsThePeriod(): void
    {
        $shop = $this->subscribedShop(SubscriptionStatus::PastDue);

        // Newer payload shape: the subscription is under parent.subscription_details.
        self::assertSame('applied', $this->send($this->event('invoice.paid', self::T0, [
            'id' => 'in_1',
            'object' => 'invoice',
            'customer' => 'cus_A',
            'parent' => ['subscription_details' => ['subscription' => 'sub_A', 'metadata' => ['shop_id' => (string) $shop->getId()]]],
            'lines' => ['data' => [['period' => ['start' => self::PERIOD_END, 'end' => self::NEXT_PERIOD_END]]]],
        ])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(self::NEXT_PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());

        // An invoice about an earlier period never moves the period end backwards.
        self::assertSame('applied', $this->send($this->invoiceEvent('invoice.paid', self::T0 + 10, self::PERIOD_END)));
        self::assertSame(self::NEXT_PERIOD_END, $this->subscriptionOf($shop)->getCurrentPeriodEnd()?->getTimestamp());
    }

    public function testTheSameEventIsAppliedOnlyOnce(): void
    {
        $shop = $this->subscribedShop();
        $failed = $this->invoiceEvent('invoice.payment_failed', self::T0);
        $paid = $this->invoiceEvent('invoice.paid', self::T0 + 60);

        self::assertSame('applied', $this->send($failed));
        self::assertSame('duplicate', $this->send($failed));
        self::assertSame(SubscriptionStatus::PastDue, $this->subscriptionOf($shop)->getStatus());

        self::assertSame('applied', $this->send($paid));
        // Stripe delivers the first event again, much later: it must not undo the newer state.
        self::assertSame('duplicate', $this->send($failed));
        self::assertSame('duplicate', $this->send($paid));

        self::assertSame(SubscriptionStatus::Active, $this->subscriptionOf($shop)->getStatus());
        self::assertSame(2, self::getContainer()->get(StripeEventRepository::class)->count());
    }

    public function testLateEventsDoNotOverwriteNewerOnes(): void
    {
        $shop = $this->trialShop();
        $metadata = ['shop_id' => (string) $shop->getId()];

        // "updated" (past due) arrives before the older "created" (active) and the older invoice.
        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 + 100, ['status' => 'past_due', 'metadata' => $metadata])));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, ['status' => 'active', 'metadata' => $metadata])));
        self::assertSame('skipped', $this->send($this->invoiceEvent('invoice.paid', self::T0 + 50)));
        self::assertSame(SubscriptionStatus::PastDue, $this->subscriptionOf($shop)->getStatus());

        // The Checkout confirmation comes last: it links the customer but keeps the known status.
        self::assertSame('applied', $this->send($this->event('checkout.session.completed', self::T0 + 1, [
            'mode' => 'subscription', 'payment_status' => 'paid', 'client_reference_id' => (string) $shop->getId(), 'customer' => 'cus_A', 'subscription' => 'sub_A', 'metadata' => ['plan' => 'monthly'],
        ])));
        self::assertSame(SubscriptionStatus::PastDue, $this->subscriptionOf($shop)->getStatus());

        // Events of the same second are all applied.
        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 + 100, ['status' => 'active', 'metadata' => $metadata])));
        self::assertSame(SubscriptionStatus::Active, $this->subscriptionOf($shop)->getStatus());
    }

    public function testNothingRevivesADeletedSubscription(): void
    {
        $shop = $this->subscribedShop();

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.deleted', self::T0, ['status' => 'canceled'])));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0, ['status' => 'active'])));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 - 5, ['status' => 'past_due'])));
        self::assertSame('skipped', $this->send($this->invoiceEvent('invoice.paid', self::T0 + 30)));
        self::assertSame('skipped', $this->send($this->invoiceEvent('invoice.payment_failed', self::T0 + 40)));

        self::assertSame(SubscriptionStatus::Canceled, $this->subscriptionOf($shop)->getStatus());
    }

    public function testEventsAboutAReplacedSubscriptionAreDropped(): void
    {
        $shop = $this->subscribedShop(SubscriptionStatus::Canceled);
        $metadata = ['shop_id' => (string) $shop->getId()];

        // The producer subscribes again: a new Stripe subscription takes over the cancelled one.
        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, ['id' => 'sub_B', 'status' => 'active', 'metadata' => $metadata])));
        $subscription = $this->subscriptionOf($shop);
        self::assertSame('sub_B', $subscription->getStripeSubscriptionId());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());

        // Late events of the old subscription must not touch the new one.
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.deleted', self::T0 + 10, ['id' => 'sub_A', 'status' => 'canceled', 'metadata' => $metadata])));
        self::assertSame('skipped', $this->send($this->invoiceEvent('invoice.payment_failed', self::T0 + 20, null, 'sub_A')));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 + 30, ['id' => 'sub_C', 'status' => 'active', 'metadata' => $metadata])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame('sub_B', $subscription->getStripeSubscriptionId());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
    }

    public function testAFailedFirstPaymentDoesNotEndTheFreeTrial(): void
    {
        $shop = $this->trialShop();
        $metadata = ['shop_id' => (string) $shop->getId()];

        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, ['status' => 'incomplete', 'metadata' => $metadata])));
        self::assertSame('skipped', $this->send($this->event('invoice.payment_failed', self::T0 + 1, [
            'customer' => 'cus_A', 'subscription' => 'sub_A', 'subscription_details' => ['metadata' => $metadata],
        ])));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0 + 2, ['status' => 'incomplete_expired', 'metadata' => $metadata])));
        self::assertSame('skipped', $this->send($this->subscriptionEvent('customer.subscription.deleted', self::T0 + 3, ['status' => 'incomplete_expired', 'metadata' => $metadata])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertNull($subscription->getStripeSubscriptionId());
        self::assertTrue($subscription->allowsNewSales(new \DateTimeImmutable()));
    }

    public function testCreatesTheSubscriptionRowOfAShopThatHasNone(): void
    {
        $shop = ShopFactory::createOne();

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, ['metadata' => ['shop_id' => (string) $shop->getId()]])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(SubscriptionPlan::Monthly, $subscription->getPlan());
    }

    public function testFindsTheShopByStripeCustomerWhenNothingElseMatches(): void
    {
        $shop = $this->subscribedShop(SubscriptionStatus::Canceled);

        self::assertSame('applied', $this->send($this->subscriptionEvent('customer.subscription.created', self::T0, ['id' => 'sub_B', 'status' => 'active'])));

        self::assertSame('sub_B', $this->subscriptionOf($shop)->getStripeSubscriptionId());
    }

    public function testUnknownEventsAndUnknownShopsAreAcknowledged(): void
    {
        $shop = $this->subscribedShop();

        self::assertSame('ignored', $this->send($this->event('customer.created', self::T0, ['id' => 'cus_A', 'object' => 'customer'])));
        self::assertSame('ignored', $this->send($this->event('invoice.finalized', self::T0, ['customer' => 'cus_A', 'subscription' => 'sub_A'])));
        self::assertSame('ignored', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0, ['id' => 'sub_unknown', 'customer' => 'cus_unknown', 'status' => 'canceled'])));
        self::assertSame('ignored', $this->send($this->subscriptionEvent('customer.subscription.updated', self::T0, ['id' => 'sub_other', 'customer' => 'cus_other', 'metadata' => ['shop_id' => '999999999']])));
        self::assertSame('ignored', $this->send($this->event('invoice.paid', self::T0, ['customer' => 'cus_unknown'])));
        self::assertSame('ignored', $this->send($this->event('checkout.session.completed', self::T0, ['mode' => 'subscription', 'customer' => 'cus_A', 'subscription' => 'sub_Z'])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame('sub_A', $subscription->getStripeSubscriptionId());
        self::assertSame(0, self::getContainer()->get(StripeEventRepository::class)->count());
    }

    private function trialShop(): Shop
    {
        return ShopSubscriptionFactory::createOne()->getShop();
    }

    /**
     * A shop already linked to Stripe customer `cus_A` and subscription `sub_A`.
     */
    private function subscribedShop(SubscriptionStatus $status = SubscriptionStatus::Active): Shop
    {
        return ShopSubscriptionFactory::createOne([
            'status' => $status,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('@'.self::PERIOD_END)->setTimezone(new \DateTimeZone('Europe/Paris')),
            'stripeCustomerId' => 'cus_A',
            'stripeSubscriptionId' => 'sub_A',
        ])->getShop();
    }

    private function subscriptionOf(Shop $shop): ShopSubscription
    {
        $subscription = self::getContainer()->get(ShopSubscriptionRepository::class)->findOneBy(['shop' => $shop->getId()]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function event(string $type, int $created, array $object): array
    {
        return [
            'id' => \sprintf('evt_test_%d', ++$this->sequence),
            'object' => 'event',
            'api_version' => '2025-09-30.clover',
            'created' => $created,
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function subscriptionEvent(string $type, int $created, array $overrides = []): array
    {
        return $this->event($type, $created, $overrides + [
            'id' => 'sub_A',
            'object' => 'subscription',
            'customer' => 'cus_A',
            'status' => 'active',
            'metadata' => [],
            'trial_end' => null,
            'items' => ['data' => [['current_period_end' => self::PERIOD_END, 'price' => ['id' => $this->env('STRIPE_PRICE_MONTHLY'), 'recurring' => ['interval' => 'month']]]]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceEvent(string $type, int $created, ?int $periodEnd = null, string $subscriptionId = 'sub_A'): array
    {
        return $this->event($type, $created, [
            'id' => 'in_1',
            'object' => 'invoice',
            'customer' => 'cus_A',
            'subscription' => $subscriptionId,
            'lines' => ['data' => null === $periodEnd ? [] : [['period' => ['end' => $periodEnd]]]],
        ]);
    }

    /**
     * Posts a correctly signed event and returns the outcome reported by the endpoint.
     *
     * @param array<string, mixed> $event
     */
    private function send(array $event): string
    {
        $payload = json_encode($event, \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $this->sign($payload, $this->secret()), 'CONTENT_TYPE' => 'application/json'], content: $payload);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertTrue($json['received'] ?? null);
        self::assertIsString($json['outcome'] ?? null);

        return $json['outcome'];
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    private function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));
    }

    private function secret(): string
    {
        return $this->env('STRIPE_WEBHOOK_SECRET');
    }

    private function env(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        self::assertIsString($value, \sprintf('Environment variable %s is not set.', $name));

        return $value;
    }
}
