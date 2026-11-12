<?php

declare(strict_types=1);

namespace App\Notification\Scheduler;

use App\Notification\Service\ReminderSender;
use App\Shared\Scheduler\DailyAtTrigger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Every day at 10:00 (Europe/Paris wall-clock time, daylight saving included): remind customers of
 * tomorrow's deliveries. Registered in App\Schedule through recurringMessage().
 */
#[AsMessageHandler(handles: SendRemindersMessage::class)]
final readonly class SendRemindersTask
{
    public const string AT = '10:00';

    public function __construct(
        private ReminderSender $reminders,
    ) {
    }

    public static function recurringMessage(): RecurringMessage
    {
        return RecurringMessage::trigger(new DailyAtTrigger(self::AT), new SendRemindersMessage());
    }

    public function __invoke(): void
    {
        $this->reminders->sendForTomorrow();
    }
}
