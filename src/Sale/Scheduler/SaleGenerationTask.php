<?php

declare(strict_types=1);

namespace App\Sale\Scheduler;

use App\Sale\Service\SaleGenerator;
use App\Shared\Scheduler\DailyAtTrigger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Once a day, at 03:30 (Europe/Paris wall-clock time): generates the sales of the coming weeks for
 * every active recurrence. Registered in App\Schedule through recurringMessage().
 */
#[AsMessageHandler(handles: GenerateSalesMessage::class)]
final readonly class SaleGenerationTask
{
    public const string AT = '03:30';

    public function __construct(
        private SaleGenerator $generator,
    ) {
    }

    public static function recurringMessage(): RecurringMessage
    {
        return RecurringMessage::trigger(new DailyAtTrigger(self::AT), new GenerateSalesMessage());
    }

    public function __invoke(): int
    {
        return $this->generator->generateAll();
    }
}
