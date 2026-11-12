<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Tests\Factory\ShopSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The producer search filters shops in SQL: its condition must say exactly what
 * ShopSubscription::allowsNewSales() says.
 */
final class ShopSubscriptionSqlTest extends KernelTestCase
{
    use Factories;

    public function testTheSqlConditionMatchesAllowsNewSales(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 12:00');
        $cases = [
            'active' => [SubscriptionStatus::Active, null, '2026-11-30 00:00'],
            'trial running' => [SubscriptionStatus::Trialing, '2026-11-20 00:00', null],
            'trial over' => [SubscriptionStatus::Trialing, '2026-11-10 11:59', null],
            'trial without end' => [SubscriptionStatus::Trialing, null, null],
            'past due, in the grace period' => [SubscriptionStatus::PastDue, null, '2026-11-04 00:00'],
            'past due, grace period over' => [SubscriptionStatus::PastDue, null, '2026-11-03 11:00'],
            'past due without period' => [SubscriptionStatus::PastDue, null, null],
            'canceled' => [SubscriptionStatus::Canceled, null, '2026-12-01 00:00'],
            'active, period over' => [SubscriptionStatus::Active, null, '2026-11-01 00:00'],
            'free solidarity running' => [SubscriptionStatus::Active, null, '2026-11-10 12:01', SubscriptionPlan::Solidarity],
            'free solidarity over' => [SubscriptionStatus::Active, null, '2026-11-10 12:00', SubscriptionPlan::Solidarity],
            'free solidarity without end' => [SubscriptionStatus::Active, null, null, SubscriptionPlan::Solidarity],
            'paid solidarity, period over' => [SubscriptionStatus::Active, null, '2026-11-01 00:00', SubscriptionPlan::Solidarity, 'sub_A'],
            'paid solidarity canceled' => [SubscriptionStatus::Canceled, null, '2026-12-01 00:00', SubscriptionPlan::Solidarity, 'sub_B'],
        ];

        $subscriptions = [];
        foreach ($cases as $label => $case) {
            [$status, $trialEndsAt, $currentPeriodEnd] = $case;
            $subscriptions[$label] = ShopSubscriptionFactory::createOne([
                'status' => $status,
                'plan' => $case[3] ?? SubscriptionPlan::Monthly,
                'stripeSubscriptionId' => $case[4] ?? null,
                'trialEndsAt' => null === $trialEndsAt ? null : new \DateTimeImmutable($trialEndsAt),
                'currentPeriodEnd' => null === $currentPeriodEnd ? null : new \DateTimeImmutable($currentPeriodEnd),
            ]);
        }

        [$condition, $parameters] = ShopSubscriptionRepository::allowsNewSalesSql('sub', $now);
        $allowedIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()
                ->fetchFirstColumn('SELECT sub.id FROM shop_subscription sub WHERE '.$condition, $parameters),
        );

        foreach ($subscriptions as $label => $subscription) {
            self::assertInstanceOf(ShopSubscription::class, $subscription);
            self::assertSame(
                $subscription->allowsNewSales($now),
                \in_array($subscription->getId(), $allowedIds, true),
                \sprintf('SQL and PHP disagree for "%s".', $label),
            );
        }

        self::assertCount(6, $allowedIds);
    }
}
