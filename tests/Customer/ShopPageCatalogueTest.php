<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * What the shop page says even without an open sale: catalogue, labels, pick-up points and their
 * map, accepted means of payment and the identity of the seller (distance selling).
 */
final class ShopPageCatalogueTest extends WebTestCase
{
    use Factories;

    public function testTheCatalogueIsVisibleWithoutAnOpenSale(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Fournil du Pont', 'categories' => [ShopCategory::Bread, ShopCategory::Grocery]]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain au levain', 'priceCents' => 650, 'labels' => ['Bio', 'Levain naturel'], 'allergens' => 'Gluten', 'position' => 1]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Grand épeautre', 'labels' => ['bio'], 'position' => 2]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('-10 days'), new \DateTimeImmutable('+10 days'))->create(['shop' => $shop, 'name' => 'Pain aux noix', 'labels' => ['AOP']]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('+30 days'))->create(['shop' => $shop, 'name' => 'Galette des rois']);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Ancien pain', 'labels' => ['Demeter']]);
        ProductFactory::createOne(['name' => 'Pain d\'un autre']);

        $crawler = $client->request('GET', '/fournil-du-pont');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-shop-categories]', 'Pain et viennoiseries · Épicerie');
        // Products in their order (the seasonal one has position 0), each label once.
        self::assertSame(['AOP', 'Bio', 'Levain naturel'], $this->texts($crawler->filter('[data-shop-labels] li')));
        self::assertSame(['Pain au levain', 'Grand épeautre'], $this->texts($crawler->filter('[data-catalogue="permanent"] [data-product] p.font-display')));
        self::assertSame(['Pain aux noix'], $this->texts($crawler->filter('[data-catalogue="seasonal"] [data-product] p.font-display')));
        self::assertStringContainsString("6,50\u{a0}€", $crawler->filter('[data-catalogue="permanent"] [data-product]')->eq(0)->text());
        self::assertSame(['Bio', 'Levain naturel'], $this->texts($crawler->filter('[data-catalogue="permanent"] [data-product]')->eq(0)->filter('[data-product-labels] li')));
        self::assertSelectorTextContains('[data-catalogue-next-sale]', 'Pas de vente prévue pour l\'instant.');
    }

    public function testTheCatalogueAnnouncesTheNextSale(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont']);
        ProductFactory::createOne(['shop' => $shop]);
        SaleFactory::new()->scheduled()->create(['shop' => $shop, 'opensAt' => new \DateTimeImmutable('2030-02-01 08:00'), 'deliveryDate' => new \DateTimeImmutable('2030-02-11')]);

        $client->request('GET', '/fournil-du-pont');
        self::assertSelectorTextContains('[data-catalogue-next-sale]', 'Prochaine vente le vendredi 1er février, pour une livraison lundi 11 février.');

        SaleFactory::createOne(['shop' => $shop]);
        $client->request('GET', '/fournil-du-pont');
        self::assertSelectorTextContains('[data-catalogue-next-sale]', 'sur sa page de commande');
    }

    public function testNoCatalogueSectionWithoutProduct(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'fournil-du-pont']);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorNotExists('[data-section="catalogue"]');
        self::assertSelectorNotExists('[data-section="points"]');
        self::assertSelectorNotExists('[data-shop-labels]');
    }

    public function testThePickUpPointsAreListedWithASmallMap(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Fournil du Pont']);
        $square = DeliveryPointFactory::new()->located(44.6464, 5.0624)->create(['shop' => $shop, 'name' => 'Place de l\'église', 'address' => "  Place de l'église\n26400 Saoû", 'instructions' => 'Camionnette verte']);
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Arrêt non localisé', 'address' => 'Lieu-dit']);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop, 'name' => 'À domicile', 'zoneTown' => 'Crest', 'zoneRadiusKm' => 12]);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop, 'name' => 'Chez toi']);
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Ancien point', 'archived' => true]);

        $crawler = $client->request('GET', '/fournil-du-pont');

        $points = $crawler->filter('[data-section="points"] [data-point]');
        // Fixed points then home deliveries, each by name.
        self::assertSame(['Arrêt non localisé', 'Place de l\'église', 'Chez toi', 'À domicile'], $this->texts($points->filter('p.font-semibold')));
        self::assertStringContainsString('26400 Saoû', $points->eq(1)->text());
        self::assertStringContainsString('Camionnette verte', $points->eq(1)->text());
        self::assertStringContainsString('Tu indiques ton adresse en commandant.', $points->eq(2)->text());
        self::assertStringContainsString('Jusqu\'à 12 km autour de Crest.', $points->eq(3)->text());

        $map = $crawler->filter('[data-controller="points-map"]');
        self::assertCount(1, $map);
        self::assertSame(
            [['id' => $square->getId(), 'name' => 'Place de l\'église', 'address' => 'Place de l\'église, 26400 Saoû', 'lat' => 44.6464, 'lng' => 5.0624]],
            json_decode((string) $map->attr('data-points-map-points-value'), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSelectorExists('[data-points-map-target="frame"][hidden]');
        self::assertSelectorExists('link[rel="stylesheet"][href*="leaflet"]');
    }

    public function testNoMapWithoutALocatedFixedPoint(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont']);
        DeliveryPointFactory::new()->home()->located(44.6, 5.0)->create(['shop' => $shop]);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorExists('[data-section="points"]');
        self::assertSelectorNotExists('[data-controller="points-map"]');
        self::assertSelectorNotExists('link[rel="stylesheet"][href*="leaflet"]');
    }

    public function testTheAcceptedMeansOfPaymentAreShownWithTheirDetails(): void
    {
        $client = self::createClient();
        ShopFactory::createOne([
            'slug' => 'fournil-du-pont',
            'paymentMethods' => [PaymentMethod::Cash, PaymentMethod::LocalCurrency, PaymentMethod::FoodVoucher],
            'localCurrencyName' => 'la Mesure',
            'foodVoucherDetails' => 'caisse alimentaire de Dieulefit',
            'paymentInfo' => 'Wero au 06 00 00 00 00',
        ]);

        $crawler = $client->request('GET', '/fournil-du-pont');

        self::assertSame(['Espèces', 'Monnaie locale (la Mesure)', 'Chèques ou caisse alimentaire (caisse alimentaire de Dieulefit)'], $this->texts($crawler->filter('[data-payment-methods] li')));
        self::assertSelectorTextContains('[data-section="payment-info"]', 'Wero au 06 00 00 00 00');
        self::assertSelectorTextContains('[data-section="payment-info"]', 'Rien n\'est payé en ligne.');
    }

    public function testTheMeansOfPaymentAloneAreEnough(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'fournil-du-pont', 'paymentMethods' => [PaymentMethod::Cheque], 'paymentInfo' => null]);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorTextContains('[data-payment-methods]', 'Chèque');
    }

    public function testTheSellerIsIdentifiedAtTheBottomOfThePage(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne([
            'slug' => 'fournil-du-pont',
            'name' => 'Fournil du Pont',
            'legalName' => 'Camille Dupont EI',
            'siret' => '12345678900012',
            'address' => "3 chemin du Pont\n26400 Saoû",
            'phone' => '06 12 34 56 78',
        ]);
        UserFactory::createOne(['shop' => $shop, 'email' => 'camille@example.test']);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorTextSame('[data-seller="name"]', 'Camille Dupont EI');
        self::assertSelectorTextSame('[data-seller="siret"]', '123 456 789 00012');
        self::assertSelectorTextContains('[data-seller="address"]', '26400 Saoû');
        self::assertSelectorExists('a[data-seller="phone"][href="tel:0612345678"]');
        self::assertSelectorExists('a[data-seller="email"][href="mailto:camille@example.test"]');
    }

    public function testTheContactAddressWinsAndMissingDetailsAreLeftOut(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Fournil du Pont', 'contactEmail' => 'bonjour@fournil.test']);
        UserFactory::createOne(['shop' => $shop, 'email' => 'camille@example.test']);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorTextSame('[data-seller="name"]', 'Fournil du Pont');
        self::assertSelectorExists('a[data-seller="email"][href="mailto:bonjour@fournil.test"]');
        self::assertSelectorNotExists('[data-seller="siret"]');
        self::assertSelectorNotExists('[data-seller="phone"]');
        self::assertSelectorNotExists('[data-seller="address"]');
    }

    /**
     * @return list<string>
     */
    private function texts(Crawler $nodes): array
    {
        return $nodes->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
