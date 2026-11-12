<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Delivery\Entity\DeliveryPoint;
use App\Shop\Entity\SearchStat;
use App\Shop\Entity\Shop;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\SearchOutcome;
use App\Shop\Enum\ShopCategory;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The producer directory beyond the plain search (ProducerSearchTest): opt-in, categories,
 * filters, labels, home delivery zones, town pages, sitemap and the sober search counter.
 */
final class DirectoryTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    /** Place du Capitole, Toulouse. */
    private const float LAT = 43.6045;
    private const float LNG = 1.4440;

    public function testOnlyShopsThatOptedInWithACategoryAreListed(): void
    {
        $client = self::createClient();
        $this->producer('Inscrite');
        $this->producer('Pas inscrite', ['listedInSearch' => false]);
        $this->producer('Sans catégorie', ['categories' => []]);

        self::assertSame(['Inscrite'], $this->names($this->search($client)));
    }

    public function testResultsSayWhatEachShopSellsAndItsLabels(): void
    {
        $client = self::createClient();
        $shop = $this->producer('Ferme du Touch', ['categories' => [ShopCategory::Vegetables, ShopCategory::EggsPoultry]]);
        ProductFactory::createOne(['shop' => $shop, 'labels' => ['Bio', 'Label Rouge'], 'position' => 1]);
        ProductFactory::createOne(['shop' => $shop, 'labels' => ['bio', 'AOP'], 'position' => 2]);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'labels' => ['Demeter']]);

        $card = $this->search($client)->filter('[data-result]');

        self::assertSame('Légumes, Œufs et volailles', $card->filter('[data-categories]')->text());
        self::assertSame(['Bio', 'Label Rouge', 'AOP'], $card->filter('[data-labels] li')->each(static fn (Crawler $node): string => $node->text()));
    }

    public function testTheCategoryFilterNarrowsTheListDown(): void
    {
        $client = self::createClient();
        $this->producer('Fournil', ['categories' => [ShopCategory::Bread]]);
        $this->producer('Maraîcher', ['categories' => [ShopCategory::Vegetables, ShopCategory::Fruits]]);

        self::assertSame(['Maraîcher'], $this->names($this->search($client, ['categorie' => 'fruits'])));
        self::assertSelectorExists('[data-search-filters][open] option[value="fruits"][selected]');
        self::assertSelectorTextContains('#resultats', 'avec tes filtres');
        // An unknown category is ignored.
        self::assertSame(['Fournil', 'Maraîcher'], $this->names($this->search($client, ['categorie' => 'licornes'])));
        self::assertSelectorExists('[data-search-filters]:not([open])');
    }

    public function testTheOrganicFilterReadsTheLabelsOfActiveProducts(): void
    {
        $client = self::createClient();
        ProductFactory::createOne(['shop' => $this->producer('Bio'), 'labels' => ['Bio']]);
        ProductFactory::createOne(['shop' => $this->producer('AB'), 'labels' => ['AB']]);
        ProductFactory::createOne(['shop' => $this->producer('Agriculture biologique'), 'labels' => ['Agriculture biologique (certifiée)']]);
        ProductFactory::createOne(['shop' => $this->producer('Biodynamie'), 'labels' => ['Biodynamie']]);
        ProductFactory::new()->archived()->create(['shop' => $this->producer('Ancien bio'), 'labels' => ['Bio']]);
        $this->producer('Sans label');

        $names = $this->names($this->search($client, ['bio' => '1']));
        sort($names);

        self::assertSame(['AB', 'Agriculture biologique', 'Bio'], $names);
        self::assertSelectorExists('input[name="bio"][checked]');
    }

    public function testThePaymentFiltersReadTheAcceptedMeansOfPayment(): void
    {
        $client = self::createClient();
        $this->producer('Chèques alimentaires', ['paymentMethods' => [PaymentMethod::Cash, PaymentMethod::FoodVoucher]]);
        $this->producer('Monnaie locale', ['paymentMethods' => [PaymentMethod::LocalCurrency]]);
        $this->producer('Les deux', ['paymentMethods' => [PaymentMethod::FoodVoucher, PaymentMethod::LocalCurrency]]);
        $this->producer('Espèces', ['paymentMethods' => [PaymentMethod::Cash]]);

        self::assertSame(['Chèques alimentaires', 'Les deux'], $this->names($this->search($client, ['cheques-alimentaires' => '1'])));
        self::assertSame(['Les deux', 'Monnaie locale'], $this->names($this->search($client, ['monnaie-locale' => '1'])));
        self::assertSame(['Les deux'], $this->names($this->search($client, ['monnaie-locale' => '1', 'cheques-alimentaires' => '1'])));
        self::assertSelectorExists('input[name="monnaie-locale"][checked]');
        self::assertSelectorExists('input[name="cheques-alimentaires"][checked]');
        // A flag only counts with the value 1.
        self::assertCount(4, $this->names($this->search($client, ['monnaie-locale' => 'oui'])));
    }

    public function testNoResultWithFiltersOffersToClearThem(): void
    {
        $client = self::createClient();
        $this->producer('Fournil', ['categories' => [ShopCategory::Bread]]);

        $this->search($client, ['categorie' => 'meat', 'bio' => '1'], 10);

        self::assertSelectorTextContains('[data-empty="empty"]', 'Aucun producteur ne correspond à tes filtres');
        // The position is rounded to three decimals (SearchCriteria).
        self::assertSelectorExists('[data-clear-filters][href="/trouver-un-producteur?lat=43.605&lng=1.444&rayon=10#resultats"]');
        self::assertSelectorNotExists('[data-widen-radius]');
    }

    public function testWideningTheRadiusKeepsTheFilters(): void
    {
        FakeBan::knows('Brest', 48.3904, -4.4861, city: 'Brest', postcode: '29200');
        $client = self::createClient();

        $client->request('GET', '/trouver-un-producteur?ou=Brest&rayon=10&monnaie-locale=1');
        self::assertSelectorExists('[data-clear-filters]');

        $client->request('GET', '/trouver-un-producteur?ou=Brest&rayon=10');
        self::assertSelectorExists('[data-widen-radius][href="/trouver-un-producteur?ou=Brest&rayon=50#resultats"]');
        self::assertSelectorTextContains('[data-empty="empty"]', 'Aucun producteur de l\'annuaire à moins de 10 km');
    }

    public function testAPointNearTheEdgeOfTheRadiusIsFoundInAnyDirection(): void
    {
        $client = self::createClient();
        // About 25 km due east and due north of the searched point.
        $this->producer('A l\'est', [], self::LAT, self::LNG + 0.31);
        $this->producer('Au nord', [], self::LAT + 0.225, self::LNG);

        self::assertSame(['A l\'est', 'Au nord'], $this->names($this->search($client)));
        self::assertSame([], $this->names($this->search($client, [], 10)));
    }

    public function testAHomeDeliveryZoneMakesTheShopFoundInsideIt(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => 'Paniers à domicile', 'slug' => 'paniers-a-domicile']);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);
        // 15 km around Blagnac (6 km from the searched point).
        DeliveryPointFactory::new()->home()->located(43.6363, 1.3906)->create(['shop' => $shop, 'zoneTown' => 'Blagnac', 'zoneRadiusKm' => 15]);
        $this->producer('Halle proche', [], 43.6100, 1.4500);

        $crawler = $this->search($client, [], 10);

        self::assertSame(['Paniers à domicile', 'Halle proche'], $this->names($crawler), 'Inside the zone: distance 0, first.');
        self::assertStringContainsString('livre chez toi · Livraison à domicile autour de Blagnac', $crawler->filter('[data-distance]')->eq(0)->text());

        // The map only shows fixed points.
        $results = json_decode((string) $crawler->filter('[data-controller="producer-map"]')->attr('data-producer-map-results-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($results);
        self::assertTrue(array_is_list($results), 'A JSON array for the map script.');
        self::assertSame(['halle-proche'], array_column($results, 'slug'));

        // Outside the zone (Muret, 23 km from Blagnac): not found.
        $client->request('GET', '/trouver-un-producteur?lat=43.4615&lng=1.3266&rayon=10');
        self::assertSelectorNotExists('[data-result="paniers-a-domicile"]');
    }

    public function testAHomeDeliveryWithoutZoneOrCoordinatesIsNotFound(): void
    {
        $client = self::createClient();
        foreach (['Sans zone' => [], 'Zone non localisée' => ['zoneTown' => 'Inconnue', 'zoneRadiusKm' => 30]] as $name => $zone) {
            $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name]);
            ShopSubscriptionFactory::createOne(['shop' => $shop]);
            $point = DeliveryPointFactory::new()->home()->create(['shop' => $shop, ...$zone]);
            if ([] === $zone) {
                $point->setCoordinates(self::LAT, self::LNG);
                self::getContainer()->get(EntityManagerInterface::class)->flush();
            }
        }
        $archived = ShopFactory::new()->listed()->verified()->create(['name' => 'Zone archivée']);
        ShopSubscriptionFactory::createOne(['shop' => $archived]);
        DeliveryPointFactory::new()->home()->located(self::LAT, self::LNG)->create(['shop' => $archived, 'zoneTown' => 'Toulouse', 'zoneRadiusKm' => 30, 'archived' => true]);

        $this->search($client);

        self::assertSelectorNotExists('[data-result]');
    }

    public function testSearchesAreCountedPerDepartmentAndOutcomeWithoutAnythingElse(): void
    {
        FakeBan::knows('Toulouse', self::LAT, self::LNG, city: 'Toulouse', postcode: '31000');
        FakeBan::knows('Saint-Denis', -20.8789, 55.4481, city: 'Saint-Denis', postcode: '97400');
        $client = self::createClient();
        $this->producer('Ferme de Blagnac');

        $client->request('GET', '/trouver-un-producteur?ou=Toulouse');
        $client->request('GET', '/trouver-un-producteur?ou=Toulouse&categorie=meat');
        $client->request('GET', '/trouver-un-producteur?ou=Toulouse');
        $client->request('GET', '/trouver-un-producteur?ou=Saint-Denis');
        $client->request('GET', '/trouver-un-producteur?ou=26999');
        $client->request('GET', '/trouver-un-producteur?ou=Xyzzyville');
        // Only a whole postcode gives a department.
        $client->request('GET', '/trouver-un-producteur?ou='.rawurlencode(' 07999 '));
        $client->request('GET', '/trouver-un-producteur?ou=x84999');
        $client->request('GET', '/trouver-un-producteur?ou=133333');
        $client->request('GET', \sprintf('/trouver-un-producteur?lat=%s&lng=%s', self::LAT, self::LNG));
        // Idle page, town pages: no search.
        $client->request('GET', '/trouver-un-producteur');
        FakeBan::down();
        $client->request('GET', '/trouver-un-producteur?ou=Ailleurs');

        self::assertSame([
            '07|not_found' => 1,
            '26|not_found' => 1,
            '31|empty' => 1,
            '31|results' => 2,
            '974|empty' => 1,
            '|not_found' => 3,
            '|results' => 1,
        ], $this->counts());
    }

    public function testATownPageListsTheProducersAroundItAndIsIndexable(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', [], 43.6363, 1.3906, "Marché couvert\n31700 Blagnac");
        $this->producer('Toulousaine', [], self::LAT, self::LNG, "Place du Capitole\n31000 Toulouse");
        $this->producer('Masquée', ['listedInSearch' => false], 43.6370, 1.3900, "Halle\n31700 Blagnac");

        $crawler = $client->request('GET', '/trouver-un-producteur');
        self::assertSame(['Blagnac (31) 1', 'Toulouse (31) 1'], $crawler->filter('[data-communes] a')->each(static fn (Crawler $node): string => trim((string) preg_replace('/\s+/', ' ', $node->text()))));
        self::assertSame('/trouver-un-producteur/blagnac-31', $crawler->filter('[data-communes] a')->eq(0)->attr('href'));

        $crawler = $client->request('GET', '/trouver-un-producteur/blagnac-31');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('meta[name="robots"]');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/trouver-un-producteur/blagnac-31"]');
        self::assertSelectorTextContains('title', 'Producteurs à Blagnac et alentours');
        self::assertSelectorExists('meta[name="description"][content^="2 producteurs près de Blagnac (31)"]');
        self::assertSelectorTextContains('h1', 'Producteurs à Blagnac et alentours');
        self::assertSame(['Ferme de Blagnac', 'Toulousaine'], $this->names($crawler), 'Within 10 km, the nearest first.');
        self::assertSame('/ferme-de-blagnac?via=recherche', $crawler->filter('[data-result]')->eq(0)->attr('href'));
        self::assertSame([], $this->counts(), 'A town page is not a search.');
    }

    public function testATownWithoutAShopOfTheDirectoryHasNoPage(): void
    {
        $client = self::createClient();
        $this->producer('Masquée', ['listedInSearch' => false], 43.6363, 1.3906, "Halle\n31700 Blagnac");
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => 'Résiliée']);
        ShopSubscriptionFactory::new()->canceled()->create(['shop' => $shop]);
        DeliveryPointFactory::new()->located(43.6, 1.44)->create(['shop' => $shop, 'address' => "Place\n31000 Toulouse"]);

        foreach (['/trouver-un-producteur/blagnac-31', '/trouver-un-producteur/toulouse-31', '/trouver-un-producteur/nulle-part-99'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $crawler = $client->request('GET', '/trouver-un-producteur');
        self::assertSelectorNotExists('[data-communes]');
        self::assertCount(0, $crawler->filter('[data-communes] a'));
    }

    public function testTheSitemapListsOnlyWhatMayBeIndexed(): void
    {
        $client = self::createClient();
        $this->producer('Ferme de Blagnac', [], 43.6363, 1.3906, "Marché couvert\n31700 Blagnac");
        $this->producer('Masquée', ['listedInSearch' => false], 43.6363, 1.3906, "Halle\n31700 Blagnac");
        $this->producer('Abeilles', [], 43.6370, 1.3900, "Halle\n31700 Blagnac");

        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = new \SimpleXMLElement((string) $client->getResponse()->getContent());
        $urls = [];
        foreach ($xml->url as $url) {
            $urls[] = (string) $url->loc;
        }

        self::assertSame([
            'http://localhost/',
            'http://localhost/trouver-un-producteur',
            'http://localhost/mentions-legales',
            'http://localhost/cgu',
            'http://localhost/confidentialite',
            'http://localhost/engagements',
            'http://localhost/trouver-un-producteur/blagnac-31',
            'http://localhost/abeilles',
            'http://localhost/ferme-de-blagnac',
        ], $urls);
    }

    public function testTheListsOfAShopAreStoredAsJsonArrays(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $shop = new Shop('Doublons', 'doublons')
            ->setCategories([ShopCategory::Bread, ShopCategory::Bread, ShopCategory::Other])
            ->setPaymentMethods([PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::Cheque]);
        $entityManager->persist($shop);
        $entityManager->flush();

        self::assertSame(
            ['categories' => 'array', 'payment_methods' => 'array', 'c' => '["bread", "other"]'],
            $entityManager->getConnection()->fetchAssociative("SELECT jsonb_typeof(categories) AS categories, jsonb_typeof(payment_methods) AS payment_methods, categories::text AS c FROM shop WHERE slug = 'doublons'"),
        );
    }

    /**
     * A listed producer (bread, trial running) with one located meeting point.
     *
     * @param array<string, mixed> $attributes
     */
    private function producer(string $name, array $attributes = [], float $latitude = 43.6363, float $longitude = 1.3906, string $address = "Place du marché\n31700 Blagnac"): Shop
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)), '-'));
        $shop = ShopFactory::new()->listed()->verified()->create(['name' => $name, 'slug' => $slug, ...$attributes]);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::new()->located($latitude, $longitude)->create(['shop' => $shop, 'name' => 'Place du marché', 'address' => $address]);

        return $shop;
    }

    /**
     * @param array<string, string> $filters
     */
    private function search(KernelBrowser $client, array $filters = [], int $radius = 30): Crawler
    {
        $crawler = $client->request('GET', '/trouver-un-producteur?'.http_build_query(['lat' => self::LAT, 'lng' => self::LNG, 'rayon' => $radius, ...$filters]));
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
     * @return array<string, int> "department|outcome" => searches
     */
    private function counts(): array
    {
        $counts = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getRepository(SearchStat::class)->findAll() as $stat) {
            self::assertEquals(new \DateTimeImmutable('today'), $stat->getDay());
            $counts[$stat->getDepartment().'|'.$stat->getOutcome()->value] = $stat->getSearches();
        }
        ksort($counts);

        return $counts;
    }

    public function testTheTownOfAPointGivesItsDirectorySlug(): void
    {
        self::assertSame('saint-peray-07', DeliveryPoint::communeSlugOf('Saint-Péray', '07130'));
        self::assertSame('saint-denis-974', DeliveryPoint::communeSlugOf('Saint-Denis', '97400'));
        self::assertSame(SearchOutcome::NotFound, SearchOutcome::from('not_found'));
    }
}
