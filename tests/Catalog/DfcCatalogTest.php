<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Dfc\DfcCatalogExporter;
use App\Catalog\Dfc\DfcVocabulary;
use App\Catalog\Entity\CatalogPublication;
use App\Catalog\Enum\ProductUnit;
use App\Catalog\Repository\CatalogPublicationRepository;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Enum\VatRegime;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Catalogue export in the Data Food Consortium format (JSON-LD, ontology 1.16): structure, content,
 * and the opt-in public URL.
 */
final class DfcCatalogTest extends WebTestCase
{
    use Factories;
    use FakeBanTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheGraphDescribesTheShopItsProductsOffersSalesAndPickupPoints(): void
    {
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $shop->setDescription('Pain au levain, cuit au feu de bois.')->setAddress('Le Moulin, 26000 Valence');
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550, 'labels' => ['AB – Agriculture biologique', 'Fait maison', 'Bio'], 'allergens' => 'Gluten', 'photoPath' => 'uploads/products/pain.webp']);
        $cheese = ProductFactory::createOne(['shop' => $shop, 'name' => 'Tomme', 'priceCents' => 2450, 'vatRateBp' => 550, 'unit' => ProductUnit::Kg, 'labels' => ['Label Rouge']]);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Ancien pain']);
        $place = DeliveryPointFactory::new()->located(44.9334, 4.8924)->create(['shop' => $shop, 'name' => 'Place du village', 'address' => '1 place de la Mairie, 26000 Valence', 'instructions' => 'Sous la halle']);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop]);
        $sale = SaleFactory::createOne(['shop' => $shop, 'opensAt' => new \DateTimeImmutable('2026-11-09 08:00'), 'deadlineAt' => new \DateTimeImmutable('2026-11-13 20:00')]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => $bread, 'priceCents' => 500, 'maxQty' => 10000, 'reservedQty' => 2500]);
        SaleOfferFactory::createOne(['sale' => $sale, 'product' => $cheese]);
        SaleFactory::new()->done()->create(['shop' => $shop]);
        // Another shop's products never appear.
        ProductFactory::createOne(['name' => 'Miel d\'ailleurs']);

        $document = self::getContainer()->get(DfcCatalogExporter::class)->export($shop);
        $origin = $this->origin();
        $base = $origin.'/'.$shop->getSlug().'/catalogue.jsonld#';

        self::assertSame('https://w3id.org/dfc/ontology/context/context_1.16.0.json', $document['@context']);
        $nodes = $this->index($document['@graph']);
        self::assertSame(
            ['dfc-b:Address' => 2, 'dfc-b:Catalog' => 1, 'dfc-b:CatalogItem' => 2, 'dfc-b:Enterprise' => 1, 'dfc-b:Offer' => 4, 'dfc-b:PhysicalPlace' => 1, 'dfc-b:PickupOption' => 1, 'dfc-b:SaleSession' => 1, 'dfc-b:SuppliedProduct' => 2],
            $this->countTypes($document['@graph']),
            'A home delivery zone, the archived product, the finished sale and other shops are left out.',
        );

        $enterprise = $nodes[$base.'enterprise'];
        self::assertSame($shop->getName(), $enterprise['dfc-b:name']);
        self::assertSame('Pain au levain, cuit au feu de bois.', $enterprise['dfc-b:hasDescription']);
        self::assertSame($origin.'/'.$shop->getSlug(), $enterprise['dfc-b:websitePage']);
        self::assertSame($base.'catalog', $enterprise['dfc-b:maintains']);
        self::assertSame(['Le Moulin, 26000 Valence', DfcVocabulary::FRANCE], [$nodes[self::str($enterprise['dfc-b:hasAddress'])]['dfc-b:hasStreet'], $nodes[self::str($enterprise['dfc-b:hasAddress'])]['dfc-b:hasCountry']]);
        $breadId = $base.'product-'.$bread->getId();
        $cheeseId = $base.'product-'.$cheese->getId();
        self::assertEqualsCanonicalizing([$breadId, $cheeseId], $enterprise['dfc-b:supplies']);
        self::assertSame($nodes[$base.'catalog']['dfc-b:lists'], $enterprise['dfc-b:manages']);
        self::assertSame($base.'enterprise', $nodes[$base.'catalog']['dfc-b:maintainedBy']);

        $product = $nodes[$breadId];
        self::assertSame('Pain de campagne', $product['dfc-b:name']);
        self::assertSame(['dfc-f:Organic-AB', 'dfc-f:Organic-EU'], $product['dfc-b:hasCertification']);
        self::assertSame("Labels : Fait maison\n\nAllergènes : Gluten", $product['dfc-b:description']);
        self::assertSame($origin.'/uploads/products/pain.webp', $product['dfc-b:image']);
        self::assertSame(['@type' => 'dfc-b:QuantitativeValue', 'dfc-b:hasUnit' => 'dfc-m:Piece', 'dfc-b:value' => '1'], $product['dfc-b:hasQuantity']);
        self::assertSame('dfc-m:Kilogram', $this->arr($nodes[$cheeseId]['dfc-b:hasQuantity'])['dfc-b:hasUnit']);
        self::assertSame(['dfc-f:RedLabel_FR'], $nodes[$cheeseId]['dfc-b:hasCertification']);

        $item = $nodes[$base.'catalog-item-'.$bread->getId()];
        self::assertSame([$breadId, (string) $bread->getId(), $base.'catalog', $base.'offer-'.$bread->getId()], [$item['dfc-b:references'], $item['dfc-b:sku'], $item['dfc-b:listedIn'], $item['dfc-b:offeredThrough']]);
        self::assertSame($item['@id'], $product['dfc-b:referencedBy']);

        // Catalogue price: TTC, VAT rate in percent, euros — exact decimals, never a float.
        $catalogOffer = $nodes[$base.'offer-'.$bread->getId()];
        self::assertSame($item['@id'], $catalogOffer['dfc-b:offeredItem']);
        self::assertSame(['@type' => 'dfc-b:Price', 'dfc-b:value' => '5.2', 'dfc-b:VATrate' => '5.5', 'dfc-b:hasUnit' => 'dfc-m:Euro'], $catalogOffer['dfc-b:hasPrice']);
        self::assertArrayNotHasKey('dfc-b:stockLimitation', $catalogOffer);
        self::assertSame('24.5', $this->arr($nodes[$base.'offer-'.$cheese->getId()]['dfc-b:hasPrice'])['dfc-b:value']);

        // The open sale: its dates and its own offers, with the stock still available.
        $session = $nodes[$base.'sale-'.$sale->getId()];
        self::assertSame(['2026-11-09T08:00:00+01:00', '2026-11-13T20:00:00+01:00'], [$session['dfc-b:beginDate'], $session['dfc-b:endDate']]);
        self::assertCount(2, $this->arr($session['dfc-b:lists']));
        $saleOffer = $nodes[$base.'sale-'.$sale->getId().'-offer-'.$offer->getId()];
        self::assertContains($saleOffer['@id'], $this->arr($session['dfc-b:lists']));
        self::assertSame('5', $this->arr($saleOffer['dfc-b:hasPrice'])['dfc-b:value']);
        self::assertSame('7.5', $saleOffer['dfc-b:stockLimitation']);
        self::assertSame($item['@id'], $saleOffer['dfc-b:offeredItem']);

        $physicalPlace = $nodes[$base.'place-'.$place->getId()];
        self::assertSame(['Place du village', 'Sous la halle'], [$physicalPlace['dfc-b:name'], $physicalPlace['dfc-b:description']]);
        $address = $nodes[self::str($physicalPlace['dfc-b:hasAddress'])];
        self::assertSame('1 place de la Mairie, 26000 Valence', $address['dfc-b:hasStreet']);
        self::assertSame(['26000', 'Valence', DfcVocabulary::FRANCE, 44.9334, 4.8924], [$address['dfc-b:hasPostalCode'], $address['dfc-b:hasCity'], $address['dfc-b:hasCountry'], $address['dfc-b:latitude'], $address['dfc-b:longitude']]);
        self::assertSame($physicalPlace['@id'], $nodes[$base.'pickup-'.$place->getId()]['dfc-b:pickedUpAt']);

        // Every reference points to a node of the graph.
        foreach ($document['@graph'] as $node) {
            foreach (['dfc-b:references', 'dfc-b:offeredItem', 'dfc-b:offeredThrough', 'dfc-b:listedIn', 'dfc-b:referencedBy', 'dfc-b:hasAddress', 'dfc-b:pickedUpAt', 'dfc-b:maintains', 'dfc-b:maintainedBy'] as $property) {
                if (isset($node[$property])) {
                    self::assertArrayHasKey(self::str($node[$property]), $nodes, \sprintf('%s of %s', $property, self::str($node['@id'])));
                }
            }
        }
    }

    public function testNothingAboutCustomersOrOrdersLeaves(): void
    {
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $order = OrderFactory::createOne(['sale' => $sale]);

        $json = json_encode(self::getContainer()->get(DfcCatalogExporter::class)->export($shop), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($order->getReference(), $json);
        self::assertStringNotContainsString((string) $order->getCustomer()->getEmail(), $json);
        self::assertStringNotContainsString('dfc-b:Order', $json);
    }

    public function testFranchiseShopsPublishAZeroVatRate(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $shop->setVatRegime(VatRegime::Franchise);
        $product = ProductFactory::createOne(['shop' => $shop, 'priceCents' => 1000, 'vatRateBp' => 2000]);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => $product, 'vatRateBp' => 550]);

        $document = self::getContainer()->get(DfcCatalogExporter::class)->export($shop);
        $nodes = $this->index($document['@graph']);
        $base = $this->origin().'/'.$shop->getSlug().'/catalogue.jsonld#';

        self::assertSame(['10', '0'], $this->price($nodes[$base.'offer-'.$product->getId()]));
        self::assertSame('0', $this->price($nodes[$base.'sale-'.$sale->getId().'-offer-'.$offer->getId()])[1]);
    }

    public function testATaxedSaleOfferKeepsItsOwnRate(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $product = ProductFactory::createOne(['shop' => $shop, 'priceCents' => 405, 'vatRateBp' => 2000]);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => $product, 'priceCents' => 1001, 'vatRateBp' => 1000]);

        $nodes = $this->index(self::getContainer()->get(DfcCatalogExporter::class)->export($shop)['@graph']);
        $base = $this->origin().'/'.$shop->getSlug().'/catalogue.jsonld#';

        self::assertSame(['4.05', '20'], $this->price($nodes[$base.'offer-'.$product->getId()]));
        self::assertSame(['10.01', '10'], $this->price($nodes[$base.'sale-'.$sale->getId().'-offer-'.$offer->getId()]));
    }

    public function testEmptyValuesAreLeftOut(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $shop->setDescription(null)->setAddress('');
        $product = ProductFactory::createOne(['shop' => $shop, 'description' => '', 'allergens' => '', 'labels' => []]);
        $remote = ProductFactory::createOne(['shop' => $shop, 'photoPath' => 'https://images.example.test/miel.jpg', 'description' => 'Miel de lavande']);
        $demo = ProductFactory::createOne(['shop' => $shop, 'photoPath' => '/images/demo/pain.jpg', 'labels' => ['Fait maison', ' Label Rouge '], 'unit' => ProductUnit::Bunch]);
        $point = DeliveryPointFactory::createOne(['shop' => $shop, 'address' => null, 'instructions' => null]);

        $nodes = $this->index(self::getContainer()->get(DfcCatalogExporter::class)->export($shop)['@graph']);
        $origin = $this->origin();
        $base = $origin.'/'.$shop->getSlug().'/catalogue.jsonld#';

        $enterprise = $nodes[$base.'enterprise'];
        foreach (['dfc-b:hasDescription', 'dfc-b:logo', 'dfc-b:hasAddress'] as $key) {
            self::assertArrayNotHasKey($key, $enterprise);
        }
        self::assertArrayNotHasKey($base.'address', $nodes);

        foreach (['dfc-b:description', 'dfc-b:image', 'dfc-b:hasCertification'] as $key) {
            self::assertArrayNotHasKey($key, $nodes[$base.'product-'.$product->getId()]);
        }
        self::assertSame('Miel de lavande', $nodes[$base.'product-'.$remote->getId()]['dfc-b:description']);
        self::assertSame('https://images.example.test/miel.jpg', $nodes[$base.'product-'.$remote->getId()]['dfc-b:image']);
        self::assertSame($origin.'/images/demo/pain.jpg', $nodes[$base.'product-'.$demo->getId()]['dfc-b:image']);
        self::assertSame('Labels : Fait maison', $nodes[$base.'product-'.$demo->getId()]['dfc-b:description']);
        self::assertSame(['dfc-f:RedLabel_FR'], $nodes[$base.'product-'.$demo->getId()]['dfc-b:hasCertification']);
        self::assertSame('dfc-m:Bunch', $this->arr($nodes[$base.'product-'.$demo->getId()]['dfc-b:hasQuantity'])['dfc-b:hasUnit']);

        self::assertArrayNotHasKey('dfc-b:description', $nodes[$base.'place-'.$point->getId()]);
        self::assertSame(['@id', '@type', 'dfc-b:hasCountry'], array_keys($nodes[$base.'place-'.$point->getId().'-address']));
    }

    public function testTheShopAddressIsShared(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $shop->setAddress("Le Moulin\n26000 Valence")->setLogoPath('uploads/logos/moulin.png');

        $nodes = $this->index(self::getContainer()->get(DfcCatalogExporter::class)->export($shop)['@graph']);
        $base = $this->origin().'/'.$shop->getSlug().'/catalogue.jsonld#';

        self::assertSame($base.'address', $nodes[$base.'enterprise']['dfc-b:hasAddress']);
        self::assertSame($this->origin().'/uploads/logos/moulin.png', $nodes[$base.'enterprise']['dfc-b:logo']);
        self::assertSame("Le Moulin\n26000 Valence", $nodes[$base.'address']['dfc-b:hasStreet']);
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return array{mixed, mixed}
     */
    private function price(array $offer): array
    {
        $price = $this->arr($offer['dfc-b:hasPrice']);

        return [$price['dfc-b:value'], $price['dfc-b:VATrate']];
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideLabels(): iterable
    {
        yield 'AB' => ['AB', 'dfc-f:Organic-AB'];
        yield 'Agriculture biologique' => ['Agriculture Biologique', 'dfc-f:Organic-AB'];
        yield 'Bio' => ['bio', 'dfc-f:Organic-EU'];
        yield 'Bio Cohérence' => ['Bio Cohérence', 'dfc-f:Organic-BioCoherence'];
        yield 'Nature & Progrès' => ['Nature & Progrès', 'dfc-f:NatureEtProgres'];
        yield 'Nature et Progrès' => ['Nature et progrès', 'dfc-f:NatureEtProgres'];
        yield 'Demeter' => ['Demeter', 'dfc-f:Demeter'];
        yield 'Label Rouge' => ['Label rouge', 'dfc-f:RedLabel_FR'];
        yield 'AOP' => ['AOP Picodon', 'dfc-f:AOP_EU'];
        yield 'AOC' => ['AOC', 'dfc-f:AOC_FR'];
        yield 'IGP' => ['IGP Ail de la Drôme', 'dfc-f:IGP_EU'];
        yield 'Eurofeuille' => ['Eurofeuille', 'dfc-f:Organic-EU'];
        yield 'not a word prefix' => ['Abricot', null];
        yield 'biodynamie is not bio' => ['Biodynamie', null];
        yield 'unknown' => ['Fait maison', null];
    }

    #[DataProvider('provideLabels')]
    public function testLabelsAreMappedToTheDfcCertifications(string $label, ?string $certification): void
    {
        self::assertSame($certification, DfcVocabulary::certification($label));
    }

    public function testTheProducerDownloadsTheFile(): void
    {
        $producer = UserFactory::createOne();
        ProductFactory::createOne(['shop' => $this->shopOf($producer), 'name' => 'Pain de campagne']);
        $this->client->loginUser($producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/produits');
        self::assertSame('/pro/produits/data-food-consortium', $crawler->filter('[data-dfc-link]')->attr('href'));

        $crawler = $this->client->request('GET', '/pro/produits/data-food-consortium');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Partager mon catalogue');
        self::assertSelectorTextContains('[data-dfc-content]', 'points de retrait');
        self::assertSelectorTextContains('main', 'Jamais tes clients ni leurs commandes.');
        self::assertSelectorExists('[data-dfc-publication="private"]');
        self::assertSelectorTextContains('[data-dfc-url]', 'http://localhost/'.$this->shopOf($producer)->getSlug().'/catalogue.jsonld');

        $this->client->click($crawler->selectLink('Télécharger le fichier (JSON-LD)')->link());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=catalogue-dfc-'.$this->shopOf($producer)->getSlug().'.jsonld', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('"@context": "https://w3id.org/dfc/', (string) $this->client->getResponse()->getContent(), 'Pretty, slashes unescaped.');
        $document = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(DfcVocabulary::CONTEXT, $document['@context']);
        self::assertStringContainsString('"dfc-b:name": "Pain de campagne"', (string) $this->client->getResponse()->getContent());
    }

    public function testThePublicUrlAnswersOnlyOncePublished(): void
    {
        // Another shop, published first: the public URL is found by its slug.
        $neighbour = $this->shopOf(UserFactory::createOne());
        ProductFactory::createOne(['shop' => $neighbour, 'name' => 'Miel du voisin']);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist(new CatalogPublication($neighbour)->setDfcPublic(true, new \DateTimeImmutable()));
        $entityManager->flush();

        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne']);
        $url = '/'.$shop->getSlug().'/catalogue.jsonld';

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($producer, 'producer');
        $crawler = $this->client->request('GET', '/pro/produits/data-food-consortium');
        self::assertSelectorExists('[data-dfc-publication="private"]', 'The neighbour\'s choice is not ours.');
        $this->client->submit($crawler->selectButton('Publier mon catalogue')->form());
        self::assertResponseRedirects('/pro/produits/data-food-consortium', 303);
        $publication = self::getContainer()->get(CatalogPublicationRepository::class)->forShop($shop);
        self::assertTrue($publication->isDfcPublic());
        self::assertNotNull($publication->getChangedAt());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Ton catalogue est publié');
        self::assertSelectorExists('[data-dfc-publication="public"]');

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=UTF-8');
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Pain de campagne', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Miel du voisin', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/pro/produits/data-food-consortium');
        $this->client->submit($crawler->selectButton('Arrêter la publication')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'n\'est plus publié');

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/boutique-inconnue/catalogue.jsonld');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublishingRequiresAValidCsrfToken(): void
    {
        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $this->client->request('POST', '/pro/produits/data-food-consortium/publication', ['_token' => 'forged', 'public' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::getContainer()->get(CatalogPublicationRepository::class)->isDfcPublic($this->shopOf($producer)));
    }

    public function testEachProducerOnlyExportsAndPublishesTheirOwnCatalogue(): void
    {
        $other = UserFactory::createOne();
        ProductFactory::createOne(['shop' => $this->shopOf($other), 'name' => 'Miel du voisin']);
        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $this->client->request('GET', '/pro/produits/data-food-consortium/catalogue.jsonld?shop='.$this->shopOf($other)->getId());
        self::assertStringNotContainsString('Miel du voisin', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/pro/produits/data-food-consortium');
        $token = $crawler->filter('form[action="/pro/produits/data-food-consortium/publication"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/pro/produits/data-food-consortium/publication', ['_token' => $token, 'public' => '1', 'shop' => (string) $this->shopOf($other)->getId()]);

        $repository = self::getContainer()->get(CatalogPublicationRepository::class);
        self::assertTrue($repository->isDfcPublic($this->shopOf($producer)));
        self::assertFalse($repository->isDfcPublic($this->shopOf($other)));
        $this->client->request('GET', '/'.$this->shopOf($other)->getSlug().'/catalogue.jsonld');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'pro_catalog_dfc' => ['GET', '/pro/produits/data-food-consortium'];
        yield 'pro_catalog_dfc_download' => ['GET', '/pro/produits/data-food-consortium/catalogue.jsonld'];
        yield 'pro_catalog_dfc_publish' => ['POST', '/pro/produits/data-food-consortium/publication'];
    }

    #[DataProvider('provideRoutes')]
    public function testRequiresAProducerWithAShop(string $method, string $url): void
    {
        $this->client->request($method, $url);
        self::assertResponseRedirects('/pro/connexion');

        $this->client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $this->client->request($method, $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePublicationDefaultsToPrivate(): void
    {
        $publication = new CatalogPublication(new Shop('Fournil', 'fournil'));
        self::assertFalse($publication->isDfcPublic());
        self::assertNull($publication->getChangedAt());

        $now = new \DateTimeImmutable('2026-11-10 10:00');
        $publication->setDfcPublic(false, $now);
        self::assertNull($publication->getChangedAt(), 'Nothing changed.');
        $publication->setDfcPublic(true, $now);
        self::assertSame($now, $publication->getChangedAt());
    }

    /**
     * @param list<array<string, mixed>> $graph
     *
     * @return array<string, array<string, mixed>>
     */
    private function index(array $graph): array
    {
        $nodes = [];
        foreach ($graph as $node) {
            self::assertIsString($node['@id'] ?? null);
            self::assertArrayNotHasKey($node['@id'], $nodes, 'Node ids are unique.');
            $nodes[$node['@id']] = $node;
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $graph
     *
     * @return array<string, int>
     */
    private function countTypes(array $graph): array
    {
        $types = array_count_values(array_map(static fn (array $node): string => self::str($node['@type']), $graph));
        ksort($types);

        return $types;
    }

    /**
     * Scheme and host of the absolute URLs built outside a request (DEFAULT_URI).
     */
    private function origin(): string
    {
        $url = self::getContainer()->get('router')->generate('home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return rtrim($url, '/');
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private function shopOf(User $producer): Shop
    {
        $shop = $producer->getShop();
        self::assertNotNull($shop);

        return $shop;
    }
}
