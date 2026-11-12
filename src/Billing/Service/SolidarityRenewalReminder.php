<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Event\SolidarityPlanEnding;
use App\Billing\Repository\ShopSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Reminds producers whose free solidarity period ends within 30 days, once per period (a new
 * declaration starts a new period and clears the flag). Safe to run several times a day.
 */
final readonly class SolidarityRenewalReminder
{
    public function __construct(
        private ShopSubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int number of subscriptions reminded by this run
     */
    public function remindDue(): int
    {
        $now = $this->clock->now();
        $due = $this->subscriptions->findFreeSolidarityToRemind($now, $now->modify(\sprintf('+%d days', SolidarityPlan::REMINDER_DAYS)));

        foreach ($due as $subscription) {
            // Flagged first: a crash after the e-mail never sends it twice.
            $subscription->setSolidarityReminderSentAt($now);
            $this->entityManager->flush();
            $this->dispatcher->dispatch(new SolidarityPlanEnding($subscription));
        }

        return \count($due);
    }
}
