<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\DataFixtures\AppFixtures;
use App\Shop\Entity\Shop;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Zenstruck\Foundry\Test\Factories;

final class ProducerSearchTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    /** Place du Capitole, Toulouse. */
    private const float LAT = 43.6045;
    private const float LNG = 1.4440;

    public function testTheDirectoryInvitesToSearchAndSaysHowItRanks(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/trouver-un-producteur');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'près de chez toi');
        self::assertSelectorExists('form[data-search-form][method="get"] input[name="ou"]');
        self::assertSelectorExists('form[data-search-form] select[name="rayon"] option[value="30"][selected]');
        self::assertSelectorExists('[data-geolocate-target="button"][hidden]');
        self::assertSelectorNotExists('#resultats');
        self::assertCount(3, $crawler->filter('[data-how-it-works] > li'));
        self::assertSelectorTextContains('[data-how-it-works]', 'règles directement au producteur');
        self::assertSelectorNotExists('meta[name="robots"]');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/trouver-un-producteur"]');
        self::assertSelectorTextContains('[data-directory-transparency]', 'Producteurs qui utilisent Panier Sauvage et ont choisi d\'apparaître ici, classés uniquement par distance. Aucune mise en avant payée.');
        self::assertSelectorExists('[data-pro-teaser] a[href="/"]');
        self::assertSelectorExists('[data-search-filters]:not([open]) select[name="categorie"]');
        self::assertSame([], FakeBan::queries());
    }

    public function testSearchingATownListsTheNearestProducersFirst(): void
    {
        FakeBan::knows('Toulouse', self::LAT, self::LNG, city: 'Toulouse', postcode: '31000');
        $client = self::createClient();
        $this->producer('Fournil de Muret', 43.4615, 1.3266, "Place de la mairie\n31600 Muret");
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906, "Marché couvert\n31700 Blagnac");
        $this->producer('Vergers de Montauban', 44.0176, 1.3550);

        $crawler = $client->request('GET', '/trouver-un-producteur?ou=Toulouse');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertSelectorTextContains('#resultats-titre', '2 producteurs autour de Toulouse');
        self::assertSame(['Ferme de Blagnac', 'Fournil de Muret'], $this->names($crawler));
        self::assertStringContainsString('à 6 km · Place du marché, Blagnac', $crawler->filter('[data-result] [data-distance]')->eq(0)->text());
        self::assertStringContainsString('à 19 km · Place du marché, Muret', $crawler->filter('[data-result] [data-distance]')->eq(1)->text());
        self::assertSame('/ferme-de-blagnac?via=recherche', $crawler->filter('[data-result]')->eq(0)->attr('href'));
        self::assertSame('Toulouse', $crawler->filter('input[name="ou"]')->attr('value'));
        self::assertSame(['Toulouse'], FakeBan::queries());
    }

    public function testTheRadiusWidensOrNarrowsTheSearch(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);
        $this->producer('Fournil de Muret', 43.4615, 1.3266);
        $this->producer('Vergers de Montauban', 44.0176, 1.3550);
        $this->producer('Fromagerie d\'Albi', 43.9289, 2.1464);

        self::assertSame(['Ferme de Blagnac'], $this->names($this->searchAround($client, 10)));
        self::assertSame(['Ferme de Blagnac', 'Fournil de Muret'], $this->names($this->searchAround($client, 30)));
        self::assertSame(['Ferme de Blagnac', 'Fournil de Muret', 'Vergers de Montauban'], $this->names($this->searchAround($client, 50)));
        self::assertSelectorExists('select[name="rayon"] option[value="50"][selected]');

        // Any other radius falls back to 30 km.
        self::assertSame(['Ferme de Blagnac', 'Fournil de Muret'], $this->names($this->searchAround($client, 500)));
    }

    public function testSearchingByPositionNeverCallsTheGeocoderAndKeepsThePositionInTheForm(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);

        $crawler = $client->request('GET', '/trouver-un-producteur?lat=43.60451234&lng=1.44400987&rayon=30');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#resultats-titre', '1 producteur autour de toi');
        self::assertSame(['Ferme de Blagnac'], $this->names($crawler));
        self::assertSame([], FakeBan::queries());
        // Rounded to about a hundred metres.
        self::assertSame('43.605', $crawler->filter('input[name="lat"]')->attr('value'));
        self::assertNull($crawler->filter('input[name="lat"]')->attr('disabled'));
    }

    public function testAProducerAppearsOnceAtTheDistanceOfItsNearestPoint(): void
    {
        $client = self::createClient();
        $shop = $this->producer('Maraîcher des deux rives', 43.4615, 1.3266);
        DeliveryPointFactory::new()->located(43.6363, 1.3906)->create(['shop' => $shop, 'name' => 'Arrêt de Blagnac']);
        DeliveryPointFactory::new()->located(self::LAT, self::LNG)->create(['shop' => $shop, 'name' => 'Ancien arrêt', 'archived' => true]);

        $crawler = $this->searchAround($client, 30);

        self::assertSame(['Maraîcher des deux rives'], $this->names($crawler));
        self::assertStringContainsString('Arrêt de Blagnac', $crawler->filter('[data-distance]')->text());
    }

    public function testOnlyVisibleProducersWithAnActiveSubscriptionAndALocatedFixedPointAreListed(): void
    {
        $client = self::createClient();
        $this->producer('Visible', 43.6363, 1.3906);
        $this->producer('Abonnée', 43.6363, 1.3906, subscription: ShopSubscriptionFactory::new()->active());
        $this->producer('Masquée', 43.6363, 1.3906, attributes: ['listedInSearch' => false]);
        $this->producer('Essai terminé', 43.6363, 1.3906, subscription: ShopSubscriptionFactory::new()->trialExpired());
        $this->producer('Résiliée', 43.6363, 1.3906, subscription: ShopSubscriptionFactory::new()->canceled());

        $withoutSubscription = ShopFactory::createOne(['name' => 'Sans abonnement']);
        DeliveryPointFactory::new()->located(43.6363, 1.3906)->create(['shop' => $withoutSubscription]);

        $homeOnly = ShopFactory::createOne(['name' => 'Domicile seulement']);
        ShopSubscriptionFactory::createOne(['shop' => $homeOnly]);
        DeliveryPointFactory::new()->home()->located(43.6363, 1.3906)->create(['shop' => $homeOnly]);

        $notLocated = ShopFactory::createOne(['name' => 'Adresse introuvable']);
        ShopSubscriptionFactory::createOne(['shop' => $notLocated]);
        DeliveryPointFactory::createOne(['shop' => $notLocated, 'address' => 'Lieu-dit inconnu']);

        $archivedOnly = ShopFactory::createOne(['name' => 'Point archivé']);
        ShopSubscriptionFactory::createOne(['shop' => $archivedOnly]);
        DeliveryPointFactory::new()->located(43.6363, 1.3906)->create(['shop' => $archivedOnly, 'archived' => true]);

        $names = $this->names($this->searchAround($client, 30));
        sort($names);

        self::assertSame(['Abonnée', 'Visible'], $names);
    }

    public function testEachProducerShowsItsNextSale(): void
    {
        $client = self::createClient();
        $open = $this->producer('Commandes ouvertes', 43.6363, 1.3906);
        SaleFactory::createOne(['shop' => $open, 'deadlineAt' => new \DateTimeImmutable('2030-01-04 20:00'), 'deliveryDate' => new \DateTimeImmutable('2030-01-07')]);
        SaleFactory::new()->scheduled()->create(['shop' => $open]);

        $scheduled = $this->producer('Bientôt', 43.6400, 1.3906);
        SaleFactory::new()->scheduled()->create(['shop' => $scheduled, 'opensAt' => new \DateTimeImmutable('2030-02-01 08:00'), 'deliveryDate' => new \DateTimeImmutable('2030-02-11')]);
        SaleFactory::new()->scheduled()->create(['shop' => $scheduled, 'opensAt' => new \DateTimeImmutable('2030-02-08 08:00'), 'deadlineAt' => new \DateTimeImmutable('2030-02-12 20:00'), 'deliveryDate' => new \DateTimeImmutable('2030-02-18')]);
        SaleFactory::new()->closed()->create(['shop' => $scheduled]);

        $nothing = $this->producer('Au repos', 43.6450, 1.3906);
        // Still open, but its deadline has passed: no longer takes orders.
        SaleFactory::createOne(['shop' => $nothing, 'deadlineAt' => new \DateTimeImmutable('-1 hour')]);

        $crawler = $this->searchAround($client, 30);

        $sales = [];
        foreach ($crawler->filter('[data-result]') as $node) {
            $card = new Crawler($node);
            $sales[$card->filter('h3')->text()] = str_replace("\u{a0}", ' ', $card->filter('[data-next-sale]')->text());
        }

        self::assertStringContainsString('Commandes ouvertes jusqu\'au vendredi 4 janvier à 20 h', $sales['Commandes ouvertes']);
        self::assertStringContainsString('Livraison lundi 7 janvier', $sales['Commandes ouvertes']);
        self::assertStringContainsString('Prochaine vente le vendredi 1er février', $sales['Bientôt']);
        self::assertStringContainsString('Livraison lundi 11 février', $sales['Bientôt']);
        self::assertStringContainsString('Pas de vente prévue', $sales['Au repos']);
    }

    public function testThePageRunsTheSameNumberOfQueriesWhateverTheNumberOfResults(): void
    {
        $client = self::createClient();
        $first = $this->producer('Premier', 43.6363, 1.3906);
        SaleFactory::createOne(['shop' => $first]);
        // A first request boots a fresh kernel: the inserts of the factories are not counted.
        $client->request('GET', '/trouver-un-producteur');
        $one = $this->countQueries($client, '/trouver-un-producteur?lat=43.6045&lng=1.444&rayon=10');

        foreach (['Deuxième', 'Troisième', 'Quatrième'] as $index => $name) {
            $shop = $this->producer($name, 43.6363 + $index / 1000, 1.3906);
            SaleFactory::createOne(['shop' => $shop]);
            SaleFactory::new()->scheduled()->create(['shop' => $shop]);
        }
        $four = $this->countQueries($client, '/trouver-un-producteur?lat=43.6045&lng=1.444&rayon=10');

        self::assertSelectorCount(4, '[data-result]');
        self::assertSame($one, $four);
        self::assertSame(4, $four, 'One query for the shops and their distance, one for their next sales, one for their labels, one to count the search.');
    }

    public function testTheDemoDataSetIsFoundWithoutCallingTheGeocoder(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $container->get(AppFixtures::class)->load($container->get(EntityManagerInterface::class));
        self::assertSame([], FakeBan::queries(), 'The demo points carry their coordinates.');

        FakeBan::knows('Saoû', 44.6489, 5.0617, city: 'Saoû', postcode: '26400');
        $crawler = $client->request('GET', '/trouver-un-producteur?ou=Saoû');
        self::assertSame(['Fournil du Pont', 'Les Jardins de la Gervanne'], $this->names($crawler));
        self::assertStringContainsString('Commandes ouvertes', $crawler->filter('[data-next-sale]')->eq(0)->text());

        FakeBan::knows('Toulouse', self::LAT, self::LNG, city: 'Toulouse', postcode: '31000');
        $crawler = $client->request('GET', '/trouver-un-producteur?ou=Toulouse');
        self::assertSame(['Fromagerie du Capitole', 'Le Rucher des Coteaux'], $this->names($crawler));
        self::assertStringContainsString('Prochaine vente le', $crawler->filter('[data-next-sale]')->eq(1)->text());
    }

    public function testTheResultsPageAddsNoInlineScriptNorExternalConnection(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);
        $crawler = $this->searchAround($client, 30);

        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("connect-src 'self';", $policy);
        if (1 !== preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $policy, $matches)) {
            self::fail('script-src has no nonce.');
        }
        foreach ($crawler->filter('script') as $script) {
            self::assertInstanceOf(\DOMElement::class, $script);
            self::assertSame($matches[1], $script->getAttribute('nonce'));
        }
        self::assertSelectorExists('form[data-controller="geolocate"]');
    }

    public function testAnUnknownPlaceSaysSo(): void
    {
        $client = self::createClient();
        $client->request('GET', '/trouver-un-producteur?ou=Xyzzyville');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-empty="not_found"]', 'On ne trouve pas ce lieu');
    }

    public function testInvalidCoordinatesAreTreatedAsAnUnknownPlace(): void
    {
        $client = self::createClient();
        $client->request('GET', '/trouver-un-producteur?lat=abc&lng=1.44');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-empty="not_found"]');
    }

    public function testAnUnavailableGeocoderSaysSoWithoutBreakingThePage(): void
    {
        FakeBan::down();
        $client = self::createClient();
        $client->request('GET', '/trouver-un-producteur?ou=Toulouse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-empty="unavailable"]', 'La recherche fait une pause');
    }

    public function testNoProducerNearbySuggestsAWiderRadius(): void
    {
        FakeBan::knows('Brest', 48.3904, -4.4861, city: 'Brest');
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', 43.6363, 1.3906);

        $client->request('GET', '/trouver-un-producteur?ou=Brest&rayon=10');

        self::assertSelectorTextContains('[data-empty="empty"]', 'Pas encore de producteur autour de Brest');
        self::assertSelectorExists('[data-widen-radius][href="/trouver-un-producteur?ou=Brest&rayon=50#resultats"]');

        $client->request('GET', '/trouver-un-producteur?ou=Brest&rayon=50');
        self::assertSelectorExists('[data-empty="empty"]');
        self::assertSelectorNotExists('[data-widen-radius]');
    }

    public function testSearchesAreRateLimitedPerIp(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        for ($i = 0; $i < 30; ++$i) {
            $client->request('GET', '/trouver-un-producteur?lat=43.6&lng=1.44');
            self::assertResponseIsSuccessful();
        }

        $client->request('GET', '/trouver-un-producteur?lat=43.6&lng=1.44');
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[data-empty="rate_limited"]', 'Réessaie dans quelques minutes');

        // The home page itself stays available.
        $client->request('GET', '/trouver-un-producteur');
        self::assertResponseIsSuccessful();
    }

    /**
     * A listed producer (trial running by default) with one located meeting point.
     *
     * @param array<string, mixed> $attributes
     */
    private function producer(string $name, float $latitude, float $longitude, ?string $address = null, array $attributes = [], ?ShopSubscriptionFactory $subscription = null): Shop
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)), '-'));
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name, 'slug' => $slug, ...$attributes]);
        ($subscription ?? ShopSubscriptionFactory::new())->create(['shop' => $shop]);
        DeliveryPointFactory::new()->located($latitude, $longitude)->create(['shop' => $shop, 'name' => 'Place du marché', 'address' => $address ?? "Place du marché\n31000 Toulouse"]);

        return $shop;
    }

    private function searchAround(KernelBrowser $client, int $radius): Crawler
    {
        $crawler = $client->request('GET', \sprintf('/trouver-un-producteur?lat=%s&lng=%s&rayon=%d', self::LAT, self::LNG, $radius));
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

    private function countQueries(KernelBrowser $client, string $url): int
    {
        $client->enableProfiler();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
