<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Sale\Entity\Sale;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\Shop;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The map beside the results of the producer search: data rendered in the page for the Stimulus
 * controller producer-map, tile server set by MAP_TILE_URL, allowed by the CSP and nothing more.
 */
final class ProducerSearchMapTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    /** Place du Capitole, Toulouse. */
    private const float LAT = 43.6045;
    private const float LNG = 1.4440;

    private const array TILE_VARIABLES = ['MAP_TILE_URL', 'MAP_TILE_ATTRIBUTION', 'MAP_TILE_ATTRIBUTION_URL'];

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }
        $this->savedEnv = [];

        parent::tearDown();
    }

    public function testTheResultsPageCarriesTheDataOfTheMap(): void
    {
        $client = self::createClient();
        $albi = $this->producer('Fromagerie d\'Albi "Le Vieux" <b>', 43.6363, 1.3906, "Marché couvert\n31700 Blagnac");
        $sale = SaleFactory::createOne(['shop' => $albi]);
        $muret = $this->producer('Fournil de Muret', 43.4615, 1.3266, "Place de la mairie\n31600 Muret");
        $scheduled = SaleFactory::new()->scheduled()->create(['shop' => $muret]);

        $crawler = $this->searchAround($client, 30);

        $map = $crawler->filter('[data-controller="producer-map"]');
        self::assertCount(1, $map);
        self::assertStringContainsString('les flèches passent de l\'un à l\'autre', $crawler->filter('#carte-aide')->text());
        self::assertSame('30', $map->attr('data-producer-map-radius-value'));
        // The position of the browser is rounded to three decimals (SearchCriteria).
        self::assertSame(['lat' => 43.605, 'lng' => 1.444, 'label' => 'Toi'], $this->json($map, 'center'));
        self::assertSame([
            'url' => 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM_0_19&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png',
            'attribution' => '© IGN – Plan IGN',
            'attributionUrl' => 'https://www.ign.fr',
        ], $this->json($map, 'tiles'));

        $formatter = self::getContainer()->get(FrenchFormatter::class);
        self::assertInstanceOf(Sale::class, $sale);
        self::assertInstanceOf(Sale::class, $scheduled);
        self::assertSame([
            [
                'slug' => 'fromagerie-d-albi-le-vieux-b',
                'name' => 'Fromagerie d\'Albi "Le Vieux" <b>',
                'lat' => 43.6363,
                'lng' => 1.3906,
                'distance' => 'à 6 km',
                'point' => 'Place du marché, Blagnac',
                'nextSale' => 'Commandes ouvertes jusqu\'au '.$formatter->dateTimeLong($sale->getDeadlineAt()),
                'url' => '/fromagerie-d-albi-le-vieux-b?via=recherche',
                'logo' => null,
            ],
            [
                'slug' => 'fournil-de-muret',
                'name' => 'Fournil de Muret',
                'lat' => 43.4615,
                'lng' => 1.3266,
                'distance' => 'à 19 km',
                'point' => 'Place du marché, Muret',
                'nextSale' => 'Prochaine vente le '.$formatter->dateLong($scheduled->getOpensAt()),
                'url' => '/fournil-de-muret?via=recherche',
                'logo' => null,
            ],
        ], $this->json($map, 'results'));

        // Names are escaped in the attribute, never interpreted as markup.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('"Le Vieux" <b>', $html);
        self::assertStringContainsString('&quot;Le Vieux&quot; &lt;b&gt;', $html);

        // The map is hidden until its controller shows it, and links each card of the list.
        self::assertSelectorExists('[data-producer-map-target="frame"][hidden] [role="region"][aria-label="Carte des producteurs autour de toi"] [data-producer-map-target="canvas"]');
        self::assertCount(2, $crawler->filter('a[data-result][data-producer-map-target="item"]'));
        self::assertSelectorExists('link[rel="stylesheet"][href*="/assets/vendor/leaflet/dist/leaflet.min"]');
    }

    public function testASearchedPlaceIsTheCentreOfTheMapAndKeepsLogos(): void
    {
        FakeBan::knows('Toulouse', self::LAT, self::LNG, city: 'Toulouse', postcode: '31000');
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906, null, ['logoPath' => 'uploads/logos/ferme.png']);

        $crawler = $client->request('GET', '/trouver-un-producteur?ou=Toulouse&rayon=10');

        $map = $crawler->filter('[data-controller="producer-map"]');
        self::assertSame('10', $map->attr('data-producer-map-radius-value'));
        self::assertSame(['lat' => self::LAT, 'lng' => self::LNG, 'label' => 'Ta recherche : Toulouse'], $this->json($map, 'center'));
        $results = $this->json($map, 'results');
        self::assertIsArray($results);
        self::assertIsArray($results[0]);
        self::assertSame('/uploads/logos/ferme.png', $results[0]['logo']);
    }

    public function testNoMapWithoutResults(): void
    {
        $client = self::createClient();

        $client->request('GET', '/trouver-un-producteur');
        self::assertSelectorNotExists('[data-controller="producer-map"]');
        self::assertSelectorNotExists('link[href*="leaflet"]');

        $client->request('GET', \sprintf('/trouver-un-producteur?lat=%s&lng=%s&rayon=10', self::LAT, self::LNG));
        self::assertSelectorExists('[data-empty="empty"]');
        self::assertSelectorNotExists('[data-controller="producer-map"]');
        self::assertSelectorNotExists('[data-producer-map-target]');
        self::assertSelectorNotExists('link[href*="leaflet"]');
    }

    public function testThePolicyAllowsTheTileServerAndNothingWider(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);

        foreach (['/trouver-un-producteur', \sprintf('/trouver-un-producteur?lat=%s&lng=%s', self::LAT, self::LNG)] as $path) {
            $client->request('GET', $path);
            $directives = $this->directives($client);

            self::assertSame("'self' data: blob: https://data.geopf.fr", $directives['img-src'], $path);
            self::assertSame("'self'", $directives['connect-src'], $path);
            self::assertSame("'self'", $directives['default-src'], $path);
            self::assertStringNotContainsString('geopf', $directives['script-src'], $path);
            self::assertStringNotContainsString('geopf', $directives['style-src'], $path);
        }
    }

    public function testTheTileServerComesFromTheEnvironment(): void
    {
        $this->setEnv('MAP_TILE_URL', 'https://{s}.tiles.example.org/carte/{z}/{x}/{y}.png?key=abc');
        $this->setEnv('MAP_TILE_ATTRIBUTION', '© Exemple, © contributeurs OpenStreetMap');
        $this->setEnv('MAP_TILE_ATTRIBUTION_URL', '');
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);

        $crawler = $this->searchAround($client, 30);

        self::assertSame("'self' data: blob: https://*.tiles.example.org", $this->directives($client)['img-src']);
        self::assertSame([
            'url' => 'https://{s}.tiles.example.org/carte/{z}/{x}/{y}.png?key=abc',
            'attribution' => '© Exemple, © contributeurs OpenStreetMap',
            'attributionUrl' => null,
        ], $this->json($crawler->filter('[data-controller="producer-map"]'), 'tiles'));
    }

    public function testAnEmptyTileServerTurnsTheMapOff(): void
    {
        $this->setEnv('MAP_TILE_URL', '');
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);

        $crawler = $this->searchAround($client, 30);

        self::assertCount(1, $crawler->filter('[data-result]'));
        self::assertSelectorNotExists('[data-controller="producer-map"]');
        self::assertSelectorNotExists('link[href*="leaflet"]');
        self::assertSame("'self' data: blob:", $this->directives($client)['img-src']);
    }

    private function setEnv(string $name, string $value): void
    {
        \assert(\in_array($name, self::TILE_VARIABLES, true));
        $this->savedEnv[$name] ??= [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function producer(string $name, float $latitude, float $longitude, ?string $address = null, array $attributes = []): Shop
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name, 'slug' => $slug, ...$attributes]);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::new()->located($latitude, $longitude)->create(['shop' => $shop, 'name' => 'Place du marché', 'address' => $address ?? "Place du marché\n31000 Toulouse"]);

        return $shop;
    }

    private function searchAround(KernelBrowser $client, int $radius): Crawler
    {
        $crawler = $client->request('GET', \sprintf('/trouver-un-producteur?lat=%s&lng=%s&rayon=%d', self::LAT, self::LNG, $radius));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function json(Crawler $map, string $value): mixed
    {
        return json_decode((string) $map->attr('data-producer-map-'.$value.'-value'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function directives(KernelBrowser $client): array
    {
        $directives = [];
        foreach (explode(';', (string) $client->getResponse()->headers->get('Content-Security-Policy')) as $directive) {
            $parts = explode(' ', trim($directive), 2);
            $directives[$parts[0]] = $parts[1] ?? '';
        }

        return $directives;
    }
}
