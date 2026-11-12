<?php

declare(strict_types=1);

namespace App\Sale\Command;

use App\Sale\Service\SaleGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sales:generate', description: 'Generates the sales of the coming weeks for every active recurrence (same work as the daily scheduled task).')]
final readonly class SalesGenerateCommand
{
    public function __construct(
        private SaleGenerator $generator,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d sale(s) generated.', $this->generator->generateAll()));

        return Command::SUCCESS;
    }
}
