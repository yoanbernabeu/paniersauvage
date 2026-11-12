<?php

declare(strict_types=1);

namespace App\Notification\Command;

use App\Notification\Service\ReminderSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notifications:send-reminders',
    description: 'Sends the day-before reminder to customers whose confirmed order is delivered tomorrow.',
)]
final readonly class SendRemindersCommand
{
    public function __construct(
        private ReminderSender $reminders,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $sent = $this->reminders->sendForTomorrow();

        $io->success(\sprintf('%d reminder(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
