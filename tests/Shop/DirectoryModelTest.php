<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Catalog\Entity\Product;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Ordering\Enum\OrderSource;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\SearchOutcome;
use App\Shop\Enum\ShopCategory;
use App\Shop\Search\DirectoryCommune;
use App\Shop\Search\NearbyShop;
use App\Shop\Search\SearchCriteria;
use App\Shop\Search\SearchFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * The pieces of the directory that need no database: towns, zones, means of payment, filters, provenance.
 */
final class DirectoryModelTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function addresses(): iterable
    {
        yield 'two lines' => ["Place de l'église\n26400 Saoû", '26400', 'Saoû'];
        yield 'one line' => ['3 rue du Pont, 12000 Rodez', '12000', 'Rodez'];
        yield 'hyphens and spaces' => ["Avenue Jean Jaurès\n26600   Tain-l'Hermitage  ", '26600', "Tain-l'Hermitage"];
        yield 'no postcode' => ['Lieu-dit les Faures', null, null];
        yield 'postcode not at the end' => ['26400 Saoû, place du marché', null, null];
        yield 'empty' => [null, null, null];
    }

    #[DataProvider('addresses')]
    public function testTheTownIsReadFromTheLastLineOfTheAddress(?string $address, ?string $postcode, ?string $town): void
    {
        self::assertSame([$postcode, $town], DeliveryPoint::townOf($address));

        $point = new DeliveryPoint(new Shop('Fournil', 'fournil'))->setAddress($address);
        self::assertSame($town, $point->getCity());
        self::assertSame($postcode, $point->getPostcode());
    }

    public function testDepartmentsAndTownSlugs(): void
    {
        self::assertSame('26', DeliveryPoint::departmentOf('26400'));
        self::assertSame('07', DeliveryPoint::departmentOf('07130'));
        self::assertSame('974', DeliveryPoint::departmentOf('97400'));
        self::assertSame('988', DeliveryPoint::departmentOf('98800'));
        self::assertSame('20', DeliveryPoint::departmentOf('20000'));
        self::assertSame('tain-l-hermitage-26', DeliveryPoint::communeSlugOf("Tain-l'Hermitage", '26600'));
        self::assertSame('saou-26', DeliveryPoint::communeSlugOf('SAOÛ', '26400'));
    }

    public function testTheTownOfAPointNeedsANameAndAPostcode(): void
    {
        $point = new DeliveryPoint(new Shop('Fournil', 'fournil'));

        $point->setTown(' Valence ', ' 26000 ');
        self::assertSame(['Valence', '26000', 'valence-26'], [$point->getCity(), $point->getPostcode(), $point->getCommuneSlug()]);

        $point->setTown('Valence', ' ');
        self::assertSame(['Valence', null, null], [$point->getCity(), $point->getPostcode(), $point->getCommuneSlug()]);

        $point->setTown('', '26000');
        self::assertSame([null, '26000', null], [$point->getCity(), $point->getPostcode(), $point->getCommuneSlug()]);

        $point->setTown(null, null);
        self::assertSame([null, null, null], [$point->getCity(), $point->getPostcode(), $point->getCommuneSlug()]);
    }

    public function testAHomeDeliveryIsGeocodedFromTheTownOfItsZone(): void
    {
        $point = new DeliveryPoint(new Shop('Fournil', 'fournil'), 'À domicile', DeliveryPointType::Home);
        self::assertFalse($point->isGeocodable());
        self::assertFalse($point->hasDeliveryZone());

        $point->setAddress('3 rue ignorée, 26400 Crest')->setZoneTown('  Crest ');
        self::assertSame('Crest', $point->getZoneTown());
        self::assertSame('Crest', $point->geocodingQuery());
        self::assertTrue($point->isGeocodingPending());
        self::assertFalse($point->hasDeliveryZone(), 'No radius yet.');

        $point->setZoneRadiusKm(12)->setCoordinates(44.73, 5.02);
        self::assertTrue($point->hasDeliveryZone());
        self::assertFalse($point->isGeocodingPending());

        // Same town: the coordinates stay.
        $point->setZoneTown('Crest');
        self::assertTrue($point->hasCoordinates());
        // Another town: they must be looked up again.
        $point->setZoneTown('Die');
        self::assertFalse($point->hasCoordinates());
        self::assertTrue($point->isGeocodingPending());

        $point->setZoneTown(' ');
        self::assertNull($point->getZoneTown());

        // A fixed point ignores the zone and keeps its address.
        $fixed = new DeliveryPoint(new Shop('Fournil', 'fournil'))->setAddress("  Place\n26400 Crest\n")->setZoneTown('Die');
        self::assertSame('Place 26400 Crest', $fixed->geocodingQuery());
        self::assertFalse($fixed->setZoneRadiusKm(5)->hasDeliveryZone());
    }

    public function testTheMeansOfPaymentReadAsCustomersSeeThem(): void
    {
        $shop = new Shop('Fournil', 'fournil');
        self::assertNull($shop->paymentSummary());
        self::assertSame([], $shop->getPaymentMethods());

        $shop->setPaymentInfo('  ');
        self::assertNull($shop->paymentSummary());

        $shop->setPaymentMethods([PaymentMethod::FoodVoucher, PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::LocalCurrency]);
        self::assertSame([PaymentMethod::Cash, PaymentMethod::LocalCurrency, PaymentMethod::FoodVoucher], $shop->getPaymentMethods(), 'In the order of the enum, once each.');
        self::assertTrue($shop->acceptsPaymentMethod(PaymentMethod::Cash));
        self::assertFalse($shop->acceptsPaymentMethod(PaymentMethod::Cheque));
        self::assertSame(['Espèces', 'Monnaie locale', 'Chèques ou caisse alimentaire'], $shop->paymentMethodLabels());
        self::assertSame('Espèces, Monnaie locale, Chèques ou caisse alimentaire', $shop->paymentSummary());

        $shop->setLocalCurrencyName(' la Mesure ')->setFoodVoucherDetails('CCAS')->setPaymentInfo(' Wero au 06 12 34 56 78 ');
        self::assertSame('la Mesure', $shop->getLocalCurrencyName());
        self::assertSame('Espèces, Monnaie locale (la Mesure), Chèques ou caisse alimentaire (CCAS). Wero au 06 12 34 56 78', $shop->paymentSummary());

        $shop->setPaymentMethods([])->setLocalCurrencyName('')->setFoodVoucherDetails(null);
        self::assertNull($shop->getLocalCurrencyName());
        self::assertNull($shop->getFoodVoucherDetails());
        self::assertSame('Wero au 06 12 34 56 78', $shop->paymentSummary());
    }

    public function testCategoriesIdentityAndPublicEmail(): void
    {
        $shop = new Shop('Fournil', 'fournil');
        self::assertFalse($shop->isListedInSearch(), 'Opt-in.');
        self::assertNull($shop->publicEmail());

        $shop->setCategories([ShopCategory::Other, ShopCategory::Bread, ShopCategory::Bread]);
        self::assertSame([ShopCategory::Bread, ShopCategory::Other], $shop->getCategories());

        $shop->addUser(new User('camille@example.test'));
        self::assertSame('camille@example.test', $shop->publicEmail());

        $shop->setContactEmail(' bonjour@fournil.test ')->setLegalName('  ');
        self::assertSame('bonjour@fournil.test', $shop->publicEmail());
        self::assertNull($shop->getLegalName());
        self::assertSame('Camille Dupont EI', $shop->setLegalName(' Camille Dupont EI ')->getLegalName());
    }

    public function testLabelsAreDeduplicatedWhateverTheirCase(): void
    {
        self::assertSame(['Bio', 'AOP', 'Label Rouge', 'Équitable'], Product::uniqueLabels([['Bio', 'AOP'], ['bio', 'Label Rouge'], [], ['aop', 'Équitable', 'équitable']]));
        self::assertSame([], Product::uniqueLabels([]));
    }

    public function testTheFiltersAreReadFromTheQueryString(): void
    {
        $filters = SearchFilters::fromQuery($this->query(['categorie' => 'dairy', 'bio' => '1', 'cheques-alimentaires' => '1', 'monnaie-locale' => '0']));
        self::assertSame(ShopCategory::Dairy, $filters->category);
        self::assertTrue($filters->organic);
        self::assertTrue($filters->foodVouchers);
        self::assertFalse($filters->localCurrency);
        self::assertFalse($filters->isEmpty());
        self::assertSame(['categorie' => 'dairy', 'bio' => 1, 'cheques-alimentaires' => 1], $filters->toQuery());

        $none = SearchFilters::fromQuery($this->query(['categorie' => 'x', 'bio' => 'true']));
        self::assertNull($none->category);
        self::assertFalse($none->organic);
        self::assertTrue($none->isEmpty());
        self::assertSame([], $none->toQuery());

        $criteria = SearchCriteria::fromQuery($this->query(['ou' => 'Crest', 'rayon' => '10', 'monnaie-locale' => '1']));
        self::assertTrue($criteria->filters->localCurrency);
        self::assertSame(['ou' => 'Crest', 'rayon' => 50, 'monnaie-locale' => 1], $criteria->withRadius(50));
        self::assertSame(['ou' => 'Crest', 'rayon' => 10], $criteria->withoutFilters());
    }

    public function testAResultInsideADeliveryZoneDeliversToTheVisitor(): void
    {
        $shop = new Shop('Paniers', 'paniers');
        $home = new NearbyShop($shop, 0.0, 'Livraison à domicile', 'Crest', 44.7, 5.0, homeDelivery: true);
        self::assertSame('livre chez toi', $home->distanceLabel());
        self::assertSame('Livraison à domicile autour de Crest', $home->pointLabel());
        self::assertSame('Livraison à domicile', new NearbyShop($shop, 0.0, 'Livraison à domicile', null, 44.7, 5.0, homeDelivery: true)->pointLabel());

        $fixed = new NearbyShop($shop, 4.4, 'Marché', "Place\n26400 Crest", 44.7, 5.0);
        self::assertSame('à 4 km', $fixed->distanceLabel());
        self::assertSame('à moins d’1 km', new NearbyShop($shop, 0.99, 'Marché', null, 44.7, 5.0)->distanceLabel());
        self::assertSame('à 1 km', new NearbyShop($shop, 1.0, 'Marché', null, 44.7, 5.0)->distanceLabel());
        self::assertSame('Marché, Crest', $fixed->pointLabel());

        $withLabels = $fixed->with(null, ['Bio']);
        self::assertSame(['Bio'], $withLabels->labels);
        self::assertSame(4.4, $withLabels->distanceKm);
        self::assertFalse($withLabels->homeDelivery);
        self::assertTrue($home->with(null, [])->homeDelivery);
    }

    public function testATownOfTheDirectoryIsNamedWithItsDepartment(): void
    {
        $commune = new DirectoryCommune('valence-26', 'Valence', '26000', 44.93, 4.89, 3);

        self::assertSame('26', $commune->department());
        self::assertSame('Valence (26)', $commune->label());
    }

    public function testTheProvenanceOfAnOrder(): void
    {
        foreach (OrderSource::cases() as $source) {
            // A generated order (recurring order) never comes from a URL.
            self::assertSame(OrderSource::Subscription === $source ? null : $source, OrderSource::fromVia($source->via()));
            self::assertNotSame('', $source->label());
        }
        self::assertSame('Annuaire des producteurs', OrderSource::Directory->label());
        self::assertSame('Autre producteur à proximité', OrderSource::CrossDiscovery->label());
        self::assertSame('Lien partagé par le producteur', OrderSource::SharedLink->label());
        self::assertNull(OrderSource::fromVia('directory'));
        self::assertNull(OrderSource::fromVia(['recherche']));
        self::assertNull(OrderSource::fromVia(null));
    }

    public function testLabelsOfTheEnums(): void
    {
        self::assertSame('Œufs et volailles', ShopCategory::EggsPoultry->label());
        self::assertSame('Miel et produits de la ruche', ShopCategory::Honey->label());
        self::assertSame('Wero / paiement instantané', PaymentMethod::InstantTransfer->label());
        self::assertSame('Sans résultat', SearchOutcome::Empty->label());
        self::assertSame('Lieu introuvable', SearchOutcome::NotFound->label());
        self::assertSame('Avec résultats', SearchOutcome::Results->label());
        $labels = array_map(static fn (ShopCategory $category): string => $category->label(), ShopCategory::cases());
        self::assertCount(11, array_unique($labels));
        self::assertCount(7, array_unique(array_map(static fn (PaymentMethod $method): string => $method->label(), PaymentMethod::cases())));
    }

    /**
     * @param array<string, string> $values
     *
     * @return InputBag<string>
     */
    private function query(array $values): InputBag
    {
        return Request::create('/', 'GET', $values)->query;
    }
}
