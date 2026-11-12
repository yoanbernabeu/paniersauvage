<?php

declare(strict_types=1);

namespace App\Billing\Scheduler;

use App\Billing\Service\SolidarityRenewalReminder;
use App\Shared\Scheduler\DailyAtTrigger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Every day at 09:30 (Europe/Paris): renewal reminder of the free solidarity periods ending within
 * 30 days. Registered in App\Schedule through recurringMessage().
 */
#[AsMessageHandler(handles: SolidarityReminderMessage::class)]
final readonly class SolidarityReminderTask
{
    public const string AT = '09:30';

    public function __construct(
        private SolidarityRenewalReminder $reminder,
    ) {
    }

    public static function recurringMessage(): RecurringMessage
    {
        return RecurringMessage::trigger(new DailyAtTrigger(self::AT), new SolidarityReminderMessage());
    }

    public function __invoke(): void
    {
        $this->reminder->remindDue();
    }
}
