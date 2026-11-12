<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Delivery\Entity\DeliveryPoint;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Repository\ShopRepository;
use App\Shop\Search\CrossDiscovery;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * "D'autres producteurs près de ce point de retrait" and the provenance of orders (`?via=`).
 */
final class CrossDiscoveryTest extends WebTestCase
{
    use Factories;

    /** Place de l'église, Saoû. */
    private const float LAT = 44.6464;
    private const float LNG = 5.0624;

    public function testTheShopPageShowsUpToThreeListedNeighboursByDistance(): void
    {
        $client = self::createClient();
        $shop = $this->shop('Fournil du Pont', listed: false);
        $this->point($shop, self::LAT, self::LNG);
        $this->neighbour('Voisin à 2 km', 0.018);
        $this->neighbour('Voisin à 5 km', 0.045);
        $this->neighbour('Voisin à 1 km', 0.009);
        $this->neighbour('Voisin à 8 km', 0.072);
        $this->neighbour('Voisin à 15 km', 0.135);
        $this->neighbour('Voisin masqué', 0.001, listed: false);

        $crawler = $client->request('GET', '/fournil-du-pont');

        self::assertResponseIsSuccessful();
        self::assertSame(['voisin-a-1-km', 'voisin-a-2-km', 'voisin-a-5-km'], $crawler->filter('[data-nearby-shop]')->each(static fn (Crawler $node): string => (string) $node->attr('data-nearby-shop')));
        self::assertSame('/voisin-a-1-km?via=croise', $crawler->filter('[data-nearby-shop]')->eq(0)->attr('href'));
        self::assertSelectorTextContains('[data-nearby-shops]', 'Autour de Place de l\'église, à moins de 10 km.');
        self::assertSelectorTextContains('[data-nearby-shop="voisin-a-1-km"]', 'Pain et viennoiseries');
        self::assertSelectorTextContains('[data-nearby-shop="voisin-a-1-km"]', 'Pas de vente prévue');
    }

