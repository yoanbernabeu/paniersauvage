<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Entity\Order;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Exception\LastMinuteNotAllowed;
use App\Sale\Form\LastMinuteSaleType;
use App\Sale\Form\Model\LastMinuteSaleData;
use App\Sale\Service\LastMinuteSale;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFollowerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Zenstruck\Foundry\Test\Factories;

/**
 * Leftovers and last-minute sale (SPEC §21, lot F): a controlled reopening of a closed sale, on the
 * leftovers the producer puts back on sale, for a short while before the delivery.
 */
final class LastMinuteSaleTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use SaleScenario;

    private User $producer;
    private Sale $sale;
    private SaleDelivery $delivery;
    /** Limited to 30, 26 reserved. */
    private SaleOffer $bread;
    /** Limited to 10, all reserved. */
    private SaleOffer $brioche;
    /** Unlimited, 3 reserved. */
    private SaleOffer $cake;

    public function testTheSalePageShowsTheLeftoversOfAClosedSale(): void
    {
        $client = $this->createClientWithClosedSale();

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-leftovers]');
        self::assertStringContainsString('Invendus', $card->text());
        self::assertCount(1, $card->filter('[data-leftover]'), 'Only the limited offers with something left.');
        self::assertStringContainsString('Pain de campagne', $card->filter('[data-leftover="'.$this->bread->getId().'"]')->text());
        self::assertStringContainsString('4 sur 30', $card->filter('[data-leftover="'.$this->bread->getId().'"]')->text());
        self::assertSame('/pro/ventes/'.$this->sale->getId().'/derniere-minute', $card->filter('a[data-last-minute-link]')->attr('href'));
        self::assertSelectorNotExists('[data-share-card]');
    }

    public function testAnOpenSaleHasNoLeftoversYet(): void
    {
        $client = $this->createClientWithClosedSale();
        $open = SaleFactory::createOne(['shop' => self::shopOf($this->producer)]);

        $client->request('GET', '/pro/ventes/'.$open->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-leftovers]');

        $client->request('GET', '/pro/ventes/'.$open->getId().'/derniere-minute');
        self::assertResponseRedirects('/pro/ventes/'.$open->getId(), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'La vente de dernière minute s\'ouvre une fois les commandes closes, avant la livraison.');
    }

    public function testTheProducerOpensALastMinuteSaleOnTheLeftovers(): void
    {
        $client = $this->createClientWithClosedSale();
        ShopFollowerFactory::createOne(['shop' => self::shopOf($this->producer), 'customer' => CustomerFactory::new(['email' => 'alice@example.test'])]);
        ShopFollowerFactory::createOne(['shop' => self::shopOf($this->producer), 'customer' => CustomerFactory::new(['email' => 'bob@example.test']), 'emailConsent' => false]);
        ShopFollowerFactory::createOne(['shop' => self::shopOf($this->producer), 'customer' => CustomerFactory::new(['email' => 'carl@example.test']), 'emailConsent' => false]);
        ShopFollowerFactory::createOne(['customer' => CustomerFactory::new(['email' => 'other-shop@example.test'])]);

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/derniere-minute');

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Ouvrir la vente de dernière minute')->form();
        // Prefilled: the end two hours before the delivery, what is left of the limited offers.
        $until = $this->delivery->getStartsAt()->modify('-2 hours');
        self::assertSame($until->format('Y-m-d\TH:i'), $this->value($form, 'last_minute_sale[until]'));
        self::assertSame('4', $this->value($form, 'last_minute_sale[quantities]['.$this->bread->getId().']'));
        self::assertSame('', $this->value($form, 'last_minute_sale[quantities]['.$this->brioche->getId().']'));
        self::assertSame('', $this->value($form, 'last_minute_sale[quantities]['.$this->cake->getId().']'));
        self::assertSelectorTextContains('[data-last-minute-offer="'.$this->cake->getId().'"]', 'sans limite, commandé : 3');
        self::assertSelectorTextContains('[data-slot="alert"]', '1 abonné qui accepte tes e-mails sera prévenu, une seule fois');

        // 3 loaves (one broke), and 2 cakes baked on top.
        $form['last_minute_sale[quantities]['.$this->bread->getId().']'] = '3';
        $form['last_minute_sale[quantities]['.$this->cake->getId().']'] = '2';
        $form['last_minute_sale[quantities]['.$this->brioche->getId().']'] = '0';
        $client->submit($form);

        self::assertResponseRedirects('/pro/ventes/'.$this->sale->getId(), 303);
        self::assertQueuedEmailCount(1);

        $sale = self::reload(Sale::class, $this->sale->getId());
        self::assertTrue($sale->isClosed(), 'The status does not go back.');
        self::assertTrue($sale->isLastMinute());
        self::assertEquals($until, $sale->getLastMinuteUntil());
        self::assertNotNull($sale->getLastMinuteOpenedAt());
        $bread = self::reload(SaleOffer::class, $this->bread->getId());
        self::assertSame(29_000, $bread->getMaxQty());
        self::assertSame(26_000, $bread->getReservedQty());
        self::assertTrue($bread->isLastMinute());
        $cake = self::reload(SaleOffer::class, $this->cake->getId());
        self::assertSame(5000, $cake->getMaxQty());
        self::assertTrue($cake->isLastMinute());
        self::assertFalse(self::reload(SaleOffer::class, $this->brioche->getId())->isLastMinute());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'Ta vente de dernière minute est ouverte');
        self::assertSelectorExists('[data-last-minute-open]');
        self::assertSelectorExists('[data-share-card]');
        self::assertSelectorTextContains('[data-share-card]', 'Ta vente de dernière minute est ouverte');
        self::assertSelectorExists('form[data-last-minute-end]');
        self::assertSelectorNotExists('a[data-last-minute-link]');
        self::assertStringContainsString('Vente de dernière minute', $crawler->text());

        // It opens once.
        $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/derniere-minute');
        self::assertResponseRedirects('/pro/ventes/'.$this->sale->getId(), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-slot="alert"]', 'Tu as déjà ouvert une vente de dernière minute pour cette livraison.');
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function refusedForms(): iterable
    {
        yield 'too short' => ['+10 minutes', [], 'Laisse au moins 30 minutes à tes clients pour commander.'];
        yield 'after the margin before the delivery' => ['DELIVERY -30 minutes', [], 'une heure avant la livraison.'];
        yield 'nothing put back on sale' => ['DELIVERY -2 hours', ['BREAD' => ''], 'Indique au moins un produit à remettre en vente.'];
        yield 'a fraction of a loaf' => ['DELIVERY -2 hours', ['BREAD' => '1,5'], 'Indique un nombre entier.'];
        yield 'too many' => ['DELIVERY -2 hours', ['BREAD' => '10000'], 'beaucoup : indique au plus 9 999.'];
        yield 'no end' => ['', [], 'quand tes clients peuvent commander.'];
    }

    /**
     * @param array<string, string> $quantities
     */
    #[DataProvider('refusedForms')]
    public function testARefusedFormChangesNothing(string $until, array $quantities, string $message): void
    {
        $client = $this->createClientWithClosedSale();
        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/derniere-minute');
        $form = $crawler->selectButton('Ouvrir la vente de dernière minute')->form();

        $form['last_minute_sale[until]'] = match (true) {
            '' === $until => '',
            str_starts_with($until, 'DELIVERY') => $this->delivery->getStartsAt()->modify(substr($until, 9))->format('Y-m-d\TH:i'),
            default => self::now()->modify($until)->format('Y-m-d\TH:i'),
        };
        foreach ($quantities as $offer => $quantity) {
            $form['last_minute_sale[quantities]['.('BREAD' === $offer ? $this->bread->getId() : $offer).']'] = $quantity;
        }
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, (string) $client->getResponse()->getContent());
        $sale = self::reload(Sale::class, $this->sale->getId());
        self::assertFalse($sale->isLastMinute());
        self::assertNull($sale->getLastMinuteUntil());
        self::assertSame(30_000, self::reload(SaleOffer::class, $this->bread->getId())->getMaxQty());
        self::assertFalse(self::reload(SaleOffer::class, $this->bread->getId())->isLastMinute());
        self::assertQueuedEmailCount(0);
    }

    public function testItIsTooLateRightBeforeTheDelivery(): void
    {
        $client = $this->createClientWithClosedSale(deliveryIn: '+80 minutes');

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId());

        self::assertSelectorTextContains('[data-last-minute-refusal]', 'C\'est trop tard : la livraison commence bientôt.');
        self::assertCount(0, $crawler->filter('a[data-last-minute-link]'));
    }

    public function testAnExpiredSubscriptionCannotOpenIt(): void
    {
        $client = self::createClient();
        $this->producer = self::expiredProducer();
        $this->createClosedSale();
        $client->loginUser($this->producer, 'producer');

        $client->request('GET', '/pro/ventes/'.$this->sale->getId());

        self::assertSelectorTextContains('[data-last-minute-refusal]', 'Ton abonnement ne permet plus d\'ouvrir de vente.');
    }

    public function testCustomersOrderTheLeftoversOnly(): void
    {
        $client = $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);
        $client->restart();

        // The shop page lists it, with its own end.
        $crawler = $client->request('GET', '/'.self::shopOf($this->producer)->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-sale="'.$this->sale->getCode().'"]', 'Dernière minute');
        self::assertStringContainsString($this->formatter()->dateTimeLong($this->delivery->getStartsAt()->modify('-2 hours')), $crawler->filter('[data-sale="'.$this->sale->getCode().'"]')->text());

        $crawler = $client->request('GET', '/'.self::shopOf($this->producer)->getSlug().'/vente/'.$this->sale->getCode());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-sale-banner]', 'Dernière minute');
        self::assertSelectorTextContains('[data-sale-banner]', 'Il reste quelques produits');
        self::assertCount(1, $crawler->filter('[data-order-form-row]'), 'Only the offers put back on sale.');
        self::assertCount(1, $crawler->filter('[data-order-form-row][data-offer="'.$this->bread->getId().'"]'));

        $client->submitForm('Valider ma commande', [
            'order_form[quantities]['.$this->bread->getId().']' => '2',
            'order_form[delivery]' => (string) $this->delivery->getId(),
            'order_form[email]' => 'late@example.test',
            'order_form[firstName]' => 'Léa',
            'order_form[lastName]' => 'Tardive',
            'order_form[acceptTerms]' => '1',
        ]);

        self::assertResponseRedirects();
        $bread = self::reload(SaleOffer::class, $this->bread->getId());
        self::assertSame(28_000, $bread->getReservedQty());
        self::assertSame(0, $bread->remaining(), 'Sold out: the limit was 26 reserved + 2.');
        self::assertQueuedEmailCount(1);
    }

    public function testACustomerCannotOrderAnOfferLeftAside(): void
    {
        $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);
        $sale = self::reload(Sale::class, $this->sale->getId());
        $cake = $sale->getOffers()->findFirst(fn (int $key, SaleOffer $offer): bool => $offer->getId() === $this->cake->getId()) ?? throw new \LogicException('No cake.');
        $delivery = $sale->getDeliveries()->first() ?: throw new \LogicException('No delivery.');

        $this->expectException(OrderNotAllowedException::class);
        $this->expectExceptionMessageIsOrContains('« Gâteau » n\'est plus disponible.');

        self::getContainer()->get(OrderManager::class)->placeOrder($sale, CustomerFactory::createOne(), new OrderRequest($delivery, [(int) $cake->getId() => 1000]), Actor::Customer);
    }

    public function testTheProducerCanStillAddAnyProduct(): void
    {
        $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);
        $sale = self::reload(Sale::class, $this->sale->getId());
        $cake = $sale->getOffers()->findFirst(fn (int $key, SaleOffer $offer): bool => $offer->getId() === $this->cake->getId()) ?? throw new \LogicException('No cake.');
        $delivery = $sale->getDeliveries()->first() ?: throw new \LogicException('No delivery.');

        $order = self::getContainer()->get(OrderManager::class)->placeOrder($sale, CustomerFactory::createOne(), new OrderRequest($delivery, [(int) $cake->getId() => 1000]), Actor::Producer);

        self::assertInstanceOf(Order::class, $order);
    }

    public function testTheProducerStopsItAndCustomersCannotOrderAnyMore(): void
    {
        $client = $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId());
        $client->submit($crawler->filter('form[data-last-minute-end]')->form());

        self::assertResponseRedirects('/pro/ventes/'.$this->sale->getId(), 303);
        $sale = self::reload(Sale::class, $this->sale->getId());
        self::assertFalse($sale->isLastMinuteOpen(self::now()));
        self::assertTrue($sale->isLastMinute());
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Terminée le', $crawler->filter('[data-leftovers]')->text());
        self::assertSelectorNotExists('[data-share-card]');
        self::assertSelectorNotExists('a[data-last-minute-link]');
        self::assertSelectorTextContains('[data-slot="alert"]', 'La vente de dernière minute est terminée');

        $client->restart();
        $client->request('GET', '/'.self::shopOf($this->producer)->getSlug().'/vente/'.$this->sale->getCode());
        self::assertSelectorNotExists('form[name="order_form"]');
    }

    public function testAResumedOrderKeepsTheLeftoversOnly(): void
    {
        $client = $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);
        $customer = CustomerFactory::createOne(['email' => 'regular@example.test', 'firstName' => 'Rémi', 'lastName' => 'Habitué', 'phone' => '06 00 00 00 00']);
        // Last week: one loaf and one cake.
        $previous = SaleFactory::new()->done()->create(['shop' => self::shopOf($this->producer)]);
        $previousDelivery = SaleDeliveryFactory::createOne(['sale' => $previous]);
        $order = OrderFactory::createOne(['sale' => $previous, 'customer' => $customer, 'saleDelivery' => $previousDelivery]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => SaleOfferFactory::createOne(['sale' => $previous, 'product' => $this->bread->getProduct()]), 'quantity' => 1000]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => SaleOfferFactory::createOne(['sale' => $previous, 'product' => $this->cake->getProduct()]), 'quantity' => 1000]);
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', '/'.self::shopOf($this->producer)->getSlug().'/vente/'.$this->sale->getCode().'?reprendre=1');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Valider ma commande')->form();
        self::assertSame('1', self::value($form, 'order_form[quantities]['.$this->bread->getId().']'));
        $form['order_form[delivery]'] = (string) $this->delivery->getId();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString('/merci/', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(27_000, self::reload(SaleOffer::class, $this->bread->getId())->getReservedQty());
        self::assertSame(3000, self::reload(SaleOffer::class, $this->cake->getId())->getReservedQty(), 'The cake left aside is not ordered.');
    }

    public function testTheShopPageListsTheOpenAndTheLastMinuteSales(): void
    {
        $client = $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);
        $open = SaleFactory::createOne(['shop' => self::shopOf($this->producer)]);
        $client->restart();

        $client->request('GET', '/'.self::shopOf($this->producer)->getSlug());

        self::assertSelectorExists('[data-sale="'.$this->sale->getCode().'"]');
        self::assertSelectorExists('[data-sale="'.$open->getCode().'"]');
        self::assertSelectorTextContains('[data-sale="'.$open->getCode().'"]', 'Commandes ouvertes');
    }

    public function testASaleWithoutDeliveryCannotOpenIt(): void
    {
        $this->createClientWithClosedSale();
        $sale = SaleFactory::new()->closed()->create(['shop' => self::shopOf($this->producer)]);
        $service = self::getContainer()->get(LastMinuteSale::class);

        self::assertNull($service->latestEnd($sale));
        self::assertNull($service->defaultEnd($sale));
        self::assertSame('C\'est trop tard : la livraison commence bientôt.', $service->refusal($sale));
    }

    public function testItOpensUntilTheLatestEndAndCommits(): void
    {
        $this->createClientWithClosedSale();
        $sale = self::reload(Sale::class, $this->sale->getId());
        $connection = self::entityManager()->getConnection();
        $level = $connection->getTransactionNestingLevel();

        self::getContainer()->get(LastMinuteSale::class)->open($sale, $this->delivery->getStartsAt()->modify('-1 hour'), [(int) $this->bread->getId() => 1000]);

        self::assertSame($level, $connection->getTransactionNestingLevel());
        self::assertEquals($this->delivery->getStartsAt()->modify('-1 hour'), self::reload(Sale::class, $this->sale->getId())->getLastMinuteUntil());
    }

    public function testAFailureRollsBackAndRestoresTheOffers(): void
    {
        $this->createClientWithClosedSale();
        $other = SaleOfferFactory::createOne(['sale' => SaleFactory::new()->closed()->create(['shop' => self::shopOf($this->producer)])]);
        $sale = self::reload(Sale::class, $this->sale->getId());
        $bread = $sale->getOffers()->findFirst(fn (int $key, SaleOffer $offer): bool => $offer->getId() === $this->bread->getId()) ?? throw new \LogicException('No bread.');
        $connection = self::entityManager()->getConnection();
        $level = $connection->getTransactionNestingLevel();

        try {
            self::getContainer()->get(LastMinuteSale::class)->open($sale, $this->delivery->getStartsAt()->modify('-2 hours'), [(int) $this->bread->getId() => 2000, (int) $other->getId() => 1000]);
            self::fail('An offer of another sale cannot be put back on sale.');
        } catch (LastMinuteNotAllowed) {
        }

        self::assertSame($level, $connection->getTransactionNestingLevel(), 'Rolled back.');
        self::assertSame(30_000, $bread->getMaxQty(), 'The offer in memory is back to what is saved.');
        self::assertFalse($bread->isLastMinute());
        self::assertFalse($sale->isLastMinute());
    }

    public function testItReadsTheSaleUnderALock(): void
    {
        $this->createClientWithClosedSale();
        $sale = self::reload(Sale::class, $this->sale->getId());
        // Opened by a double submission since the sale was loaded.
        self::entityManager()->getConnection()->executeStatement('UPDATE sale SET last_minute_opened_at = NOW(), last_minute_until = NOW() WHERE id = ?', [$this->sale->getId()]);

        $this->expectExceptionObject(new LastMinuteNotAllowed('Tu as déjà ouvert une vente de dernière minute pour cette livraison.'));

        self::getContainer()->get(LastMinuteSale::class)->open($sale, $this->delivery->getStartsAt()->modify('-2 hours'), [(int) $this->bread->getId() => 1000]);
    }

    public function testItLastsAtLeastThirtyMinutes(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-20 10:00:00'));
        $this->createClientWithClosedSale();
        $sale = self::reload(Sale::class, $this->sale->getId());

        self::getContainer()->get(LastMinuteSale::class)->open($sale, new \DateTimeImmutable('2026-11-20 10:30:00'), [(int) $this->bread->getId() => 1000]);

        self::assertTrue(self::reload(Sale::class, $this->sale->getId())->isLastMinute());
    }

    public function testTheFormTakesUpTo9999UnitsAndNamesTheEnd(): void
    {
        $this->createClientWithClosedSale();
        $offers = self::reload(Sale::class, $this->sale->getId())->getOffers()->getValues();
        $factory = self::getContainer()->get('form.factory');
        $id = (string) $this->bread->getId();

        $form = $factory->create(LastMinuteSaleType::class, null, ['offers' => $offers, 'csrf_protection' => false]);
        $form->submit(['until' => '2030-01-01T10:00', 'quantities' => [$id => '9999']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(LastMinuteSaleData::class, $data);
        self::assertSame(9_999_000, $data->quantities[(int) $id]);

        $view = $factory->create(LastMinuteSaleType::class, null, ['offers' => $offers])->createView();
        self::assertSame('Tes clients commandent jusqu\'au', $view['until']->vars['label']);
        $attributes = $view['quantities'][$id]->vars['attr'];
        self::assertIsArray($attributes);
        self::assertSame('numeric', $attributes['inputmode'] ?? null);
        self::assertFalse($view['quantities'][$id]->vars['required']);

        $this->expectException(InvalidOptionsException::class);
        $factory->create(LastMinuteSaleType::class, null, ['offers' => 'all']);
    }

    public function testStoppingRequiresAValidCsrfToken(): void
    {
        $client = $this->createClientWithClosedSale();
        $this->openLastMinute([(int) $this->bread->getId() => 2000]);

        $client->request('POST', '/pro/ventes/'.$this->sale->getId().'/derniere-minute/arreter', ['_csrf_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue(self::reload(Sale::class, $this->sale->getId())->isLastMinuteOpen(self::now()));
    }

    public function testAnotherProducerGetsNotFound(): void
    {
        $client = $this->createClientWithClosedSale();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/derniere-minute');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/pro/ventes/'.$this->sale->getId().'/derniere-minute');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/pro/ventes/'.$this->sale->getId().'/derniere-minute/arreter');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::reload(Sale::class, $this->sale->getId())->isLastMinute());
    }

    public function testTheServiceRefusesAnOfferOfAnotherSale(): void
    {
        $this->createClientWithClosedSale();
        $other = SaleOfferFactory::createOne(['sale' => SaleFactory::new()->closed()->create(['shop' => self::shopOf($this->producer)])]);
        $sale = self::reload(Sale::class, $this->sale->getId());

        try {
            self::getContainer()->get(LastMinuteSale::class)->open($sale, $this->delivery->getStartsAt()->modify('-2 hours'), [(int) $other->getId() => 1000]);
            self::fail('An offer of another sale cannot be put back on sale.');
        } catch (LastMinuteNotAllowed $exception) {
            self::assertSame('Un des produits ne fait pas partie de cette vente.', $exception->getMessage());
        }

        $sale = self::reload(Sale::class, $this->sale->getId());
        self::assertFalse($sale->isLastMinute());
        self::assertFalse(self::reload(SaleOffer::class, $other->getId())->isLastMinute());
    }

    public function testTheDefaultEndFallsBackToTheLatestEnd(): void
    {
        $this->createClientWithClosedSale(deliveryIn: '+150 minutes');
        $service = self::getContainer()->get(LastMinuteSale::class);
        $sale = self::reload(Sale::class, $this->sale->getId());

        // Two hours before the delivery is in 30 minutes: too short, one hour before is suggested.
        self::assertEquals($this->delivery->getStartsAt()->modify('-1 hour'), $service->defaultEnd($sale));
        self::assertNull($service->refusal($sale));
    }

    /**
     * @param array<int, int> $quantities
     */
    private function openLastMinute(array $quantities): void
    {
        $sale = self::reload(Sale::class, $this->sale->getId());
        self::getContainer()->get(LastMinuteSale::class)->open($sale, $this->delivery->getStartsAt()->modify('-2 hours'), $quantities);
    }

    private function createClientWithClosedSale(string $deliveryIn = '+2 days'): KernelBrowser
    {
        $client = self::createClient();
        $this->producer = self::subscribedProducer();
        $this->createClosedSale($deliveryIn);
        $client->loginUser($this->producer, 'producer');

        return $client;
    }

    private function createClosedSale(string $deliveryIn = '+2 days'): void
    {
        $shop = self::shopOf($this->producer);
        $starts = self::now()->modify($deliveryIn);
        $starts = '+2 days' === $deliveryIn ? $starts->setTime(18, 0) : $starts->setTime((int) $starts->format('H'), (int) $starts->format('i'));
        $this->sale = SaleFactory::new()->closed()->create(['shop' => $shop, 'deliveryDate' => $starts]);
        $this->delivery = SaleDeliveryFactory::createOne(['sale' => $this->sale, 'startsAt' => $starts, 'endsAt' => $starts->modify('+1 hour')]);
        $this->bread = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'position' => 1]), 'maxQty' => 30_000, 'reservedQty' => 26_000]);
        $this->brioche = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'position' => 2]), 'maxQty' => 10_000, 'reservedQty' => 10_000]);
        $this->cake = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Gâteau', 'position' => 3, 'maxQtyDefault' => null]), 'maxQty' => null, 'reservedQty' => 3000]);
    }

    private function value(Form $form, string $name): mixed
    {
        $field = $form[$name];
        self::assertInstanceOf(FormField::class, $field);

        return $field->getValue();
    }

    private function formatter(): FrenchFormatter
    {
        return self::getContainer()->get(FrenchFormatter::class);
    }
}
