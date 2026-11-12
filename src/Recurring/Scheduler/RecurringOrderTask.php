<?php

declare(strict_types=1);

namespace App\Recurring\Scheduler;

use App\Recurring\Service\RecurringOrderGenerator;
use App\Recurring\Service\RecurringOrderManager;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 15 minutes: ends the subscriptions whose last day has passed, then generates the orders
 * still missing in the open sales (catch-up of GenerateOnSaleOpenedListener: missed run, failure).
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class RecurringOrderTask
{
    public function __construct(
        private RecurringOrderManager $manager,
        private RecurringOrderGenerator $generator,
    ) {
    }

    /**
     * @return array{ended: int, generated: int}
     */
    public function __invoke(): array
    {
        return ['ended' => $this->manager->endDue(), 'generated' => $this->generator->generateAll()];
    }
}
