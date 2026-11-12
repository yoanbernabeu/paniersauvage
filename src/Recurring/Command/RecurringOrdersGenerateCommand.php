<?php

declare(strict_types=1);

namespace App\Recurring\Command;

use App\Recurring\Scheduler\RecurringOrderTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:recurring-orders:generate', description: 'Ends the finished subscriptions and generates the missing orders of the subscribers in the open sales (same work as the scheduled task).')]
final readonly class RecurringOrdersGenerateCommand
{
    public function __construct(
        private RecurringOrderTask $task,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        ['ended' => $ended, 'generated' => $generated] = ($this->task)();
        $io->success(\sprintf('%d subscription(s) ended, %d delivery(ies) generated.', $ended, $generated));

        return Command::SUCCESS;
    }
}
