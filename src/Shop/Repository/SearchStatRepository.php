<?php

declare(strict_types=1);

namespace App\Shop\Repository;

use App\Shop\Entity\SearchStat;
use App\Shop\Enum\SearchOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SearchStat>
 */
final class SearchStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SearchStat::class);
    }

    /**
     * Adds one search to the counter of the day, department and outcome, in a single statement
     * (upsert): concurrent searches never lose a count.
     */
    public function record(\DateTimeImmutable $day, string $department, SearchOutcome $outcome): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO search_stat (day, department, outcome, searches) VALUES (:day, :department, :outcome, 1)
                ON CONFLICT (day, department, outcome) DO UPDATE SET searches = search_stat.searches + 1
                SQL,
            ['day' => $day->format('Y-m-d'), 'department' => $department, 'outcome' => $outcome->value],
        );
    }

    /**
     * Departments with the most searches that found nobody (no producer nearby, or unknown place)
     * since $since: where producers are missing.
     *
     * @return list<array{department: string, empty: int, not_found: int, results: int, total: int}>
     */
    public function departmentsWithoutResults(\DateTimeImmutable $since, int $limit): array
    {
        /** @var list<array{department: string, empty: int|string, not_found: int|string, results: int|string, total: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT department,
                    SUM(CASE WHEN outcome = :empty THEN searches ELSE 0 END) AS empty,
                    SUM(CASE WHEN outcome = :not_found THEN searches ELSE 0 END) AS not_found,
                    SUM(CASE WHEN outcome = :results THEN searches ELSE 0 END) AS results,
                    SUM(searches) AS total
                FROM search_stat
                WHERE day >= :since
                GROUP BY department
                HAVING SUM(CASE WHEN outcome <> :results THEN searches ELSE 0 END) > 0
                ORDER BY SUM(CASE WHEN outcome <> :results THEN searches ELSE 0 END) DESC, department ASC
                LIMIT :limit
                SQL,
            [
                'since' => $since->format('Y-m-d'),
                'empty' => SearchOutcome::Empty->value,
                'not_found' => SearchOutcome::NotFound->value,
                'results' => SearchOutcome::Results->value,
                'limit' => $limit,
            ],
        );

        return array_map(static fn (array $row): array => [
            'department' => $row['department'],
            'empty' => (int) $row['empty'],
            'not_found' => (int) $row['not_found'],
            'results' => (int) $row['results'],
            'total' => (int) $row['total'],
        ], $rows);
    }
}
