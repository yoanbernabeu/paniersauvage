<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Billing\Repository\StripeEventRepository;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopSubscriptionFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Stripe webhooks of the solidarity plan (price created on the fly, amount in cents). No network:
 * payloads are signed here with the webhook secret, as Stripe does.
 */
final class SolidarityWebhookTest extends WebTestCase
{
    use Factories;

    private const int T0 = 1_800_000_000;
    private const int PERIOD_END = 1_802_592_000;

    private KernelBrowser $client;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testCheckoutOfTheSolidarityPlanStoresTheAmount(): void
    {
        $shop = ShopSubscriptionFactory::createOne()->getShop();

        self::assertSame('applied', $this->send($this->event('checkout.session.completed', self::T0, [
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'payment_status' => 'paid',
            'client_reference_id' => (string) $shop->getId(),
            'customer' => 'cus_A',
            'subscription' => 'sub_S',
            'metadata' => ['shop_id' => (string) $shop->getId(), 'plan' => 'solidarity', 'amount_cents' => '500'],
        ])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionPlan::Solidarity, $subscription->getPlan());
        self::assertSame(500, $subscription->getAmountCents());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame('sub_S', $subscription->getStripeSubscriptionId());
        self::assertFalse($subscription->isFreeSolidarity());
    }

    public function testTheSubscriptionEventCarriesTheAmountReallyBilled(): void
    {
        $shop = $this->freeSolidarityShop();

        // The price billed (unit_amount) wins over the metadata, which may be stale.
        self::assertSame('applied', $this->send($this->solidarityEvent('customer.subscription.created', self::T0, $shop, 800, ['amount_cents' => '500'])));

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionPlan::Solidarity, $subscription->getPlan());
        self::assertSame(800, $subscription->getAmountCents());
        self::assertSame('sub_S', $subscription->getStripeSubscriptionId());
        self::assertSame(self::PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());
        self::assertTrue($subscription->allowsNewSales(new \DateTimeImmutable('@'.(self::PERIOD_END + 86400))), 'Paid through Stripe: no longer bound to the free period.');
    }

    public function testTheMetadataAmountIsUsedWhenThePriceHasNone(): void
    {
        $shop = $this->freeSolidarityShop();
        $event = $this->solidarityEvent('customer.subscription.created', self::T0, $shop, null, ['amount_cents' => '300']);

        self::assertSame('applied', $this->send($event));
        self::assertSame(300, $this->subscriptionOf($shop)->getAmountCents());
    }

    public function testAnEventWithoutAmountKeepsTheKnownOne(): void
    {
        $shop = $this->freeSolidarityShop();
        $this->send($this->solidarityEvent('customer.subscription.created', self::T0, $shop, 600));
        $this->send($this->solidarityEvent('customer.subscription.updated', self::T0 + 10, $shop, null, ['amount_cents' => 'abc']));
        $this->send($this->solidarityEvent('customer.subscription.updated', self::T0 + 20, $shop, null, ['amount_cents' => '123456789']));

        self::assertSame(600, $this->subscriptionOf($shop)->getAmountCents());

        $this->send($this->solidarityEvent('customer.subscription.updated', self::T0 + 30, $shop, null, ['amount_cents' => '12345678']));
        self::assertSame(12345678, $this->subscriptionOf($shop)->getAmountCents(), 'Eight digits are still read.');
    }

    public function testAChangeOfAmountIsAppliedOnceAndOnlyOnce(): void
    {
        $shop = $this->freeSolidarityShop();
        $this->send($this->solidarityEvent('customer.subscription.created', self::T0, $shop, 500));

        $update = $this->solidarityEvent('customer.subscription.updated', self::T0 + 60, $shop, 900);
        self::assertSame('applied', $this->send($update));
        self::assertSame('duplicate', $this->send($update));
        self::assertSame(900, $this->subscriptionOf($shop)->getAmountCents());

        // A late event about the old amount does not undo the change.
        self::assertSame('skipped', $this->send($this->solidarityEvent('customer.subscription.updated', self::T0 + 30, $shop, 500)));
        self::assertSame(900, $this->subscriptionOf($shop)->getAmountCents());
        self::assertSame(3, self::getContainer()->get(StripeEventRepository::class)->count());
    }

    public function testMovingBackToTheMonthlyPriceClearsTheAmount(): void
    {
        $shop = $this->freeSolidarityShop();
        $this->send($this->solidarityEvent('customer.subscription.created', self::T0, $shop, 500));

        $monthly = $this->solidarityEvent('customer.subscription.updated', self::T0 + 60, $shop, 1000, ['plan' => 'monthly']);
        $this->send($monthly);

        $subscription = $this->subscriptionOf($shop);
        self::assertSame(SubscriptionPlan::Monthly, $subscription->getPlan());
        self::assertNull($subscription->getAmountCents());
    }

    public function testTheDeletionOfAnOldPaidSubscriptionDoesNotEndTheFreePlan(): void
    {
        $shop = $this->freeSolidarityShop();

        self::assertSame('skipped', $this->send($this->solidarityEvent('customer.subscription.deleted', self::T0, $shop, 500, [], 'canceled', 'sub_OLD')));

        $subscription = $this->subscriptionOf($shop);
        self::assertTrue($subscription->isFreeSolidarity());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
    }

    private function freeSolidarityShop(): Shop
    {
        return ShopSubscriptionFactory::createOne([
            'plan' => SubscriptionPlan::Solidarity,
            'status' => SubscriptionStatus::Active,
            'amountCents' => 0,
            'trialEndsAt' => null,
            'currentPeriodEnd' => new \DateTimeImmutable('+6 months'),
            'stripeCustomerId' => 'cus_A',
        ])->getShop();
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, mixed>
     */
    private function solidarityEvent(string $type, int $created, Shop $shop, ?int $unitAmount, array $metadata = [], string $status = 'active', string $id = 'sub_S'): array
    {
        $price = ['id' => 'price_adhoc_'.($unitAmount ?? 'none'), 'recurring' => ['interval' => 'month']];
        if (null !== $unitAmount) {
            $price['unit_amount'] = $unitAmount;
        }

        return $this->event($type, $created, [
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_A',
            'status' => $status,
            'metadata' => $metadata + ['shop_id' => (string) $shop->getId(), 'plan' => 'solidarity'],
            'trial_end' => null,
            'items' => ['data' => [['current_period_end' => self::PERIOD_END, 'price' => $price]]],
        ]);
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function event(string $type, int $created, array $object): array
    {
        return [
            'id' => \sprintf('evt_solidarity_%d', ++$this->sequence),
            'object' => 'event',
            'api_version' => '2025-09-30.clover',
            'created' => $created,
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function send(array $event): string
    {
        $payload = json_encode($event, \JSON_THROW_ON_ERROR);
        $secret = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;
        self::assertIsString($secret);
        $timestamp = time();
        $signature = \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));

        $this->client->request('POST', '/webhooks/stripe', server: ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseIsSuccessful();

        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsString($json['outcome'] ?? null);

        return $json['outcome'];
    }

    private function subscriptionOf(Shop $shop): ShopSubscription
    {
        $subscription = self::getContainer()->get(ShopSubscriptionRepository::class)->findOneBy(['shop' => $shop->getId()]);
        self::assertNotNull($subscription);

        return $subscription;
    }
}
