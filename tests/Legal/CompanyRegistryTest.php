<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Verification\CompanyRecord;
use App\Legal\Verification\CompanyRegistry;
use App\Legal\Verification\CompanyRegistryUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The client of the API Recherche d'entreprises, against canned answers in the format of the real
 * API (checked on 2026-11-10: `results[]` with `siege` and `matching_etablissements`).
 */
final class CompanyRegistryTest extends TestCase
{
    private const string SIRET = '12345678900015';

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    public function testFindsTheMatchingEstablishmentAndSendsOnlyTheSiret(): void
    {
        $registry = $this->registry($this->answer([
            $this->company('EARL DU PONT', 'A', '01.13Z', [$this->establishment('12345678900023', '01.13Z', 'A'), $this->establishment(self::SIRET, '10.71C', 'A')]),
        ]));

        $record = $registry->find(self::SIRET);

        self::assertEquals(new CompanyRecord(self::SIRET, 'EARL DU PONT', '10.71C', true), $record);
        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://recherche-entreprises.api.gouv.fr/search?q='.self::SIRET.'&page=1&per_page=1', $this->requests[0]['url']);
    }

    public function testTheHeadOfficeCountsAsAnEstablishment(): void
    {
        $company = $this->company('GAEC DES MONTS', 'A', '01.41Z', []);
        $company['siege'] = $this->establishment(self::SIRET, '01.41Z', 'A');

        self::assertEquals(new CompanyRecord(self::SIRET, 'GAEC DES MONTS', '01.41Z', true), $this->registry($this->answer([$company]))->find(self::SIRET));
    }

    public function testAClosedEstablishmentOrCompanyIsInactive(): void
    {
        $closedEstablishment = $this->registry($this->answer([$this->company('EARL', 'A', '01.13Z', [$this->establishment(self::SIRET, '01.13Z', 'F')])]));
        self::assertFalse($closedEstablishment->find(self::SIRET)?->active);

        $closedCompany = $this->registry($this->answer([$this->company('EARL', 'C', '01.13Z', [$this->establishment(self::SIRET, '01.13Z', 'A')])]));
        self::assertFalse($closedCompany->find(self::SIRET)?->active);
    }

    public function testFallsBackOnTheCompanyNameAndActivity(): void
    {
        $company = $this->company('', 'A', '10.71C', [['siret' => self::SIRET, 'etat_administratif' => 'A']]);
        unset($company['nom_complet']);
        $company['nom_raison_sociale'] = 'BOULANGERIE MARTIN';

        self::assertEquals(new CompanyRecord(self::SIRET, 'BOULANGERIE MARTIN', '10.71C', true), $this->registry($this->answer([$company]))->find(self::SIRET));
    }

    public function testWithoutNameNorActivityTheSiretStandsForTheName(): void
    {
        $company = ['etat_administratif' => 'A', 'nom_complet' => '', 'matching_etablissements' => [['siret' => self::SIRET, 'etat_administratif' => 'A', 'activite_principale' => '']]];

        self::assertEquals(new CompanyRecord(self::SIRET, self::SIRET, null, true), $this->registry($this->answer([$company]))->find(self::SIRET));
    }

    public function testAResultWithoutThisSiretIsNotFound(): void
    {
        // The full-text search may answer a company whose number only looks alike.
        $registry = $this->registry($this->answer([$this->company('AUTRE', 'A', '62.01Z', [$this->establishment('99999999999994', '62.01Z', 'A')]), 'garbage']));

        self::assertNull($registry->find(self::SIRET));
    }

    public function testNoResultIsNotFound(): void
    {
        self::assertNull($this->registry($this->answer([]))->find(self::SIRET));
    }

    public function testAMalformedNumberIsNeverSent(): void
    {
        $registry = $this->registry($this->answer([]));

        self::assertNull($registry->find('1234 5678'));
        self::assertNull($registry->find('123456789000150'));
        self::assertSame([], $this->requests);
    }

    public function testAnswersAreCachedFoundOrNot(): void
    {
        $cache = new ArrayAdapter();
        $registry = $this->registry($this->answer([$this->company('EARL', 'A', '01.13Z', [$this->establishment(self::SIRET, '01.13Z', 'A')])]), $cache);
        $registry->find(self::SIRET);
        self::assertEquals(new CompanyRecord(self::SIRET, 'EARL', '01.13Z', true), $registry->find(self::SIRET));

        $unknown = $this->registry($this->answer([]), $cache);
        self::assertNull($unknown->find('13002526500013'));
        self::assertNull($unknown->find('13002526500013'));

        self::assertCount(2, $this->requests);
    }

