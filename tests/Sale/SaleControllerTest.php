<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Sale\Exception\SaleEditConflict;
use App\Sale\Repository\SaleRepository;
use App\Sale\Service\SaleDataFactory;
use App\Sale\Service\SaleEditor;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

final class SaleControllerTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    public function testIndexShowsAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $client->request('GET', '/pro/ventes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="empty"]', 'Pas encore de vente');
        self::assertSelectorExists('a[href="/pro/ventes/nouvelle"]');
    }

    public function testIndexGroupsSalesByStatusWithTheirOrderCount(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $open = SaleFactory::createOne(['shop' => $shop, 'title' => 'Fournée ouverte']);
        SaleFactory::new()->scheduled()->create(['shop' => $shop, 'title' => 'Fournée à venir']);
        SaleFactory::new()->closed()->create(['shop' => $shop, 'title' => 'Fournée close']);
        SaleFactory::new()->done()->create(['shop' => $shop, 'title' => 'Fournée passée']);
        SaleFactory::new()->cancelled()->create(['shop' => $shop, 'title' => 'Fournée annulée']);
        OrderFactory::createMany(2, ['sale' => $open]);
        OrderFactory::new()->cancelled()->create(['sale' => $open]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-sales-section="open"]', 'Fournée ouverte');
        self::assertSelectorTextContains('[data-sales-section="open"]', '2 commandes');
        self::assertSelectorTextContains('[data-sales-section="scheduled"]', 'Fournée à venir');
        self::assertSelectorTextContains('[data-sales-section="closed"]', 'Fournée close');
        // Rendering the list must not change any status (Twig would call Sale::open() for `sale.open`).
        self::assertSelectorTextContains('[data-sales-section="closed"] [data-sale-status]', 'Commandes closes');
        self::assertSelectorTextContains('[data-sales-section="scheduled"] [data-sale-status]', 'Programmée');
        self::assertSelectorTextContains('[data-sales-section="past"]', 'Fournée passée');
        self::assertSelectorTextContains('[data-sales-section="past"]', 'Fournée annulée');
        self::assertSelectorTextNotContains('[data-sales-section="open"]', 'Fournée close');
    }

    public function testNewSaleIsPrefilledWithTheRangeAndSeasonalProductsInSeason(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']);
        ProductFactory::new()->limitedTo(12)->create(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'position' => 1]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+1 month'))
            ->create(['shop' => $shop, 'name' => 'Galette de saison', 'priceCents' => 900, 'position' => 2]);
        ProductFactory::new()->seasonal(new \DateTimeImmutable('+2 months'), new \DateTimeImmutable('+3 months'))
            ->create(['shop' => $shop, 'name' => 'Stollen hors saison', 'priceCents' => 1100, 'position' => 3]);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Pain archivé']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/nouvelle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvelle vente');
        self::assertSelectorCount(1, '[data-delivery-row]');
        self::assertSelectorCount(3, '[data-offer-row]');
        self::assertStringNotContainsString('Pain archivé', $crawler->text());

        $form = $crawler->selectButton('Créer la vente')->form();
        self::assertTrue($this->checkbox($form, 'sale[deliveries][0][selected]')->hasValue());
        self::assertTrue($this->checkbox($form, 'sale[offers][0][selected]')->hasValue());
        self::assertTrue($this->checkbox($form, 'sale[offers][1][selected]')->hasValue());
        self::assertFalse($this->checkbox($form, 'sale[offers][2][selected]')->hasValue());
        self::assertInputValueSame('sale[offers][0][priceCents]', '5,20');
        self::assertInputValueSame('sale[offers][0][maxQty]', '12');
        self::assertInputValueSame('sale[offers][1][maxQty]', '');
    }

    public function testCreatingAManualSaleOpensItWhenItsOpeningIsDue(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $square = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Marché']);
        DeliveryPointFactory::new()->home()->create(['shop' => $shop]);
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550, 'position' => 1]);
        $brioche = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 750, 'position' => 2]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/nouvelle');
        $form = $crawler->selectButton('Créer la vente')->form();
        $deliveryDate = new \DateTimeImmutable('+10 days');

        // Rows follow the names: "Livraison à domicile" then "Marché".
        $this->checkbox($form, 'sale[deliveries][1][selected]')->tick();
        $this->checkbox($form, 'sale[offers][1][selected]')->untick();
        $client->submit($form, [
            'sale[title]' => 'Fournée de Noël',
            'sale[deliveryDate]' => $deliveryDate->format('Y-m-d'),
            'sale[opensAt]' => new \DateTimeImmutable('-1 hour')->format('Y-m-d\TH:i'),
            'sale[deadlineAt]' => $deliveryDate->modify('-2 days')->format('Y-m-d').'T20:00',
            'sale[editCutoffHours]' => '24',
            'sale[deliveries][1][startTime]' => '09:00',
            'sale[deliveries][1][endTime]' => '12:30',
            'sale[offers][0][priceCents]' => '6,50',
            'sale[offers][0][maxQty]' => '15',
        ]);

        $sales = self::getContainer()->get(SaleRepository::class)->findBy(['shop' => $shop]);
        self::assertCount(1, $sales);
        $sale = $sales[0];
        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());

        self::assertSame('Fournée de Noël', $sale->getTitle());
        self::assertNull($sale->getSchedule());
        self::assertSame(SaleStatus::Open, $sale->getStatus());
        self::assertSame($deliveryDate->format('Y-m-d'), $sale->getDeliveryDate()->format('Y-m-d'));
        self::assertSame($deliveryDate->modify('-2 days')->format('Y-m-d').' 20:00', $sale->getDeadlineAt()->format('Y-m-d H:i'));
        self::assertEquals($sale->getDeadlineAt()->modify('-24 hours'), $sale->getEditCutoffAt());
        self::assertTrue($sale->isAllowCustomerEdit());

        self::assertCount(1, $sale->getDeliveries());
        $delivery = $sale->getDeliveries()->first();
        self::assertNotFalse($delivery);
        self::assertSame($square->getId(), $delivery->getDeliveryPoint()->getId());
        self::assertSame($deliveryDate->format('Y-m-d').' 09:00', $delivery->getStartsAt()->format('Y-m-d H:i'));
        self::assertSame($deliveryDate->format('Y-m-d').' 12:30', $delivery->getEndsAt()->format('Y-m-d H:i'));

        self::assertCount(1, $sale->getOffers());
        $offer = $sale->getOffers()->first();
        self::assertNotFalse($offer);
        self::assertSame($bread->getId(), $offer->getProduct()->getId());
        self::assertSame(650, $offer->getPriceCents());
        self::assertSame(550, $offer->getVatRateBp());
        self::assertSame(15000, $offer->getMaxQty());
        self::assertNotNull($brioche->getId());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ta vente est ouverte');
    }

    public function testASaleOpeningLaterStaysScheduled(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/nouvelle');
        $client->submitForm('Créer la vente', [
            'sale[title]' => 'Fournée de demain',
            'sale[opensAt]' => new \DateTimeImmutable('+1 day')->format('Y-m-d\TH:i'),
        ]);

        $sales = self::getContainer()->get(SaleRepository::class)->findBy(['shop' => $shop]);
        self::assertCount(1, $sales);
        self::assertSame(SaleStatus::Scheduled, $sales[0]->getStatus());
        // No limit typed: unlimited, even though nothing was changed.
        $offer = $sales[0]->getOffers()->first();
        self::assertNotFalse($offer);
        self::assertNull($offer->getMaxQty());
    }

    public function testNewSaleValidation(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/nouvelle');
        $form = $crawler->selectButton('Créer la vente')->form();
        $this->checkbox($form, 'sale[deliveries][0][selected]')->untick();
        $this->checkbox($form, 'sale[offers][0][selected]')->untick();
        $client->submit($form, [
            'sale[title]' => '',
            'sale[opensAt]' => new \DateTimeImmutable('+3 days')->format('Y-m-d\TH:i'),
            'sale[deadlineAt]' => new \DateTimeImmutable('+2 days')->format('Y-m-d\TH:i'),
        ]);

        self::assertResponseStatusCodeSame(422);
        $text = $client->getCrawler()->text();
        self::assertStringContainsString('Donne un titre à ta vente.', $text);
        self::assertStringContainsString('La date limite doit suivre l\'ouverture.', $text);
        self::assertStringContainsString('Choisis au moins un point de livraison.', $text);
        self::assertStringContainsString('Propose au moins un produit.', $text);
        self::assertSame(0, self::getContainer()->get(SaleRepository::class)->count([]));
    }

    public function testADeadlineInThePastIsRefused(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/nouvelle');
        $client->submitForm('Créer la vente', [
            'sale[title]' => 'Trop tard',
            'sale[opensAt]' => new \DateTimeImmutable('-3 days')->format('Y-m-d\TH:i'),
            'sale[deadlineAt]' => new \DateTimeImmutable('-1 day')->format('Y-m-d\TH:i'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('La date limite est déjà passée.', $client->getCrawler()->text());
    }

    public function testCreationIsRefusedWhenTheSubscriptionDoesNotAllowNewSales(): void
    {
        $client = self::createClient();
        $producer = self::expiredProducer();
        $shop = self::shopOf($producer);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser($producer, 'producer');

        foreach (['GET', 'POST'] as $method) {
            $client->request($method, '/pro/ventes/nouvelle', 'POST' === $method ? ['sale' => ['title' => 'Vente interdite']] : []);
            self::assertResponseRedirects('/pro/ventes');
        }

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'Ton abonnement ne permet plus de créer de nouvelles ventes');
        self::assertSelectorExists('[data-subscription-alert] a[href="/pro/abonnement"]');
        self::assertSelectorNotExists('a[href="/pro/ventes/nouvelle"]');
        self::assertSame(0, self::getContainer()->get(SaleRepository::class)->count([]));
    }

    public function testShowDisplaysLinkShareProductionAndOrdersByDelivery(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop, 'title' => 'Fournée du lundi']);
        $square = SaleDeliveryFactory::createOne(['sale' => $sale, 'deliveryPoint' => DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']), 'position' => 0]);
        $market = SaleDeliveryFactory::createOne(['sale' => $sale, 'deliveryPoint' => DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Marché couvert']), 'position' => 1]);
        $bread = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 500]), 'maxQty' => 10000, 'reservedQty' => 5000]);
        $brioche = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 700])]);

        $jeanne = CustomerFactory::createOne(['firstName' => 'Jeanne', 'lastName' => 'Martin']);
        $paul = CustomerFactory::createOne(['firstName' => 'Paul', 'lastName' => 'Durand']);
        self::order($sale, $bread, 2, ['saleDelivery' => $square, 'note' => 'Bien cuit'], $jeanne);
        self::order($sale, $bread, 3, ['saleDelivery' => $market], $paul);
        $cancelled = self::order($sale, $brioche, 4, ['saleDelivery' => $market]);
        $cancelled->cancel();
        self::entityManager()->flush();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fournée du lundi');
        self::assertSelectorTextContains('[data-sale-status="open"]', 'Ouverte');

        // Public link and WhatsApp share.
        $publicUrl = \sprintf('http://localhost/%s/vente/%s', $shop->getSlug(), $sale->getCode());
        self::assertSame($publicUrl, $crawler->filter('#sale-public-url')->attr('value'));
        self::assertSelectorExists('[data-controller="clipboard"] button[data-action="clipboard#copy"]');
        $whatsApp = $crawler->filter('a[data-whatsapp-share]')->attr('href');
        self::assertNotNull($whatsApp);
        self::assertStringStartsWith('https://wa.me/?text=', $whatsApp);
        $message = rawurldecode(substr($whatsApp, \strlen('https://wa.me/?text=')));
        self::assertStringContainsString('Pain de campagne, Brioche', $message);
        self::assertStringContainsString('Commande avant ', $message);
        // The shared link counts as shared by the producer when someone orders from it.
        self::assertStringEndsWith($publicUrl.'?via=lien', $message);

        // Production totals and remaining quantities (the cancelled order does not count).
        $rows = $crawler->filter('[data-production-row]');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Pain de campagne', $rows->eq(0)->text());
        self::assertSame('5', $rows->eq(0)->filter('td')->eq(1)->text());
        self::assertSame('5 sur 10', $rows->eq(0)->filter('td')->eq(2)->text());
        self::assertSame('0', $rows->eq(1)->filter('td')->eq(1)->text());
        self::assertSame('illimité', $rows->eq(1)->filter('td')->eq(2)->text());

        // Orders grouped by delivery point.
        $groups = $crawler->filter('[data-delivery-group]');
        self::assertCount(2, $groups);
        self::assertStringContainsString('Place du village', $groups->eq(0)->text());
        self::assertStringContainsString('Jeanne Martin', $groups->eq(0)->text());
        self::assertStringContainsString('2 × Pain de campagne', $groups->eq(0)->text());
        self::assertStringContainsString('Bien cuit', $groups->eq(0)->text());
        self::assertStringNotContainsString('Paul Durand', $groups->eq(0)->text());
        self::assertStringContainsString('Marché couvert', $groups->eq(1)->text());
        self::assertStringContainsString('Paul Durand', $groups->eq(1)->text());
        self::assertStringContainsString('1 commande', $groups->eq(1)->text());
        self::assertStringNotContainsString($cancelled->getReference(), $crawler->html());

        self::assertSelectorExists('a[href="/pro/ventes/'.$sale->getId().'/livraison"]');
        self::assertSelectorExists('a[href="/pro/ventes/'.$sale->getId().'/modifier"]');
        self::assertStringNotContainsStringIgnoringCase('payé', $crawler->text());
    }

    public function testEditUpdatesTheSale(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop, 'title' => 'Fournée du lundi']);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'priceCents' => 500])]);
        $extra = ProductFactory::createOne(['shop' => $shop, 'name' => 'Zeste de brioche', 'priceCents' => 700, 'position' => 9]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('sale[title]', 'Fournée du lundi');
        // The sale is open: its opening date is locked.
        self::assertSelectorExists('input[name="sale[opensAt]"][disabled]');

        $form = $crawler->selectButton('Enregistrer')->form();
        $this->checkbox($form, 'sale[offers][1][selected]')->tick();
        $client->submit($form, [
            'sale[title]' => 'Fournée du mardi',
            'sale[offers][0][priceCents]' => '5,50',
            'sale[offers][0][maxQty]' => '8',
        ]);

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        $reloaded = self::reload(Sale::class, $sale->getId());
        self::assertSame('Fournée du mardi', $reloaded->getTitle());
        self::assertEquals($sale->getOpensAt(), $reloaded->getOpensAt());
        self::assertCount(2, $reloaded->getOffers());
        $updated = self::entityManager()->find(SaleOffer::class, $offer->getId());
        self::assertNotNull($updated);
        self::assertSame(550, $updated->getPriceCents());
        self::assertSame(8000, $updated->getMaxQty());
        $added = $reloaded->getOffers()->last();
        self::assertNotFalse($added);
        self::assertSame($extra->getId(), $added->getProduct()->getId());
        self::assertSame(700, $added->getPriceCents());
    }

    public function testEditCannotLowerAQuantityBelowWhatIsReserved(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'maxQty' => 10000, 'reservedQty' => 6000]);
        self::order($sale, $offer, 6);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');
        self::assertSelectorTextContains('[data-offer-row]', '6 déjà réservés');
        $client->submitForm('Enregistrer', ['sale[offers][0][maxQty]' => '5']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-offer-row]', 'Déjà 6 réservés : tu ne peux pas descendre en dessous.');
        $reloaded = self::reload(SaleOffer::class, $offer->getId());
        self::assertSame(10000, $reloaded->getMaxQty());

        // Exactly the reserved quantity is accepted.
        $client->submitForm('Enregistrer', ['sale[offers][0][maxQty]' => '6']);
        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        self::assertSame(6000, self::reload(SaleOffer::class, $offer->getId())->getMaxQty());
    }

    public function testEditCannotRemoveAnOrderedOfferNorAnOrderedDelivery(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $usedDelivery = SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 0]);
        SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 1]);
        $ordered = SaleOfferFactory::createOne(['sale' => $sale, 'reservedQty' => 2000]);
        SaleOfferFactory::createOne(['sale' => $sale]);
        self::order($sale, $ordered, 2, ['saleDelivery' => $usedDelivery]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->checkbox($form, 'sale[offers][0][selected]')->untick();
        $this->checkbox($form, 'sale[deliveries][0][selected]')->untick();
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $text = $client->getCrawler()->text();
        self::assertStringContainsString('Ce produit a déjà été commandé : tu ne peux pas le retirer de la vente.', $text);
        self::assertStringContainsString('Des commandes sont déjà prévues ici : tu ne peux pas retirer cette livraison.', $text);
        $reloaded = self::reload(Sale::class, $sale->getId());
        self::assertCount(2, $reloaded->getOffers());
        self::assertCount(2, $reloaded->getDeliveries());

        // The offer and the delivery nobody ordered can go.
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->checkbox($form, 'sale[offers][0][selected]')->tick();
        $this->checkbox($form, 'sale[deliveries][0][selected]')->tick();
        $this->checkbox($form, 'sale[offers][1][selected]')->untick();
        $this->checkbox($form, 'sale[deliveries][1][selected]')->untick();
        $client->submit($form);

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        $reloaded = self::reload(Sale::class, $sale->getId());
        self::assertCount(1, $reloaded->getOffers());
        self::assertCount(1, $reloaded->getDeliveries());
    }

    public function testOrdersLockTheDeliveryDateAndTheTimesOfTheirDelivery(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $ordered = SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 0]);
        $free = SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 1]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'reservedQty' => 1000]);
        self::order($sale, $offer, 1, ['saleDelivery' => $ordered]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');
        self::assertSelectorExists('input[name="sale[deliveryDate]"][disabled]');
        self::assertSelectorExists('input[name="sale[deliveries][0][startTime]"][disabled]');
        self::assertSelectorExists('input[name="sale[deliveries][0][endTime]"][disabled]');
        self::assertSelectorNotExists('input[name="sale[deliveries][1][startTime]"][disabled]');
        self::assertSelectorTextContains('[data-delivery-row]', 'horaires figés');

        // Even a forged request cannot move the ordered delivery; the free one can change.
        $client->submitForm('Enregistrer', [
            'sale[deliveries][1][startTime]' => '07:00',
            'sale[deliveries][1][endTime]' => '08:00',
        ]);

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        $reloadedOrdered = self::reload(SaleDelivery::class, $ordered->getId());
        self::assertEquals($ordered->getStartsAt(), $reloadedOrdered->getStartsAt());
        self::assertEquals($ordered->getEndsAt(), $reloadedOrdered->getEndsAt());
        self::assertSame('07:00', self::reload(SaleDelivery::class, $free->getId())->getStartsAt()->format('H:i'));
        self::assertEquals($sale->getDeliveryDate(), self::reload(Sale::class, $sale->getId())->getDeliveryDate());
    }

    public function testAnOrderPlacedWhileEditingBlocksDateAndTimeChanges(): void
    {
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        $sale = SaleFactory::createOne(['shop' => $shop]);
        $delivery = SaleDeliveryFactory::createOne(['sale' => $sale]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'reservedQty' => 1000]);
        $editor = self::getContainer()->get(SaleEditor::class);
        $factory = self::getContainer()->get(SaleDataFactory::class);

        // The form was displayed before the order arrived.
        $moveDate = $factory->fromSale($sale);
        $moveDate->deliveryDate = $sale->getDeliveryDate()->modify('+1 day');
        $moveTimes = $factory->fromSale($sale);
        $moveTimes->deliveries[0]->startTime = new \DateTimeImmutable('1970-01-01 06:00');
        self::order($sale, $offer, 1, ['saleDelivery' => $delivery]);

        foreach ([[$moveDate, 'la date de livraison ne peut plus changer'], [$moveTimes, 'ses horaires ne peuvent plus changer']] as [$data, $message]) {
            try {
                $editor->update($sale, $data);
                self::fail('The change must be refused.');
            } catch (SaleEditConflict $conflict) {
                self::assertStringContainsString($message, $conflict->getMessage());
            }
        }

        $reloaded = self::reload(SaleDelivery::class, $delivery->getId());
        self::assertEquals($delivery->getStartsAt(), $reloaded->getStartsAt());
    }

    public function testADeliveryOrderedWhileEditingCannotBeRemoved(): void
    {
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 0]);
        $second = SaleDeliveryFactory::createOne(['sale' => $sale, 'position' => 1]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'reservedQty' => 1000]);

        // The form was displayed (and validated) before a customer ordered on that delivery, then
        // cancelled: the order still references the row, removing it would break the foreign key.
        $data = self::getContainer()->get(SaleDataFactory::class)->fromSale($sale);
        $data->deliveries[1]->selected = false;
        self::order($sale, $offer, 1, ['saleDelivery' => $second, 'status' => OrderStatus::Cancelled]);

        try {
            self::getContainer()->get(SaleEditor::class)->update($sale, $data);
            self::fail('Removing an ordered delivery must be refused.');
        } catch (SaleEditConflict $conflict) {
            self::assertStringContainsString('tu ne peux plus retirer cette livraison', $conflict->getMessage());
        }

        self::assertCount(2, self::reload(Sale::class, $sale->getId())->getDeliveries());
    }

    public function testAClosedSaleKeepsItsDates(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::new()->closed()->create(['shop' => self::shopOf($producer)]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        SaleOfferFactory::createOne(['sale' => $sale]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');
        self::assertSelectorExists('input[name="sale[deadlineAt]"][disabled]');
        self::assertSelectorExists('input[name="sale[deliveryDate]"][disabled]');
        $client->submitForm('Enregistrer', ['sale[title]' => 'Titre corrigé']);

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        $reloaded = self::reload(Sale::class, $sale->getId());
        self::assertSame('Titre corrigé', $reloaded->getTitle());
        self::assertEquals($sale->getDeadlineAt(), $reloaded->getDeadlineAt());
        self::assertSame(SaleStatus::Closed, $reloaded->getStatus());
    }

    public function testADoneSaleCannotBeEdited(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::new()->done()->create(['shop' => self::shopOf($producer)]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId().'/modifier');

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'ne peut plus être modifiée');
        self::assertSelectorNotExists('a[href="/pro/ventes/'.$sale->getId().'/modifier"]');
    }

    public function testCancelASale(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$sale->getId());
        $client->submitForm('Annuler la vente');

        self::assertResponseRedirects('/pro/ventes/'.$sale->getId());
        self::assertSame(SaleStatus::Cancelled, self::reload(Sale::class, $sale->getId())->getStatus());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ta vente est annulée');
        self::assertSelectorTextContains('[data-sale-status="cancelled"]', 'Annulée');
        self::assertSelectorNotExists('form[action$="/annuler"]');
    }

    public function testCancelRequiresAValidCsrfToken(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        $client->loginUser($producer, 'producer');

        $client->request('POST', '/pro/ventes/'.$sale->getId().'/annuler');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $sale->getId())->getStatus());
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }
}
