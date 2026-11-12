<?php

declare(strict_types=1);

namespace App;

use App\Billing\Scheduler\SolidarityReminderTask;
use App\Notification\Scheduler\SendRemindersTask;
use App\Sale\Scheduler\SaleGenerationTask;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Daily tasks at a fixed Europe/Paris time (a "1 day" periodic task drifts with daylight saving).
            ->add(
                SendRemindersTask::recurringMessage(),
                SaleGenerationTask::recurringMessage(),
                SolidarityReminderTask::recurringMessage(),
            )

            // Other recurring messages are attached with #[AsPeriodicTask] on their handlers.
        ;
    }
}
