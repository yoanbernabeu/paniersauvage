<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\Geocoding\BanGeocoder;
use App\Delivery\Geocoding\GeocoderUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BanGeocoderTest extends TestCase
{
    private const string TOULOUSE = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[1.433805,43.604082]},"properties":{"label":"Toulouse","score":0.9638,"type":"municipality","name":"Toulouse","postcode":"31000","citycode":"31555","city":"Toulouse","context":"31, Haute-Garonne, Occitanie"}}],"query":"Toulouse"}';

    public function testReturnsTheBestMatchWithLatitudeAndLongitudeInTheRightOrder(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse(self::TOULOUSE);
        }, 'https://api-adresse.data.gouv.fr');

        $place = new BanGeocoder($client, new NullLogger())->geocode("  Toulouse\n ");

        self::assertNotNull($place);
        self::assertEqualsWithDelta(43.604082, $place->latitude, 0.000001);
        self::assertEqualsWithDelta(1.433805, $place->longitude, 0.000001);
        self::assertSame('Toulouse', $place->label);
        self::assertSame('Toulouse', $place->city);
        self::assertSame('31000', $place->postcode);
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('https://api-adresse.data.gouv.fr/search/?q=Toulouse&limit=1', $requests[0]['url']);
    }

    public function testNoFeatureMeansNotFound(): void
    {
        $client = new MockHttpClient(new MockResponse('{"type":"FeatureCollection","features":[],"query":"Xyzzy"}'), 'https://api-adresse.data.gouv.fr');

        self::assertNull(new BanGeocoder($client, new NullLogger())->geocode('Xyzzy'));
    }

    public function testALowScoreIsIgnored(): void
    {
        $body = str_replace('"score":0.9638', '"score":0.31', self::TOULOUSE);
        $client = new MockHttpClient(new MockResponse($body), 'https://api-adresse.data.gouv.fr');

        self::assertNull(new BanGeocoder($client, new NullLogger())->geocode('Toulouse'));
    }

    public function testAQueryTheApiWouldRejectIsNotSent(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('No request expected.'), 'https://api-adresse.data.gouv.fr');
        $geocoder = new BanGeocoder($client, new NullLogger());

        self::assertNull($geocoder->geocode('ab'));
        self::assertNull($geocoder->geocode('   '));
        self::assertNull($geocoder->geocode('--- Toulouse'));
    }

    public function testABadRequestMeansNotFound(): void
    {
        $client = new MockHttpClient(new MockResponse('{"code":400}', ['http_code' => 400]), 'https://api-adresse.data.gouv.fr');

        self::assertNull(new BanGeocoder($client, new NullLogger())->geocode('Toulouse'));
    }

    public function testAServerErrorMeansUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('oops', ['http_code' => 503]), 'https://api-adresse.data.gouv.fr');

        $this->expectException(GeocoderUnavailableException::class);
        new BanGeocoder($client, new NullLogger())->geocode('Toulouse');
    }

    public function testATimeoutMeansUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse(['']), 'https://api-adresse.data.gouv.fr');

        $this->expectException(GeocoderUnavailableException::class);
        new BanGeocoder($client, new NullLogger())->geocode('Toulouse');
    }

    public function testAnInvalidBodyMeansUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>maintenance</html>'), 'https://api-adresse.data.gouv.fr');

        $this->expectException(GeocoderUnavailableException::class);
        new BanGeocoder($client, new NullLogger())->geocode('Toulouse');
    }
}
