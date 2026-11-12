<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Delivery\Entity\DeliveryPoint;
use App\Shop\Entity\SearchStat;
use App\Shop\Entity\Shop;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * A territorial instance restricts its directory to its territory (INSTANCE_TERRITORY): search,
 * town pages, cross discovery and sitemap only show the points inside it. Without territory,
 * everything stays as on Panier Sauvage.
 */
final class TerritoryDirectoryTest extends WebTestCase
{
    use Factories;
    use InstanceTrait;

    /** Valence, place de la Liberté. */
    private const float LAT = 44.9334;
    private const float LNG = 4.8924;

    public function testWithoutTerritoryTheWholeDirectoryIsShown(): void
    {
        $client = self::createClient();
        $this->producers();

        self::assertSame(['Fournil de Valence', 'Deux rives', 'Ferme de Romans', 'Chèvrerie de Privas'], $this->names($this->search($client)));
        $crawler = $client->request('GET', '/trouver-un-producteur');
        self::assertSelectorNotExists('[data-directory-territory]');
        self::assertSelectorNotExists('[data-territory-results]');
        self::assertSame(['Guilherand-Granges (07)', 'Privas (07)', 'Romans-sur-Isère (26)', 'Valence (26)'], $this->communes($crawler));
    }

    public function testADepartmentKeepsOnlyThePointsInsideIt(): void
    {
        $this->useInstance(['INSTANCE_TERRITORY' => '26', 'INSTANCE_TERRITORY_NAME' => 'la Drôme']);
        $client = self::createClient();
        $this->producers();

        $crawler = $this->search($client);
        self::assertSame(['Fournil de Valence', 'Ferme de Romans', 'Deux rives'], $this->names($crawler), 'Deux rives is found by its Drôme point, farther than its Ardèche one.');
        self::assertStringContainsString('Marché de Romans', $crawler->filter('[data-result="deux-rives"] [data-distance]')->text());
        self::assertSelectorTextContains('[data-directory-territory]', 'Cet annuaire couvre la Drôme : seuls les points de retrait et de livraison situés sur ce territoire y figurent.');

        $crawler = $client->request('GET', '/trouver-un-producteur');
        self::assertSame(['Romans-sur-Isère (26)', 'Valence (26)'], $this->communes($crawler));

        $client->request('GET', '/trouver-un-producteur/privas-07');
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', '/trouver-un-producteur/valence-26');
        self::assertResponseIsSuccessful();
        self::assertSame(['Fournil de Valence'], $this->names($crawler), 'Within 10 km of Valence, the Ardèche point of Deux rives does not count.');
        self::assertSelectorExists('[data-directory-territory]');

        self::assertSame([
            'http://localhost/trouver-un-producteur/romans-sur-isere-26',
            'http://localhost/trouver-un-producteur/valence-26',
            'http://localhost/deux-rives',
            'http://localhost/ferme-de-romans',
            'http://localhost/fournil-de-valence',
        ], \array_slice($this->sitemap($client), 6));

        // Cross discovery on a shop page: the Ardèche neighbour is not suggested.
        $crawler = $client->request('GET', '/fournil-de-valence');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Chèvrerie de Privas', $crawler->filter('main')->text());
    }

    public function testTownsByInseeCode(): void
    {
        $this->useInstance(['INSTANCE_TERRITORY' => '26281 07186']);
        $client = self::createClient();
        $this->producers();

        self::assertSame(['Ferme de Romans', 'Deux rives', 'Chèvrerie de Privas'], $this->names($this->search($client)), 'Deux rives by its Romans point.');
        self::assertSelectorTextContains('[data-directory-territory]', 'Cet annuaire couvre le territoire de Panier Sauvage');
    }

