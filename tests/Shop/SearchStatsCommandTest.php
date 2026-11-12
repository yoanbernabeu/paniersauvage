<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Enum\SearchOutcome;
use App\Shop\Repository\SearchStatRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchStatsCommandTest extends KernelTestCase
{
    public function testItListsTheDepartmentsWithTheMostSearchesWithoutResult(): void
    {
        self::bootKernel();
        $stats = self::getContainer()->get(SearchStatRepository::class);
        $today = new \DateTimeImmutable('today');
        foreach ([['26', SearchOutcome::Empty, 3], ['26', SearchOutcome::NotFound, 1], ['26', SearchOutcome::Results, 5], ['07', SearchOutcome::Empty, 6], ['31', SearchOutcome::Results, 9], ['', SearchOutcome::Empty, 2]] as [$department, $outcome, $count]) {
            for ($i = 0; $i < $count; ++$i) {
                $stats->record($today, $department, $outcome);
            }
        }
        // Too old for the default period (30 days, today included).
        $stats->record($today->modify('-30 days'), '84', SearchOutcome::Empty);
        $stats->record($today->modify('-29 days'), '13', SearchOutcome::NotFound);

        $tester = $this->command([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(\sprintf('Recherches sans résultat depuis le %s', $today->modify('-29 days')->format('d/m/Y')), $tester->getDisplay());
        self::assertMatchesRegularExpression('/Département\s+Sans producteur\s+Lieu introuvable\s+Avec résultats\s+Total/', $tester->getDisplay());
        $rows = $this->rows($tester->getDisplay());
        self::assertSame([
            ['07', '6', '0', '0', '6'],
            ['26', '3', '1', '5', '9'],
            ['inconnu (position)', '2', '0', '0', '2'],
            ['13', '0', '1', '0', '1'],
        ], $rows);

        self::assertSame([['07', '6', '0', '0', '6']], $this->rows($this->command(['--limit' => '1'])->getDisplay()));
        // One more day back: the search of the 84 counts too (ties by department).
        self::assertSame(['84', '1', '0', '0', '1'], $this->rows($this->command(['--days' => '31'])->getDisplay())[4]);
    }

    public function testNothingToShow(): void
    {
        self::bootKernel();
        self::getContainer()->get(SearchStatRepository::class)->record(new \DateTimeImmutable('today'), '31', SearchOutcome::Results);

        $tester = $this->command([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Aucune recherche sans résultat sur la période.', $tester->getDisplay());
        self::assertStringNotContainsString('Département', $tester->getDisplay());
    }

    public function testInvalidOptionsAreRefused(): void
    {
        self::bootKernel();

        $invalid = $this->command(['--days' => '0']);
        self::assertSame(Command::INVALID, $invalid->getStatusCode());
        self::assertStringContainsString('--days et --limit doivent valoir au moins 1.', $invalid->getDisplay());
        self::assertSame(Command::INVALID, $this->command(['--limit' => '0'])->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->command(['--days' => '1', '--limit' => '1'])->getStatusCode());
    }

    public function testTenDepartmentsByDefaultCountedAsIntegers(): void
    {
        self::bootKernel();
        $stats = self::getContainer()->get(SearchStatRepository::class);
        $today = new \DateTimeImmutable('today');
        foreach (range(10, 21) as $department) {
            $stats->record($today, (string) $department, SearchOutcome::Empty);
        }
        $stats->record($today, '10', SearchOutcome::Results);

        self::assertCount(10, $this->rows($this->command([])->getDisplay()));
        self::assertSame(
            ['department' => '10', 'empty' => 1, 'not_found' => 0, 'results' => 1, 'total' => 2],
            $stats->departmentsWithoutResults($today, 1)[0],
        );
    }

    /**
     * @param array<string, string> $options
     */
    private function command(array $options): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('app:stats:search'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $display): array
    {
        $rows = [];
        foreach (explode("\n", $display) as $line) {
            $cells = preg_split('/\s{2,}/', trim($line));
            if (\is_array($cells) && 5 === \count($cells) && 'Département' !== $cells[0]) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }
}
