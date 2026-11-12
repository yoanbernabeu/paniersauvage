<?php

declare(strict_types=1);

namespace App\Sale\Command;

use App\Sale\Scheduler\SaleLifecycleTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sales:tick', description: 'Opens, closes and finishes the sales that are due (same work as the 5-minute scheduled task).')]
final readonly class SalesTickCommand
{
    public function __construct(
        private SaleLifecycleTask $task,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = ($this->task)();

        $io->success(\sprintf(
            '%d opened, %d closed, %d finished, %d never opened and cancelled.',
            $result['opened'],
            $result['closed'],
            $result['finished'],
            $result['missed'],
        ));

        return Command::SUCCESS;
    }
}