    public function testTheDirectoryOpensOnTheMapOfTheTerritory(): void
    {
        $this->useInstance(['INSTANCE_NAME' => 'Panier Toqué du local', 'INSTANCE_TERRITORY' => '26', 'INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924', 'INSTANCE_TERRITORY_RADIUS_KM' => '50']);
        $client = self::createClient();
        $this->producers();

        $crawler = $client->request('GET', '/trouver-un-producteur');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="canonical"]', 'Still the indexable home of the directory.');
        self::assertSame(['Fournil de Valence', 'Ferme de Romans', 'Deux rives'], $this->names($crawler));
        self::assertSelectorTextContains('[data-territory-results] h2', '3 producteurs autour de Panier Toqué du local');
        self::assertSelectorTextContains('[data-territory-results]', 'À moins de 50 km');
        $map = $crawler->filter('[data-territory-results] [data-controller="producer-map"]');
        self::assertCount(1, $map);
        self::assertSame(['lat' => self::LAT, 'lng' => self::LNG, 'label' => 'Ta recherche : Panier Toqué du local'], json_decode((string) $map->attr('data-producer-map-center-value'), true));
        self::assertSame('50', $map->attr('data-producer-map-radius-value'));
        self::assertSelectorExists('link[href*="leaflet"]');
        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(SearchStat::class)->findAll(), 'Opening the directory is not a search.');
    }

    public function testTheTerritoryNamesTheMapCentre(): void
    {
        $this->useInstance(['INSTANCE_TERRITORY' => '26', 'INSTANCE_TERRITORY_NAME' => 'Valence Romans Agglo', 'INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924', 'INSTANCE_TERRITORY_RADIUS_KM' => '10']);
        $client = self::createClient();
        $this->producers();

        $crawler = $client->request('GET', '/trouver-un-producteur');
        self::assertSame(['Fournil de Valence'], $this->names($crawler));
        self::assertSelectorTextContains('[data-territory-results] h2', '1 producteur autour de Valence Romans Agglo');
    }

    public function testAMapCentreWithoutProducerShowsNothingMore(): void
    {
        $this->useInstance(['INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924']);
        $client = self::createClient();
        $client->request('GET', '/trouver-un-producteur');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-territory-results]');
        self::assertSelectorNotExists('[data-producer-map-center-value]');
    }

    public function testASearchReplacesTheTerritoryOverview(): void
    {
        $this->useInstance(['INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924']);
        $client = self::createClient();
        $this->producers();

        $crawler = $client->request('GET', '/trouver-un-producteur?lat=45.0435&lng=5.0511&rayon=10');
        self::assertSelectorNotExists('[data-territory-results]');
        self::assertSame(['Ferme de Romans', 'Deux rives'], $this->names($crawler));
    }

    /**
     * Valence and Romans (Drôme), Privas (Ardèche), and Deux rives with one point on each side of
     * the Rhône: Guilherand-Granges (Ardèche, close to Valence) and Romans.
     */
    private function producers(): void
    {
        $this->producer('Fournil de Valence', [['Place des Clercs', "Place des Clercs\n26000 Valence", 44.9330, 4.8920, '26362']]);
        $this->producer('Ferme de Romans', [['Place Jacquemart', "Place Jacquemart\n26100 Romans-sur-Isère", 45.0435, 5.0511, '26281']]);
        $this->producer('Chèvrerie de Privas', [['Halle de Privas', "Place du Champ de Mars\n07000 Privas", 44.7353, 4.5992, '07186']]);
        $this->producer('Deux rives', [
            ['Guilherand, place de la mairie', "Place de la Mairie\n07500 Guilherand-Granges", 44.9331, 4.8735, '07102'],
            ['Marché de Romans', "Place Ernest Gailly\n26100 Romans-sur-Isère", 45.0447, 5.0498, '26281'],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: float, 4: string}> $points name, address, latitude, longitude, INSEE code
     */
    private function producer(string $name, array $points): Shop
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)), '-'));
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name, 'slug' => $slug]);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);
        foreach ($points as [$pointName, $address, $latitude, $longitude, $insee]) {
            $point = DeliveryPointFactory::new()->located($latitude, $longitude)->create(['shop' => $shop, 'name' => $pointName, 'address' => $address]);
            self::assertInstanceOf(DeliveryPoint::class, $point);
            $point->setTown($point->getCity(), $point->getPostcode(), $insee);
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $shop;
    }

    private function search(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/trouver-un-producteur?'.http_build_query(['lat' => self::LAT, 'lng' => self::LNG, 'rayon' => 50]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('[data-result] h3')->each(static fn (Crawler $node): string => $node->text());
    }

    /**
     * @return list<string>
     */
    private function communes(Crawler $crawler): array
    {
        return $crawler->filter('[data-communes] a')->each(static fn (Crawler $node): string => trim((string) preg_replace('/\s+\d+$/', '', trim($node->text()))));
    }

    /**
     * @return list<string>
     */
    private function sitemap(KernelBrowser $client): array
    {
        $client->request('GET', '/sitemap.xml');
        $urls = [];
        foreach (new \SimpleXMLElement((string) $client->getResponse()->getContent())->url as $url) {
            $urls[] = (string) $url->loc;
        }

        return $urls;
    }
}