    public function testTheFirstLocatedFixedPointIsUsed(): void
    {
        $client = self::createClient();
        $shop = $this->shop('Fournil du Pont', listed: false);
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'A non localisé', 'address' => 'Lieu-dit']);
        DeliveryPointFactory::new()->located(self::LAT, self::LNG)->create(['shop' => $shop, 'name' => 'B place']);
        $this->neighbour('Voisin', 0.009);

        $client->request('GET', '/fournil-du-pont');

        self::assertSelectorExists('[data-nearby-shop="voisin"]');
        self::assertSelectorTextContains('[data-nearby-shops]', 'Autour de B place');
    }

    public function testAHomeDeliveryHasNoNeighbours(): void
    {
        self::bootKernel();
        $home = DeliveryPointFactory::new()->home()->located(self::LAT, self::LNG)->create(['shop' => $this->shop('Livreur', listed: false), 'zoneTown' => 'Saoû', 'zoneRadiusKm' => 10]);
        $this->neighbour('Voisin', 0.009);

        self::assertSame([], self::getContainer()->get(CrossDiscovery::class)->around($home));
    }

    public function testALocatedDeliveryZoneHasNoNeighboursEither(): void
    {
        self::bootKernel();
        $home = DeliveryPointFactory::new()->home()->create(['shop' => $this->shop('Livreur', listed: false), 'zoneTown' => 'Saoû', 'zoneRadiusKm' => 10]);
        // The centre of the zone is located: still no suggestion around a customer's home.
        $home->setCoordinates(self::LAT, self::LNG, new \DateTimeImmutable());
        $this->neighbour('Voisin', 0.009);

        self::assertTrue($home->hasCoordinates());
        self::assertSame([], self::getContainer()->get(CrossDiscovery::class)->around($home));
    }

    public function testNoBlockWithoutALocatedFixedPoint(): void
    {
        $client = self::createClient();
        $shop = $this->shop('Livreur', listed: false);
        DeliveryPointFactory::new()->home()->located(self::LAT, self::LNG)->create(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop, 'address' => 'Lieu-dit inconnu']);
        $this->neighbour('Voisin', 0.009);

        $client->request('GET', '/livreur');

        self::assertSelectorNotExists('[data-nearby-shops]');
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?OrderSource}>
     */
    public static function sources(): iterable
    {
        yield 'directory' => ['recherche', OrderSource::Directory];
        yield 'other producer nearby' => ['croise', OrderSource::CrossDiscovery];
        yield 'shared link' => ['lien', OrderSource::SharedLink];
        yield 'unknown value' => ['pub', null];
        yield 'none' => [null, null];
    }

    #[DataProvider('sources')]
    public function testWhereTheCustomerCameFromIsKeptUntilTheOrderWithoutCookie(?string $via, ?OrderSource $source): void
    {
        $client = self::createClient();
        [$sale, $offer, $delivery] = $this->openSale($this->shop('Fournil du Pont'));

        $crawler = $client->request('GET', '/fournil-du-pont'.(null === $via ? '' : '?via='.$via));
        $orderLink = $crawler->filter('[data-sale] a')->attr('href');
        self::assertSame('/fournil-du-pont/vente/'.$sale->getCode().($source instanceof OrderSource ? '?via='.$source->via() : ''), $orderLink);

        $crawler = $client->request('GET', $orderLink);
        self::assertCount($source instanceof OrderSource ? 1 : 0, $crawler->filter('input[type="hidden"][name="via"][data-order-via]'));
        foreach ($client->getCookieJar()->all() as $cookie) {
            self::assertStringNotContainsString('via', strtolower($cookie->getName().$cookie->getValue()));
        }

        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $delivery->getId(),
            \sprintf('order_form[quantities][%d]', (int) $offer->getId()) => '1',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ]);
        self::assertResponseRedirects();

        self::assertSame($source, $this->onlyOrder()->getSource());
    }

    public function testAnInvalidFormKeepsTheProvenance(): void
    {
        $client = self::createClient();
        [$sale] = $this->openSale($this->shop('Fournil du Pont'));

        $client->request('GET', '/fournil-du-pont/vente/'.$sale->getCode().'?via=croise');
        $crawler = $client->submitForm('Valider ma commande', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('croise', $crawler->filter('input[name="via"]')->attr('value'));
    }

    public function testTheConfirmationSuggestsNeighboursOfThePickUpPoint(): void
    {
        $client = self::createClient();
        [$sale, $offer, $delivery] = $this->openSale($this->shop('Fournil du Pont'));
        $this->neighbour('Fromagerie voisine', 0.009, ShopCategory::Dairy);
        $scheduled = $this->neighbour('Maraîcher voisin', 0.02);
        SaleFactory::new()->scheduled()->create(['shop' => $scheduled, 'opensAt' => new \DateTimeImmutable('2030-02-01 08:00')]);

        $client->request('GET', '/fournil-du-pont/vente/'.$sale->getCode());
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $delivery->getId(),
            \sprintf('order_form[quantities][%d]', (int) $offer->getId()) => '1',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ]);
        // The confirmation e-mail carries the suggestions…
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'D\'autres producteurs près de ce point de retrait');
        self::assertEmailHtmlBodyContains($email, 'http://localhost/fromagerie-voisine?via=croise');
        self::assertEmailTextBodyContains($email, 'D\'AUTRES PRODUCTEURS PRÈS DE CE POINT DE RETRAIT');
        self::assertEmailTextBodyContains($email, '- Maraîcher voisin, à 2 km : prochaine vente le vendredi 1er février');
        self::assertEmailTextBodyContains($email, 'http://localhost/maraicher-voisin?via=croise');

        // …and so does the confirmation page.
        $crawler = $client->followRedirect();
        self::assertSame(['fromagerie-voisine', 'maraicher-voisin'], $crawler->filter('[data-nearby-shop]')->each(static fn (Crawler $node): string => (string) $node->attr('data-nearby-shop')));
        self::assertStringContainsString('Fromages et laitages', $crawler->filter('[data-nearby-shop]')->eq(0)->text());
        self::assertStringContainsString('Prochaine vente le vendredi 1er février', str_replace("\u{a0}", ' ', $crawler->filter('[data-nearby-shop]')->eq(1)->text()));
        self::assertSelectorTextContains('[data-nearby-shops]', 'Autour de Place de l\'église');
    }

    public function testAnOrderEnteredByTheProducerSuggestsNoOtherProducer(): void
    {
        self::bootKernel();
        [$sale, $offer, $delivery] = $this->openSale($this->shop('Fournil du Pont'));
        $this->neighbour('Fromagerie voisine', 0.009);
        $order = OrderFactory::createOne(['sale' => $sale, 'saleDelivery' => $delivery]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer]);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $dispatcher->dispatch(new OrderPlaced($order, Actor::Producer));

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyNotContains($email, 'fromagerie-voisine');
        self::assertEmailTextBodyNotContains($email, 'PRÈS DE CE POINT DE RETRAIT');
    }

    public function testTheSalePageShowsTheLabelsAndTheMeansOfPayment(): void
    {
        $client = self::createClient();
        $shop = $this->shop('Fournil du Pont');
        [$sale] = $this->openSale($shop);
        SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::new(['shop' => $shop, 'name' => 'Pain bio', 'labels' => ['Bio', 'Levain naturel']])]);
        $managed = self::getContainer()->get(ShopRepository::class)->findOneBySlug('fournil-du-pont');
        self::assertNotNull($managed);
        $managed->setPaymentMethods([PaymentMethod::Cash, PaymentMethod::Cheque])->setPaymentInfo(null);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/fournil-du-pont/vente/'.$sale->getCode());

        self::assertSame(['Bio', 'Levain naturel'], $crawler->filter('[data-product-labels] li')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSelectorTextContains('[data-sale-banner] [data-payment-info]', 'Tu règles directement au producteur : Espèces, Chèque');
    }

    public function testAShopCanTurnTheBlockOffAndStillShowsAtItsNeighbours(): void
    {
        $client = self::createClient();
        $shop = $this->shop('Fournil du Pont');
        $shop->setCrossDiscoveryEnabled(false);
        [$sale, $offer, $delivery] = $this->openSale($shop);
        $neighbour = $this->neighbour('Fromagerie voisine', 0.009, ShopCategory::Dairy);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->point($neighbour, self::LAT, self::LNG);

        $client->request('GET', '/fournil-du-pont');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-nearby-shops]');

        $client->request('GET', '/fournil-du-pont/vente/'.$sale->getCode());
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $delivery->getId(),
            \sprintf('order_form[quantities][%d]', (int) $offer->getId()) => '1',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ]);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyNotContains($email, 'D\'autres producteurs');
        self::assertEmailTextBodyNotContains($email, 'fromagerie-voisine');
        $client->followRedirect();
        self::assertSelectorNotExists('[data-nearby-shop]');

        // The neighbour still suggests the shop that turned its own block off.
        $crawler = $client->request('GET', '/fromagerie-voisine');
        self::assertContains('fournil-du-pont', $crawler->filter('[data-nearby-shop]')->each(static fn (Crawler $node): string => (string) $node->attr('data-nearby-shop')));
    }

    public function testTheBlockIsOnByDefaultAndSwitchedInTheSettings(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/boutique');
        self::assertSame('checked', $crawler->filter('input[name="shop_settings[crossDiscoveryEnabled]"]')->attr('checked'));

        $form = $crawler->selectButton('Enregistrer')->form();
        $checkbox = $form['shop_settings[crossDiscoveryEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $client->submit($form);
        self::assertResponseRedirects('/pro/boutique');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $shop = self::getContainer()->get(ShopRepository::class)->find((int) $producer->getShop()?->getId());
        self::assertFalse($shop?->isCrossDiscoveryEnabled());
    }

    private function shop(string $name, bool $listed = true, ShopCategory $category = ShopCategory::Bread): Shop
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT', str_replace(['à', 'î'], ['a', 'i'], $name))), '-'));
        $factory = ShopFactory::new()->with(['name' => $name, 'slug' => $slug]);
        $shop = ($listed ? $factory->listed([$category])->verified() : $factory)->create();
        ShopSubscriptionFactory::createOne(['shop' => $shop]);

        return $shop;
    }

    private function point(Shop $shop, float $latitude, float $longitude): DeliveryPoint
    {
        return DeliveryPointFactory::new()->located($latitude, $longitude)->create(['shop' => $shop, 'name' => 'Place de l\'église', 'address' => "Place de l'église\n26400 Saoû"]);
    }

    /**
     * A shop of the directory, $latitudeOffset degrees north of the point (0.009° ≈ 1 km).
     */
    private function neighbour(string $name, float $latitudeOffset, ShopCategory $category = ShopCategory::Bread, bool $listed = true): Shop
    {
        $shop = $this->shop($name, $listed, $category);
        DeliveryPointFactory::new()->located(self::LAT + $latitudeOffset, self::LNG)->create(['shop' => $shop, 'name' => 'Marché']);

        return $shop;
    }

    /**
     * @return array{0: Sale, 1: SaleOffer, 2: SaleDelivery}
     */
    private function openSale(Shop $shop): array
    {
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $delivery = SaleDeliveryFactory::createOne(['sale' => $sale, 'deliveryPoint' => $this->point($shop, self::LAT, self::LNG)]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::new(['shop' => $shop, 'name' => 'Pain de campagne'])]);

        return [$sale, $offer, $delivery];
    }

    private function onlyOrder(): Order
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findAll();
        self::assertCount(1, $orders);

        return $orders[0];
    }
}
