<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\Geocoding\BanGeocoder;
use App\Delivery\Geocoding\GeocoderUnavailableException;
use App\Delivery\Geocoding\PlaceSearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlaceSearchTest extends TestCase
{
    private const string TOULOUSE = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[1.433805,43.604082]},"properties":{"label":"Toulouse","score":0.96,"city":"Toulouse","postcode":"31000"}}]}';
    private const string NOTHING = '{"type":"FeatureCollection","features":[]}';

    /** @var list<string> */
    private array $queries = [];

    private bool $down = false;

    public function testAPlaceFoundIsCachedWhateverTheCaseAndSpacing(): void
    {
        $search = $this->placeSearch();

        $first = $search->find('Toulouse');
        $second = $search->find('  toulouse ');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertEqualsWithDelta(43.604082, $second->latitude, 0.000001);
        self::assertSame('Toulouse', $second->city);
        self::assertSame(['Toulouse'], $this->queries);
    }

    public function testAnUnknownPlaceIsCachedToo(): void
    {
        $search = $this->placeSearch();

        self::assertNull($search->find('Xyzzyville'));
        self::assertNull($search->find('Xyzzyville'));
        self::assertSame(['Xyzzyville'], $this->queries);
    }

    public function testAnUnavailableApiIsNeverCached(): void
    {
        $search = $this->placeSearch();
        $this->down = true;

        try {
            $search->find('Toulouse');
            self::fail('The outage should be reported.');
        } catch (GeocoderUnavailableException) {
        }

        $this->down = false;
        self::assertNotNull($search->find('Toulouse'));
        self::assertSame(['Toulouse', 'Toulouse'], $this->queries);
    }

    private function placeSearch(): PlaceSearch
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $parameters);
            $query = \is_string($parameters['q'] ?? null) ? $parameters['q'] : '';
            $this->queries[] = $query;

            if ($this->down) {
                return new MockResponse('', ['http_code' => 502]);
            }

            return new MockResponse('toulouse' === mb_strtolower($query) ? self::TOULOUSE : self::NOTHING);
        }, 'https://api-adresse.data.gouv.fr');

        return new PlaceSearch(new BanGeocoder($client, new NullLogger()), new ArrayAdapter());
    }
}
