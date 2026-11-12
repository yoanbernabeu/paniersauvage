<?php

declare(strict_types=1);

namespace App\Shop\Command;

use App\Shop\Repository\SearchStatRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Where visitors look for producers and find none: the departments to recruit producers in.
 */
#[AsCommand(name: 'app:stats:search', description: 'Départements avec le plus de recherches de producteurs sans résultat')]
final readonly class SearchStatsCommand
{
    public function __construct(
        private SearchStatRepository $stats,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Nombre de jours pris en compte, aujourd\'hui compris')]
        int $days = 30,
        #[Option(description: 'Nombre de départements affichés')]
        int $limit = 10,
    ): int {
        if ($days < 1 || $limit < 1) {
            $io->error('--days et --limit doivent valoir au moins 1.');

            return Command::INVALID;
        }

        $since = $this->clock->now()->modify(\sprintf('-%d days', $days - 1));
        $rows = $this->stats->departmentsWithoutResults($since, $limit);

        $io->title(\sprintf('Recherches sans résultat depuis le %s', $since->format('d/m/Y')));

        if ([] === $rows) {
            $io->success('Aucune recherche sans résultat sur la période.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Département', 'Sans producteur', 'Lieu introuvable', 'Avec résultats', 'Total'],
            array_map(static fn (array $row): array => [
                '' === $row['department'] ? 'inconnu (position)' : $row['department'],
                $row['empty'],
                $row['not_found'],
                $row['results'],
                $row['total'],
            ], $rows),
        );

        return Command::SUCCESS;
    }
}
