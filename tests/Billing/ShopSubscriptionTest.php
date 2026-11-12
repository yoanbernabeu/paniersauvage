<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionStatus;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

final class ShopSubscriptionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-09 10:00');
    }

    private function trial(): ShopSubscription
    {
        return ShopSubscription::startTrial(new Shop('Fournil', 'fournil'), $this->now);
    }

    public function testTrialLastsThirtyDays(): void
    {
        $subscription = $this->trial();

        self::assertSame(SubscriptionStatus::Trialing, $subscription->getStatus());
        self::assertSame('2026-12-09 10:00', $subscription->getTrialEndsAt()?->format('Y-m-d H:i'));
        self::assertSame(30, $subscription->trialDaysLeft($this->now));
        self::assertSame(1, $subscription->trialDaysLeft($this->now->modify('+29 days +1 hour')));
        self::assertSame(0, $subscription->trialDaysLeft($this->now->modify('+30 days')));
    }

    public function testTrialAllowsNewSalesUntilItExpires(): void
    {
        $subscription = $this->trial();

        self::assertTrue($subscription->allowsNewSales($this->now));
        self::assertTrue($subscription->allowsNewSales($this->now->modify('+29 days')));
        self::assertFalse($subscription->isTrialExpired($this->now->modify('+29 days')));
        self::assertFalse($subscription->allowsNewSales($this->now->modify('+30 days')));
        self::assertTrue($subscription->isTrialExpired($this->now->modify('+30 days')));
    }

    public function testActiveSubscriptionAllowsNewSales(): void
    {
        $subscription = $this->trial()->setStatus(SubscriptionStatus::Active)->setCurrentPeriodEnd($this->now->modify('+1 month'));

        self::assertTrue($subscription->allowsNewSales($this->now));
        self::assertTrue($subscription->allowsNewSales($this->now->modify('+2 months')), 'Stripe is the source of truth for an active status');
        self::assertFalse($subscription->isTrialExpired($this->now->modify('+2 months')));
    }

    public function testPastDueKeepsWorkingDuringTheGracePeriodOnly(): void
    {
        $periodEnd = $this->now;
        $subscription = $this->trial()->setStatus(SubscriptionStatus::PastDue)->setCurrentPeriodEnd($periodEnd);

        self::assertTrue($subscription->allowsNewSales($periodEnd->modify('+6 days')));
        self::assertFalse($subscription->allowsNewSales($periodEnd->modify('+7 days')));
        self::assertFalse($subscription->setCurrentPeriodEnd(null)->allowsNewSales($this->now));
    }

    public function testCanceledSubscriptionBlocksNewSales(): void
    {
        $subscription = $this->trial()->setStatus(SubscriptionStatus::Canceled)->setCurrentPeriodEnd($this->now->modify('+10 days'));

        self::assertFalse($subscription->allowsNewSales($this->now));
    }
}
