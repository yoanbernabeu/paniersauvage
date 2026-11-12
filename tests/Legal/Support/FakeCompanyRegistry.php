<?php

declare(strict_types=1);

namespace App\Tests\Legal\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stand-in for the API Recherche d'entreprises in tests (mock response factory of the
 * `company_registry.client` scoped client), answering in the format of the real API.
 *
 * By default every SIRET is unknown. A test teaches it establishments with `knows()`, or makes the
 * API fail with `down()` / `timeout()`. Static state (the test client reboots the kernel): use
 * FakeCompanyRegistryTrait, which resets it around each test.
 */
final class FakeCompanyRegistry
{
    /** @var array<string, array{name: string, naf: string, company_active: bool, establishment_active: bool, head_office: bool}> */
    private static array $establishments = [];

    private static ?string $failure = null;

    /** @var list<string> the `q` parameter of every request received */
    private static array $queries = [];

    /** @var list<string> the User-Agent header of every request received */
    private static array $userAgents = [];

    public static function reset(): void
    {
        self::$establishments = [];
        self::$failure = null;
        self::$queries = [];
        self::$userAgents = [];
    }

    public static function knows(string $siret, string $name = 'EARL DU PONT', string $naf = '01.13Z', bool $active = true, bool $companyActive = true, bool $headOffice = true): void
    {
        self::$establishments[$siret] = ['name' => $name, 'naf' => $naf, 'company_active' => $companyActive, 'establishment_active' => $active, 'head_office' => $headOffice];
    }

    /** Every request answers HTTP 503. */
    public static function down(): void
    {
        self::$failure = 'down';
    }

    /** Every request times out. */
    public static function timeout(): void
    {
        self::$failure = 'timeout';
    }

    /** Every request answers 200 with a body that is not the expected JSON. */
    public static function garbled(): void
    {
        self::$failure = 'garbled';
    }

    /**
     * @return list<string>
     */
    public static function queries(): array
    {
        return self::$queries;
    }

    /**
     * @return list<string>
     */
    public static function userAgents(): array
    {
        return self::$userAgents;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $parameters);
        $query = \is_string($parameters['q'] ?? null) ? $parameters['q'] : '';
        self::$queries[] = $query;
        $headers = \is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        self::$userAgents[] = \is_array($headers['user-agent'] ?? null) && \is_string($headers['user-agent'][0] ?? null) ? $headers['user-agent'][0] : '';

        return match (self::$failure) {
            'down' => new MockResponse('{"erreur":"Service indisponible"}', ['http_code' => 503]),
            // An empty chunk is turned into a timeout by MockResponse.
            'timeout' => new MockResponse(['']),
            'garbled' => new MockResponse('{"message":"ok"}', ['response_headers' => ['content-type' => 'application/json']]),
            default => $this->answer($query),
        };
    }

    private function answer(string $siret): MockResponse
    {
        $known = self::$establishments[$siret] ?? null;
        $results = [];

        if (null !== $known) {
            $establishment = [
                'siret' => $siret,
                'activite_principale' => $known['naf'],
                'etat_administratif' => $known['establishment_active'] ? 'A' : 'F',
                'est_siege' => $known['head_office'],
            ];
            $headOffice = $known['head_office'] ? $establishment : [
                'siret' => substr($siret, 0, 9).'00019',
                'activite_principale' => '70.10Z',
                'etat_administratif' => 'A',
                'est_siege' => true,
            ];

            $results[] = [
                'siren' => substr($siret, 0, 9),
                'nom_complet' => $known['name'],
                'nom_raison_sociale' => $known['name'],
                'activite_principale' => $known['naf'],
                'etat_administratif' => $known['company_active'] ? 'A' : 'C',
                'siege' => $headOffice,
                'matching_etablissements' => [$establishment],
            ];
        }

        return new MockResponse(json_encode(['results' => $results, 'total_results' => \count($results), 'page' => 1, 'per_page' => 1, 'total_pages' => \count($results)], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
