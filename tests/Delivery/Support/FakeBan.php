<?php

declare(strict_types=1);

namespace App\Tests\Delivery\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stand-in for the API Adresse in tests (mock response factory of the `ban.client` scoped client).
 *
 * By default every query finds nothing. A test teaches it places with `knows()`, or makes the API
 * fail with `down()` / `timeout()`. The state is static because the test client reboots the kernel
 * between requests: use FakeBanTrait, which resets it around each test.
 */
final class FakeBan
{
    /** @var array<string, array{lat: float, lng: float, score: float, label: string, city: string, postcode: string, citycode: ?string}> */
    private static array $places = [];

    private static ?string $failure = null;

    /** @var list<string> the `q` parameter of every request received */
    private static array $queries = [];

    public static function reset(): void
    {
        self::$places = [];
        self::$failure = null;
        self::$queries = [];
    }

    public static function knows(string $query, float $latitude, float $longitude, float $score = 0.95, ?string $city = null, string $postcode = '00000', ?string $citycode = null): void
    {
        self::$places[self::key($query)] = [
            'lat' => $latitude,
            'lng' => $longitude,
            'score' => $score,
            'label' => $query,
            'city' => $city ?? $query,
            'postcode' => $postcode,
            'citycode' => $citycode,
        ];
    }

    /** Every request answers HTTP 500. */
    public static function down(): void
    {
        self::$failure = 'down';
    }

    /** Every request times out. */
    public static function timeout(): void
    {
        self::$failure = 'timeout';
    }

    /**
     * @return list<string>
     */
    public static function queries(): array
    {
        return self::$queries;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $parameters);
        $query = \is_string($parameters['q'] ?? null) ? $parameters['q'] : '';
        self::$queries[] = $query;

        if ('down' === self::$failure) {
            return new MockResponse('{"code":500,"message":"Internal error"}', ['http_code' => 500]);
        }

        if ('timeout' === self::$failure) {
            // An empty chunk is turned into a timeout by MockResponse.
            return new MockResponse(['']);
        }

        $place = self::$places[self::key($query)] ?? null;
        $features = null === $place ? [] : [[
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$place['lng'], $place['lat']]],
            'properties' => [
                'label' => $place['label'],
                'score' => $place['score'],
                'type' => 'municipality',
                'name' => $place['city'],
                'postcode' => $place['postcode'],
                'city' => $place['city'],
                ...(null === $place['citycode'] ? [] : ['citycode' => $place['citycode']]),
            ],
        ]];

        return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features, 'query' => $query], \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private static function key(string $query): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $query)));
    }
}
