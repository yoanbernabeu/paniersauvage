<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Repository\ShopSubscriptionRepository;
use App\Instance\SubscriptionPolicy;
use App\Sale\Service\SubscriptionGate;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * SPEC §7 on an instance: the rule (SubscriptionPolicy::allowsNewSales()) and its SQL translation
 * for the directory agree, in the standard mode (the subscription decides) and in the sponsored
 * mode (every shop may).
 */
final class SubscriptionPolicyTest extends KernelTestCase
{
    use Factories;
    use InstanceTrait;

    /**
     * @param list<string> $allowed
     */
    #[TestWith(['standard', ['active', 'trial running', 'past due, in the grace period']])]
    #[TestWith(['sponsored', ['active', 'trial running', 'trial without end', 'trial over', 'past due, in the grace period', 'past due, grace period over', 'canceled', 'no subscription']])]
    public function testTheSqlConditionMatchesTheRule(string $pricing, array $allowed): void
    {
        $this->useInstance(['INSTANCE_PRICING' => $pricing, 'INSTANCE_CARRIER_NAME' => 'Valence Romans Agglo']);
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-11-10 12:00');
        $cases = [
            'active' => [SubscriptionStatus::Active, null, '2026-11-30 00:00'],
            'trial running' => [SubscriptionStatus::Trialing, '2026-11-20 00:00', null],
            'trial without end' => [SubscriptionStatus::Trialing, null, null],
            'trial over' => [SubscriptionStatus::Trialing, '2026-11-10 11:59', null],
            'past due, in the grace period' => [SubscriptionStatus::PastDue, null, '2026-11-04 00:00'],
            'past due, grace period over' => [SubscriptionStatus::PastDue, null, '2026-11-03 11:00'],
            'canceled' => [SubscriptionStatus::Canceled, null, '2026-12-01 00:00'],
        ];

        $policy = self::getContainer()->get(SubscriptionPolicy::class);
        $subscriptions = self::getContainer()->get(ShopSubscriptionRepository::class);
        /** @var array<string, Shop> $shops */
        $shops = [];
        foreach ($cases as $label => [$status, $trialEndsAt, $currentPeriodEnd]) {
            $shops[$label] = ShopSubscriptionFactory::createOne([
                'status' => $status,
                'trialEndsAt' => null === $trialEndsAt ? null : new \DateTimeImmutable($trialEndsAt),
                'currentPeriodEnd' => null === $currentPeriodEnd ? null : new \DateTimeImmutable($currentPeriodEnd),
            ])->getShop();
        }
        $shops['no subscription'] = ShopFactory::createOne();

        [$condition, $parameters] = $policy->shopAllowsNewSalesSql('s', 'shop_subscription', $now);
        $sqlIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn('SELECT s.id FROM shop s WHERE '.$condition, $parameters),
        );

        $byRule = $bySql = [];
        foreach ($shops as $label => $shop) {
            if ($policy->allowsNewSales($subscriptions->findOneByShop($shop), $now)) {
                $byRule[] = $label;
            }
            if (\in_array($shop->getId(), $sqlIds, true)) {
                $bySql[] = $label;
            }
        }

        self::assertSame($allowed, $byRule, 'Rule');
        self::assertSame($allowed, $bySql, 'SQL');
    }

    public function testTheSalesGateFollowsTheInstance(): void
    {
        self::bootKernel();
        $expired = ShopSubscriptionFactory::new()->trialExpired()->create()->getShop();
        $without = ShopFactory::createOne();
        self::assertFalse(self::getContainer()->get(SubscriptionGate::class)->allowsNewSales($expired));
        self::assertFalse(self::getContainer()->get(SubscriptionGate::class)->allowsNewSales($without));

        $this->useInstance(['INSTANCE_PRICING' => 'sponsored', 'INSTANCE_CARRIER_NAME' => 'Valence Romans Agglo']);
        self::bootKernel();
        self::assertTrue(self::getContainer()->get(SubscriptionGate::class)->allowsNewSales($expired));
        self::assertTrue(self::getContainer()->get(SubscriptionGate::class)->allowsNewSales($without));
    }
}
