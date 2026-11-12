<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Exception\DeliveryMarkNotAllowedException;
use App\Ordering\Service\OrderDeliveryMarker;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class DeliverySheetTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    private User $producer;
    private Sale $sale;
    private SaleDelivery $square;
    private SaleDelivery $home;
    private SaleOffer $bread;
    private SaleOffer $brioche;
    private Order $jeanne;
    private Order $paul;
    private Order $lucie;
    private Order $homeOrder;

    public function testSheetShowsTheTourOfOneDelivery(): void
    {
        $client = $this->createClientWithTour();

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison');

        self::assertResponseIsSuccessful();
        // Header: date, point and time window.
        self::assertSelectorTextContains('[data-sheet-header]', 'Place du village');
        self::assertSelectorTextContains('[data-sheet-header]', $this->formatter()->timeRange($this->square->getStartsAt(), $this->square->getEndsAt()));
        self::assertSelectorTextContains('[data-sheet-header] h1', ucfirst($this->formatter()->dateLong($this->sale->getDeliveryDate())));
        // Progress.
        self::assertSelectorTextContains('[data-progress]', '1 livrée sur 3');
        self::assertSame('1', $crawler->filter('[role="progressbar"]')->attr('aria-valuenow'));
        self::assertSame('3', $crawler->filter('[role="progressbar"]')->attr('aria-valuemax'));
        // Truck load of this delivery only: 2 + 1 + 3 breads, 2 brioches.
        $truck = $crawler->filter('[data-truck] li');
        self::assertCount(2, $truck);
        self::assertStringContainsString('6 Pain de campagne', $truck->eq(0)->text());
        self::assertStringContainsString('(reste 5)', $truck->eq(0)->text());
        self::assertStringContainsString('2 Brioche', $truck->eq(1)->text());
        // Search field and tabs.
        self::assertSelectorExists('input[type="search"][data-delivery-sheet-target="search"]');
        self::assertSelectorTextContains('#tab-todo', 'À livrer (2)');
        self::assertSelectorTextContains('#tab-done', 'Livrées (1)');
        self::assertSelectorCount(2, '#panel-todo [data-order]');
        self::assertSelectorCount(1, '#panel-done [data-order]');

        // One order card: big checkbox, name, items, amount, note, phone.
        $card = $crawler->filter('[data-order="'.$this->jeanne->getReference().'"]');
        self::assertCount(1, $card);
        self::assertSame('Jeanne Martin '.$this->jeanne->getReference(), $card->attr('data-search'));
        self::assertCount(1, $card->filter('button[role="checkbox"][aria-checked="false"][value="delivered"]'));
        self::assertStringContainsString('2 × Pain de campagne', $card->text());
        self::assertStringContainsString('2 × Brioche', $card->text());
        self::assertStringContainsString('À régler', $card->text());
        self::assertStringContainsString($this->formatter()->money(2400), $card->text());
        self::assertSame('Sans sésame, merci', $card->filter('[data-order-note]')->text());
        self::assertSame('tel:0612345678', $card->filter('a[href^="tel:"]')->attr('href'));
        self::assertCount(1, $card->filter('button[value="not_delivered"]'));
        self::assertCount(0, $card->filter('[data-order-address]'));

        $delivered = $crawler->filter('#panel-done [data-order="'.$this->paul->getReference().'"]');
        self::assertCount(1, $delivered->filter('button[role="checkbox"][aria-checked="true"][value="confirmed"]'));

        // The cancelled order and the other delivery's order are not on this sheet.
        self::assertStringNotContainsString('Client Annulé', $crawler->text());
        self::assertSelectorNotExists('[data-order="'.$this->homeOrder->getReference().'"]');
        // Nothing about payment (SPEC §1).
        self::assertStringNotContainsStringIgnoringCase('payé', $crawler->html());
        self::assertStringNotContainsStringIgnoringCase('paiement', $crawler->html());
    }

    public function testSheetOfAHomeDeliveryShowsTheAddress(): void
    {
        $client = $this->createClientWithTour();

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison?creneau='.$this->home->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-sheet-header]', 'Livraison à domicile');
        self::assertSelectorTextContains('[data-progress]', '0 livrée sur 1');
        self::assertSelectorCount(1, '[data-order]');
        self::assertSame('12 rue des Lilas, 26400 Crest', $crawler->filter('[data-order-address]')->text());
        // Both deliveries can be reached from the header.
        self::assertSelectorCount(2, '[data-sheet-header] nav a');
        self::assertSelectorTextContains('[data-sheet-header] nav a[aria-current="page"]', 'Livraison à domicile');
    }

    public function testAnUnknownDeliveryIsNotFound(): void
    {
        $client = $this->createClientWithTour();
        $other = SaleDeliveryFactory::createOne(['sale' => SaleFactory::createOne(['shop' => self::shopOf($this->producer)])]);

        $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison?creneau='.$other->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testSheetWithoutOrderShowsAnEmptyState(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::new()->closed()->create(['shop' => self::shopOf($producer)]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/livraison');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="empty"]', 'Aucune commande à livrer ici');
    }

    public function testTickingAnOrderMarksItDelivered(): void
    {
        $client = $this->createClientWithTour();
        $sheetUrl = '/pro/ventes/'.$this->sale->getId().'/livraison';

        $crawler = $client->request('GET', $sheetUrl);
        $client->submit($crawler->filter('[data-order="'.$this->jeanne->getReference().'"] button[value="delivered"]')->form());

        self::assertResponseRedirects($sheetUrl, 303);
        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Delivered, $order->getStatus());
        self::assertCount(1, $order->getEvents());
        $event = $order->getEvents()->first();
        self::assertNotFalse($event);
        self::assertSame(OrderEventType::Delivered, $event->getType());
        self::assertSame(Actor::Producer, $event->getActor());
        self::assertSame(['from' => 'confirmed', 'to' => 'delivered'], $event->getDetail());
        self::assertGreaterThanOrEqual($order->getCreatedAt(), $order->getUpdatedAt());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-progress]', '2 livrées sur 3');
        self::assertSelectorTextContains('#tab-done', 'Livrées (2)');

        // Unticking puts the order back to "confirmed".
        $client->submit($crawler->filter('[data-order="'.$this->jeanne->getReference().'"] button[value="confirmed"]')->form());

        self::assertResponseRedirects($sheetUrl, 303);
        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertCount(2, $order->getEvents());
        $event = $order->getEvents()->last();
        self::assertNotFalse($event);
        self::assertSame(OrderEventType::Reconfirmed, $event->getType());
    }

    public function testMarkingAnOrderNotDeliveredThenBackToDeliver(): void
    {
        $client = $this->createClientWithTour();
        $sheetUrl = '/pro/ventes/'.$this->sale->getId().'/livraison';

        $crawler = $client->request('GET', $sheetUrl);
        $client->submit($crawler->filter('[data-order="'.$this->lucie->getReference().'"] button[value="not_delivered"]')->form());

        self::assertResponseRedirects($sheetUrl, 303);
        $order = self::reload(Order::class, $this->lucie->getId());
        self::assertSame(OrderStatus::NotDelivered, $order->getStatus());
        $event = $order->getEvents()->last();
        self::assertNotFalse($event);
        self::assertSame(OrderEventType::NotDelivered, $event->getType());

        $crawler = $client->followRedirect();
        // Still in the list to deliver, flagged, and no longer counted as delivered.
        $card = $crawler->filter('#panel-todo [data-order="'.$this->lucie->getReference().'"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Non livrée', $card->text());
        self::assertSelectorTextContains('[data-progress]', '1 livrée sur 3');

        $client->submit($card->filter('button[value="confirmed"]')->selectButton('Remettre à livrer')->form());
        self::assertResponseRedirects($sheetUrl, 303);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->lucie->getId())->getStatus());
    }

    public function testMarkRedirectsToTheSameDeliveryOfTheSheet(): void
    {
        $client = $this->createClientWithTour();
        $sheetUrl = '/pro/ventes/'.$this->sale->getId().'/livraison?creneau='.$this->home->getId();

        $crawler = $client->request('GET', $sheetUrl);
        $client->submit($crawler->filter('[data-order] button[value="delivered"]')->form());

        self::assertResponseRedirects($sheetUrl, 303);
        self::assertSame(OrderStatus::Delivered, self::reload(Order::class, $this->homeOrder->getId())->getStatus());
    }

    public function testMarkRejectsAnUnknownStatusAndTheCancelledOne(): void
    {
        $client = $this->createClientWithTour();
        $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison');

        foreach (['cancelled', 'paid', ''] as $status) {
            $client->request('POST', '/pro/commandes/'.$this->jeanne->getId().'/statut', ['status' => $status, '_csrf_token' => 'csrf-token']);
            self::assertResponseStatusCodeSame(404);
        }

        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertCount(0, $order->getEvents());
    }

    public function testMarkRequiresAValidCsrfToken(): void
    {
        $client = $this->createClientWithTour();

        $client->request('POST', '/pro/commandes/'.$this->jeanne->getId().'/statut', ['status' => 'delivered']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testACancelledOrderKeepsItsStatus(): void
    {
        $client = $this->createClientWithTour();
        $cancelled = self::order($this->sale, $this->bread, 1, ['saleDelivery' => $this->square, 'status' => OrderStatus::Cancelled]);

        $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison');
        $client->request('POST', '/pro/commandes/'.$cancelled->getId().'/statut', ['status' => 'delivered', '_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/pro/ventes/'.$this->sale->getId().'/livraison', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'Cette commande est annulée');
        $order = self::reload(Order::class, $cancelled->getId());
        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertCount(0, $order->getEvents());
    }

    public function testOrdersOfAnOpenSaleCannotBeTickedYet(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale]);
        $order = self::order($sale, $offer, 1, ['saleDelivery' => SaleDeliveryFactory::createOne(['sale' => $sale])]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/livraison');
        $client->request('POST', '/pro/commandes/'.$order->getId().'/statut', ['status' => 'delivered', '_csrf_token' => 'csrf-token']);

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'pas encore closes');
        $order = self::reload(Order::class, $order->getId());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertCount(0, $order->getEvents());
    }

    public function testMarkerReadsTheLatestStatusUnderALock(): void
    {
        $this->createClientWithTour();
        $marker = self::getContainer()->get(OrderDeliveryMarker::class);

        // Cancelled meanwhile by someone else: the stale object in memory still says "confirmed".
        self::entityManager()->getConnection()->executeStatement(
            'UPDATE customer_order SET status = :status WHERE id = :id',
            ['status' => OrderStatus::Cancelled->value, 'id' => $this->lucie->getId()],
        );
        self::assertTrue($this->lucie->isConfirmed());

        try {
            $marker->mark($this->lucie, OrderStatus::Delivered);
            self::fail('A cancelled order must not be marked delivered.');
        } catch (DeliveryMarkNotAllowedException) {
        }

        self::assertSame(OrderStatus::Cancelled, self::reload(Order::class, $this->lucie->getId())->getStatus());
    }

    public function testMarkerIgnoresAnUnchangedStatus(): void
    {
        $this->createClientWithTour();
        $marker = self::getContainer()->get(OrderDeliveryMarker::class);

        $marker->mark($this->paul, OrderStatus::Delivered);
        self::assertCount(0, $this->paul->getEvents());

        $this->expectException(\DomainException::class);
        $marker->mark($this->jeanne, OrderStatus::Cancelled);
    }

    /**
     * A closed sale with two deliveries. On the village square: Jeanne (to deliver, with a note),
     * Paul (delivered), Lucie (to deliver) and a cancelled order. At home: one order with an address.
     */
    private function createClientWithTour(): KernelBrowser
    {
        $client = self::createClient();
        $this->producer = self::subscribedProducer();
        $shop = self::shopOf($this->producer);

        $this->sale = SaleFactory::new()->closed()->create(['shop' => $shop, 'title' => 'Fournée du lundi']);
        $this->square = SaleDeliveryFactory::createOne([
            'sale' => $this->sale,
            'deliveryPoint' => DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']),
            'position' => 0,
        ]);
        $this->home = SaleDeliveryFactory::createOne([
            'sale' => $this->sale,
            'deliveryPoint' => DeliveryPointFactory::new()->home()->create(['shop' => $shop]),
            'position' => 1,
        ]);
        $this->bread = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 500])]);
        $this->brioche = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 700])]);

        $this->jeanne = self::order(
            $this->sale,
            $this->bread,
            2,
            ['saleDelivery' => $this->square, 'note' => 'Sans sésame, merci'],
            CustomerFactory::createOne(['firstName' => 'Jeanne', 'lastName' => 'Martin', 'phone' => '06 12 34 56 78']),
        );
        OrderLineFactory::createOne(['order' => $this->jeanne, 'saleOffer' => $this->brioche, 'quantity' => 2000]);
        $this->paul = self::order(
            $this->sale,
            $this->bread,
            1,
            ['saleDelivery' => $this->square, 'status' => OrderStatus::Delivered],
            CustomerFactory::createOne(['firstName' => 'Paul', 'lastName' => 'Durand']),
        );
        $this->lucie = self::order(
            $this->sale,
            $this->bread,
            3,
            ['saleDelivery' => $this->square],
            CustomerFactory::createOne(['firstName' => 'Lucie', 'lastName' => 'Bernard']),
        );
        self::order(
            $this->sale,
            $this->bread,
            9,
            ['saleDelivery' => $this->square, 'status' => OrderStatus::Cancelled],
            CustomerFactory::createOne(['firstName' => 'Client', 'lastName' => 'Annulé']),
        );
        $this->homeOrder = self::order(
            $this->sale,
            $this->brioche,
            1,
            ['saleDelivery' => $this->home, 'deliveryAddress' => '12 rue des Lilas, 26400 Crest'],
        );

        $client->loginUser($this->producer, 'producer');

        return $client;
    }

    private function formatter(): FrenchFormatter
    {
        return self::getContainer()->get(FrenchFormatter::class);
    }
}
