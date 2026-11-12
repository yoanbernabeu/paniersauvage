<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Service\SolidarityPlan;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

/**
 * Rules of the solidarity plan on the subscription itself (no database).
 */
final class SolidaritySubscriptionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10 10:00');
    }

    public function testTheFreePlanLastsTwelveMonthsWithoutStripe(): void
    {
        $subscription = ShopSubscription::startTrial(new Shop('Fournil', 'fournil'), $this->now)
            ->setStripeCustomerId('cus_A')
            ->setStripeSubscriptionId('sub_OLD')
            ->setSolidarityReminderSentAt($this->now);

        $subscription->activateFreeSolidarity($this->now);

        self::assertSame(SubscriptionPlan::Solidarity, $subscription->getPlan());
        self::assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        self::assertSame(0, $subscription->getAmountCents());
        self::assertNull($subscription->getTrialEndsAt());
        self::assertNull($subscription->getStripeSubscriptionId());
        self::assertSame('cus_A', $subscription->getStripeCustomerId(), 'Past invoices stay reachable in the portal.');
        self::assertNull($subscription->getSolidarityReminderSentAt());
        self::assertSame('2027-11-10 10:00', $subscription->getCurrentPeriodEnd()?->format('Y-m-d H:i'));
        self::assertTrue($subscription->isFreeSolidarity());
        self::assertTrue($subscription->isSolidarity());
    }

    public function testTheFreePlanAllowsNewSalesUntilItsEnd(): void
    {
        $subscription = new ShopSubscription(new Shop('Fournil', 'fournil'))->activateFreeSolidarity($this->now);
        $end = $this->now->modify('+12 months');

        self::assertTrue($subscription->allowsNewSales($this->now));
        self::assertTrue($subscription->allowsNewSales($end->modify('-1 second')));
        self::assertFalse($subscription->allowsNewSales($end));

        $subscription->setCurrentPeriodEnd(null);
        self::assertFalse($subscription->allowsNewSales($this->now), 'A free period without end is not a free pass.');
    }

    public function testAPaidSolidaritySubscriptionFollowsStripeLikeAnyOther(): void
    {
        $subscription = new ShopSubscription(new Shop('Fournil', 'fournil'))
            ->setPlan(SubscriptionPlan::Solidarity)
            ->setAmountCents(500)
            ->setStatus(SubscriptionStatus::Active)
            ->setStripeSubscriptionId('sub_A')
            ->setCurrentPeriodEnd($this->now->modify('-2 days'));

        self::assertFalse($subscription->isFreeSolidarity());
        self::assertTrue($subscription->allowsNewSales($this->now), 'Stripe renews it: the stored period end may lag behind.');
        self::assertFalse($subscription->isSolidarityRenewalDue($this->now));
    }

    public function testRenewalIsDueWithinThirtyDaysOfTheEnd(): void
    {
        $subscription = new ShopSubscription(new Shop('Fournil', 'fournil'))->activateFreeSolidarity($this->now);
        $end = $this->now->modify('+12 months');

        self::assertFalse($subscription->isSolidarityRenewalDue($end->modify('-30 days')));
        self::assertTrue($subscription->isSolidarityRenewalDue($end->modify('-30 days +1 second')));
        self::assertTrue($subscription->isSolidarityRenewalDue($end->modify('+1 day')));

        $subscription->setStatus(SubscriptionStatus::Canceled);
        self::assertFalse($subscription->isSolidarityRenewalDue($end->modify('-1 day')));
    }

    public function testTheMonthlyPlanIsNeverFreeSolidarity(): void
    {
        $subscription = new ShopSubscription(new Shop('Fournil', 'fournil'))->setStatus(SubscriptionStatus::Active)->setCurrentPeriodEnd($this->now->modify('-1 day'));

        self::assertFalse($subscription->isFreeSolidarity());
        self::assertFalse($subscription->isSolidarity());
        self::assertTrue($subscription->allowsNewSales($this->now));
        self::assertFalse($subscription->isSolidarityRenewalDue($this->now));
    }

    public function testTheDeclarationKeepsTheCriteriaAndItsDate(): void
    {
        $subscription = new ShopSubscription(new Shop('Fournil', 'fournil'))->setSolidarityReminderSentAt($this->now);

        $subscription->declareSolidarity([SolidarityCriterion::SmallVolume, SolidarityCriterion::FirstSeason, SolidarityCriterion::SmallVolume], $this->now);

        self::assertSame([SolidarityCriterion::SmallVolume, SolidarityCriterion::FirstSeason], $subscription->getSolidarityCriteria());
        self::assertSame($this->now, $subscription->getSolidarityDeclaredAt());
        self::assertNull($subscription->getSolidarityReminderSentAt());
        self::assertSame(SubscriptionPlan::Monthly, $subscription->getPlan(), 'Declaring does not change the plan by itself.');
    }

    public function testAmountsAreWholeEurosFromZeroToTen(): void
    {
        self::assertTrue(SolidarityPlan::isValidAmount(0));
        self::assertTrue(SolidarityPlan::isValidAmount(500));
        self::assertTrue(SolidarityPlan::isValidAmount(1000));
        self::assertFalse(SolidarityPlan::isValidAmount(-100));
        self::assertFalse(SolidarityPlan::isValidAmount(1100));
        self::assertFalse(SolidarityPlan::isValidAmount(1001));
        self::assertFalse(SolidarityPlan::isValidAmount(250));
    }

    public function testLabelsAreInFrench(): void
    {
        self::assertSame('Solidaire', SubscriptionPlan::Solidarity->label());
        self::assertSame('C\'est ma première saison', SolidarityCriterion::FirstSeason->label());
        self::assertStringContainsString('SIRET', SolidarityCriterion::RecentInstallation->description());
        self::assertStringContainsString('3 derniers mois', SolidarityCriterion::SmallVolume->description());
        self::assertStringContainsString('saisonnier', SolidarityCriterion::FirstSeason->description());
        self::assertStringContainsString('Épicerie solidaire', SolidarityCriterion::FoodAid->description());
        foreach (SolidarityCriterion::cases() as $criterion) {
            self::assertNotSame('', $criterion->label());
        }
    }
}