    public function testAnHttpErrorIsAnOutageAndIsNotCached(): void
    {
        $cache = new ArrayAdapter();

        try {
            $this->registry(new MockResponse('{"erreur":"Too many requests"}', ['http_code' => 429]), $cache)->find(self::SIRET);
            self::fail('An HTTP error must be an outage.');
        } catch (CompanyRegistryUnavailableException $exception) {
            self::assertStringContainsString('429', $exception->getMessage());
        }

        self::assertNull($this->registry($this->answer([]), $cache)->find(self::SIRET));
        self::assertCount(2, $this->requests);
    }

    public function testATimeoutIsAnOutage(): void
    {
        $this->expectException(CompanyRegistryUnavailableException::class);

        $this->registry(new MockResponse(['']))->find(self::SIRET);
    }

    public function testAnUnexpectedBodyIsAnOutage(): void
    {
        $this->expectException(CompanyRegistryUnavailableException::class);
        $this->expectExceptionMessageIsOrContains('Unexpected answer');

        $this->registry(new MockResponse('{"message":"maintenance"}'))->find(self::SIRET);
    }

    public function testInvalidJsonIsAnOutage(): void
    {
        $this->expectException(CompanyRegistryUnavailableException::class);

        $this->registry(new MockResponse('<html>maintenance</html>'))->find(self::SIRET);
    }

    public function testLongNamesAreCutAndMalformedActivitiesDropped(): void
    {
        $registry = $this->registry(self::answer([
            self::company(str_repeat('É', 300), 'A', '01.13Z', [self::establishment(self::SIRET, 'INCONNU', 'A')]),
        ]));

        $record = $registry->find(self::SIRET);

        self::assertInstanceOf(CompanyRecord::class, $record);
        self::assertSame(str_repeat('É', 255), $record->companyName);
        self::assertNull($record->nafCode);
    }

    public function testOnlyAWholeActivityCodeIsKept(): void
    {
        foreach (['010.71C', '10.71CC', '10.71'] as $naf) {
            $registry = $this->registry(self::answer([self::company('EARL', 'A', $naf, [self::establishment(self::SIRET, $naf, 'A')])]));
            self::assertNull($registry->find(self::SIRET)?->nafCode, $naf);
        }
    }

    public function testAnswersAreKeptADayUnderTheirSiret(): void
    {
        $clock = new MockClock('2026-11-10 10:00:00');
        $cache = new ArrayAdapter(clock: $clock);
        $registry = $this->registry(self::answer([]), $cache);

        $registry->find(self::SIRET);
        self::assertTrue($cache->getItem('siret_'.self::SIRET)->isHit());

        $clock->modify('+23 hours');
        $registry->find(self::SIRET);
        self::assertCount(1, $this->requests);

        $clock->modify('+2 hours');
        $registry->find(self::SIRET);
        self::assertCount(2, $this->requests);
    }

    public function testOutagesAreLogged(): void
    {
        $logger = new BufferingLogger();

        try {
            $this->registry(new MockResponse('', ['http_code' => 503]), logger: $logger)->find(self::SIRET);
        } catch (CompanyRegistryUnavailableException) {
        }

        try {
            $this->registry(new MockResponse(['']), logger: $logger)->find(self::SIRET);
        } catch (CompanyRegistryUnavailableException $exception) {
            self::assertSame('The company register is unreachable.', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertNotNull($exception->getPrevious());
        }

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertCount(2, $logs);
        self::assertSame(['warning', 'Company register answered with HTTP {status}.', ['status' => 503]], $logs[0]);
        [$level, $message, $context] = $logs[1];
        self::assertSame('warning', $level);
        self::assertSame('Company register unreachable: {message}', $message);
        self::assertIsString($context['message'] ?? null);
        self::assertInstanceOf(\Throwable::class, $context['exception'] ?? null);
    }

    private function registry(MockResponse $response, ?ArrayAdapter $cache = null, ?BufferingLogger $logger = null): CompanyRegistry
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url];

            return $response;
        }, 'https://recherche-entreprises.api.gouv.fr');

        return new CompanyRegistry($client, $cache ?? new ArrayAdapter(), $logger ?? new NullLogger());
    }

    /**
     * @param list<mixed> $results
     */
    private function answer(array $results): MockResponse
    {
        return new MockResponse(json_encode(['results' => $results, 'total_results' => \count($results), 'page' => 1, 'per_page' => 1], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $establishments
     *
     * @return array<string, mixed>
     */
    private function company(string $name, string $state, string $naf, array $establishments): array
    {
        return [
            'siren' => substr(self::SIRET, 0, 9),
            'nom_complet' => $name,
            'nom_raison_sociale' => 'IGNORED WHEN THE FULL NAME EXISTS',
            'activite_principale' => $naf,
            'etat_administratif' => $state,
            'siege' => $this->establishment('99999999999994', '70.10Z', 'A'),
            'matching_etablissements' => $establishments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function establishment(string $siret, string $naf, string $state): array
    {
        return ['siret' => $siret, 'activite_principale' => $naf, 'etat_administratif' => $state];
    }
}
