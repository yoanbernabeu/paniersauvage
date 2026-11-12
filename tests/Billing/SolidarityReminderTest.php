<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Entity\ShopSubscription;
use App\Billing\Enum\SubscriptionPlan;
use App\Billing\Enum\SubscriptionStatus;
use App\Billing\Scheduler\SolidarityReminderMessage;
use App\Billing\Scheduler\SolidarityReminderTask;
use App\Billing\Service\BillingBannerProvider;
use App\Billing\Service\SolidarityRenewalReminder;
use App\Notification\Repository\NotificationLogRepository;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Zenstruck\Foundry\Test\Factories;

/**
 * Renewal reminder of the free solidarity plan, 30 days before its end.
 */
final class SolidarityReminderTest extends KernelTestCase
{
    use Factories;

    public function testRemindsOncePerFreePeriodEndingWithinThirtyDays(): void
    {
        $producer = UserFactory::createOne(['email' => 'lea@example.test', 'firstName' => 'Léa']);
        $due = $this->freePlan(['shop' => $producer->getShop(), 'currentPeriodEnd' => new \DateTimeImmutable('+20 days')]);
        $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('+31 days')]);
        $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('-1 day')]);
        $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('+10 days'), 'solidarityReminderSentAt' => new \DateTimeImmutable('-20 days')]);
        $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('+10 days'), 'stripeSubscriptionId' => 'sub_PAID']);
        $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('+10 days'), 'status' => SubscriptionStatus::Canceled]);
        ShopSubscriptionFactory::new()->active()->create(['currentPeriodEnd' => new \DateTimeImmutable('+10 days')]);

        $reminder = self::getContainer()->get(SolidarityRenewalReminder::class);
        self::assertSame(1, $reminder->remindDue());
        self::assertSame(0, $reminder->remindDue(), 'Never twice for the same period.');

        self::assertNotNull($due->getSolidarityReminderSentAt());
        $emails = array_values(array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email));
        self::assertCount(1, $emails);
        self::assertSame('lea@example.test', $emails[0]->getTo()[0]->getAddress());
        self::assertEmailSubjectContains($emails[0], 'Ton palier solidaire arrive à échéance');
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($emails[0], 'Bonjour Léa');
            self::$assert($emails[0], 'renouveler ta déclaration sur l\'honneur');
            self::$assert($emails[0], '/pro/abonnement');
        }

        $logs = self::getContainer()->get(NotificationLogRepository::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('solidarity_renewal', $logs[0]->getType());
        self::assertSame('shop_subscription', $logs[0]->getRelatedType());
        self::assertSame($due->getId(), $logs[0]->getRelatedId());
    }

    public function testTheSchedulerRunsTheReminderEveryMorning(): void
    {
        $this->freePlan(['shop' => UserFactory::createOne()->getShop(), 'currentPeriodEnd' => new \DateTimeImmutable('+5 days')]);

        $task = self::getContainer()->get(SolidarityReminderTask::class);
        $task();

        self::assertCount(1, array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email));

        $recurring = SolidarityReminderTask::recurringMessage();
        self::assertInstanceOf(SolidarityReminderMessage::class, iterator_to_array($recurring->getMessages(new MessageContext('default', 'id', $recurring->getTrigger(), new \DateTimeImmutable())))[0]);
        self::assertStringContainsString('09:30', (string) $recurring->getTrigger());
    }

    public function testTheBannerWarnsBeforeTheEndOfTheFreePeriod(): void
    {
        $provider = self::getContainer()->get(BillingBannerProvider::class);
        $now = new \DateTimeImmutable('2026-11-10 10:00');

        $soon = $this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('2026-11-30 10:00')]);
        $banner = $provider->for($soon, $now);
        self::assertSame(['warning', 'Ton palier solidaire gratuit se termine le lundi 30 novembre 2026.', 'Renouveler'], [$banner?->variant, $banner?->title, $banner?->action]);
        self::assertStringContainsString('Renouvelle ta déclaration', (string) $banner?->message);

        self::assertNull($provider->for($this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('2026-12-11 10:00')]), $now));

        $over = $provider->for($this->freePlan(['currentPeriodEnd' => new \DateTimeImmutable('2026-11-10 09:00')]), $now);
        self::assertSame(['destructive', 'Ton palier solidaire gratuit est arrivé à échéance.', 'Renouveler ma déclaration'], [$over?->variant, $over?->title, $over?->action]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function freePlan(array $attributes): ShopSubscription
    {
        return ShopSubscriptionFactory::createOne($attributes + [
            'plan' => SubscriptionPlan::Solidarity,
            'status' => SubscriptionStatus::Active,
            'amountCents' => 0,
            'trialEndsAt' => null,
        ]);
    }
}
