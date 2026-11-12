<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Enum\ProductKind;
use App\Customer\Entity\Customer;
use App\Customer\Repository\CustomerRepository;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

final class SaleOrderPageTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testTheNumberOfQueriesDoesNotGrowWithTheProducts(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $this->createOffer($sale, 'Pain de campagne', 520);
        $url = $this->saleUrl($sale);

        // The first request shares the test's entity manager: measure fresh requests only.
        $client->request('GET', $url);
        $withOne = $this->countQueries($client, $url);

        foreach (['Brioche', 'Baguette', 'Pain aux noix', 'Fougasse'] as $name) {
            $this->createOffer($sale, $name, 450);
        }
        $this->addHomeDelivery($sale);

        self::assertSame($withOne, $this->countQueries($client, $url));
    }

    public function testThePageShowsTheSaleItsDeliveriesAndItsProducts(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $shop->setName('Le Fournil du Pont');
        $sale = $this->createSale($shop, [
            'deliveryDate' => new \DateTimeImmutable('2031-11-17'),
            'deadlineAt' => new \DateTimeImmutable('2031-11-14 20:00'),
        ]);
        $this->addHomeDelivery($sale);
        $this->createOffer($sale, 'Pain de campagne', 520, null, ['description' => 'Levain naturel, farine T80', 'position' => 1]);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5, ['position' => 2]);
        $soldOut = $this->createOffer($sale, 'Pain aux noix', 700, 2, ['position' => 3]);
        $this->createOffer($sale, 'Fougasse aux olives', 450, null, ['kind' => ProductKind::Seasonal]);
        $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($brioche) => 2, self::idOf($soldOut) => 2]);

        $crawler = $client->request('GET', $this->saleUrl($sale));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-shop-header]', 'Le Fournil du Pont');
        self::assertSelectorTextContains('[data-sale-banner]', 'Commandes ouvertes');
        self::assertSelectorTextContains('[data-sale-banner] h1', 'Livraison lundi 17 novembre');
        self::assertSelectorTextContains('[data-sale-banner]', "Commande jusqu'au vendredi 14 novembre à 20\u{a0}h.");
        self::assertSelectorTextContains('[data-sale-banner]', 'Tu pourras modifier jusque-là.');

        // Delivery cards: one radio per delivery, plus the address for home delivery.
        self::assertCount(2, $crawler->filter('input[type="radio"][name="order_form[delivery]"]'));
        self::assertCount(1, $crawler->filter('input[type="radio"][data-home="true"]'));
        self::assertSelectorExists('textarea[name="order_form[deliveryAddress]"]');

        // Products first ("La gamme", then "Du moment"), then the delivery, then the contact details.
        $titles = $crawler->filter('main h2')->each(static fn ($node): string => trim($node->text()));
        self::assertSame(['La gamme', 'Du moment', 'Où récupères-tu ta commande ?', 'Tes coordonnées', 'Avant de valider'], $titles);
        self::assertSame(
            ['Pain de campagne', 'Brioche', 'Pain aux noix', 'Fougasse aux olives'],
            $crawler->filter('[data-order-form-row] label')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertSelectorTextContains('main', 'Levain naturel, farine T80');
        self::assertSelectorTextContains('main', "5,20\u{a0}€");

        // Stock hints: 3 brioches left, walnut bread sold out (no quantity field).
        $briocheRow = $crawler->filter(\sprintf('[data-offer="%d"]', self::idOf($brioche)));
        self::assertSame('Plus que 3', trim($briocheRow->filter('[data-low-stock]')->text()));
        self::assertSame('3', $briocheRow->filter('input[type="number"]')->attr('max'));
        $soldOutRow = $crawler->filter(\sprintf('[data-offer="%d"]', self::idOf($soldOut)));
        self::assertSame('Épuisé', trim($soldOutRow->filter('[data-sold-out]')->text()));
        self::assertCount(0, $soldOutRow->filter('input'));
        self::assertCount(1, $crawler->filter('[data-low-stock]'));

        // Fixed bar: count, total and the single wheat button.
        self::assertSelectorTextContains('[data-order-bar]', 'Panier vide');
        self::assertSelectorTextContains('[data-order-bar] button[type="submit"]', 'Valider ma commande');
        self::assertCount(1, $crawler->filter('main .bg-wheat'));
        self::assertSelectorExists('form[data-controller="order-form"]');

        // Guests give their identity; nothing about payment being recorded.
        self::assertSelectorExists('input[name="order_form[email]"]');
        self::assertSelectorExists('input[name="order_form[firstName]"]');
        self::assertSelectorExists('input[name="order_form[lastName]"]');
        self::assertSelectorExists('input[name="order_form[phone]"]:not([required])');
        self::assertStringNotContainsStringIgnoringCase('payé', (string) $client->getResponse()->getContent());
    }

    public function testAGuestOrdersWithoutBeingLoggedInAndSeesTheConfirmation(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $shop->setPaymentInfo('Wero au 06 12 34 56 78, espèces');
        $sale = $this->createSale($shop);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $saleUrl = $this->saleUrl($sale);
        $deliveryId = (string) $this->deliveryOf($sale)->getId();

        $client->request('GET', $saleUrl);
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => $deliveryId,
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '2',
            \sprintf('order_form[quantities][%d]', self::idOf($baguette)) => '3',
            'order_form[note]' => 'Bien cuites !',
            'order_form[email]' => 'Nouvelle.Cliente@Example.test',
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[phone]' => '',
        ]);

        $order = $this->onlyOrder();
        $confirmationUrl = \sprintf('%s/merci/%s', $saleUrl, $order->getReference());
        self::assertResponseRedirects($confirmationUrl, 303);

        // The customer exists, the order is theirs, the stock is reserved…
        $customer = $order->getCustomer();
        self::assertSame('nouvelle.cliente@example.test', $customer->getEmail());
        self::assertSame('Jeanne Durand', $order->getCustomerDisplayName());
        self::assertNull($order->getCustomerPhone());
        // A guest cannot prove they own the address: the account's profile stays untouched.
        self::assertNull($customer->getFirstName());
        self::assertNull($customer->getLastName());
        self::assertSame(2 * 650 + 3 * 120, $order->getTotalCents());
        self::assertSame('Bien cuites !', $order->getNote());
        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(3, $this->reservedQty($baguette));

        // …but nobody has been logged in.
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertNull(self::getContainer()->get(TokenStorageInterface::class)->getToken());
        $client->request('GET', '/mes-commandes/'.$order->getReference());
        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));

        // Confirmation: summary, amount due on delivery, accepted means of payment, calendar link.
        self::assertSame('C\'est noté ! 🧺', trim($crawler->filter('h1')->text()));
        self::assertSame($order->getReference(), trim($crawler->filter('[data-order-reference]')->text()));
        self::assertStringContainsString('2 × Brioche', $crawler->filter('[data-order-summary]')->text());
        self::assertStringContainsString('3 × Baguette', $crawler->filter('[data-order-summary]')->text());
        self::assertStringContainsString('À régler à la livraison', $crawler->filter('[data-order-summary]')->text());
        self::assertStringContainsString("16,60\u{a0}€", $crawler->filter('[data-order-total]')->text());
        self::assertStringContainsString('Wero au 06 12 34 56 78, espèces', $crawler->filter('[data-payment-info]')->text());
        self::assertStringContainsString('Tu peux modifier ou annuler ta commande jusqu\'au', $crawler->filter('[data-edit-notice]')->text());
        self::assertStringContainsString('/connexion', (string) $crawler->filter('[data-edit-notice] a')->attr('href'));
        self::assertCount(1, $crawler->filter('main .bg-wheat'));
        self::assertStringNotContainsStringIgnoringCase('payé', $crawler->filter('main')->text());

        $icsUrl = (string) $crawler->filter('[data-ics-link]')->attr('href');
        self::assertSame($confirmationUrl.'/calendrier.ics', $icsUrl);
        $client->request('GET', $icsUrl);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        self::assertStringContainsString('BEGIN:VEVENT', (string) $client->getResponse()->getContent());

        // Another visitor (another session) cannot see this confirmation nor its calendar file.
        $client->getCookieJar()->clear();
        $client->request('GET', $confirmationUrl);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $icsUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestUsingAKnownEmailIsAttachedToThatCustomerWithoutChangingTheProfile(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $known = $this->createCustomer('lea@example.test', 'Léa', 'Martin');
        $knownId = $known->getId();

        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '1',
            'order_form[email]' => 'LEA@example.test',
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => 'Usurpateur',
            'order_form[lastName]' => 'Anonyme',
            'order_form[phone]' => '07 00 00 00 00',
        ]);

        self::assertResponseRedirects();
        $order = $this->onlyOrder();
        self::assertSame($knownId, $order->getCustomer()->getId());
        self::assertSame('Léa Martin', $order->getCustomer()->getDisplayName());
        self::assertSame('06 12 34 56 78', $order->getCustomer()->getPhone());
        self::assertSame(1, self::getContainer()->get(CustomerRepository::class)->count([]));

        // Still a guest: the account owner's pages stay behind the login.
        $client->request('GET', '/mes-commandes/'.$order->getReference());
        self::assertResponseRedirects();
    }

    public function testALoggedInCustomerOrdersWithPrefilledDetails(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer('lea@example.test', 'Léa', 'Martin');
        $customerId = $customer->getId();
        $saleUrl = $this->saleUrl($sale);
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', $saleUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="order_form[email]"]');
        self::assertSelectorTextContains('main', 'Tu commandes avec lea@example.test');
        self::assertSame('Léa', $crawler->filter('input[name="order_form[firstName]"]')->attr('value'));
        self::assertSame('Martin', $crawler->filter('input[name="order_form[lastName]"]')->attr('value'));
        self::assertSame('06 12 34 56 78', $crawler->filter('input[name="order_form[phone]"]')->attr('value'));
        // A single delivery is preselected.
        self::assertCount(1, $crawler->filter('input[name="order_form[delivery]"][checked]'));
        self::assertSelectorNotExists('[data-resume-order]');

        $client->submitForm('Valider ma commande', [
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '2',
            'order_form[phone]' => '06 99 88 77 66',
        ]);

        $order = $this->onlyOrder();
        self::assertResponseRedirects(\sprintf('%s/merci/%s', $saleUrl, $order->getReference()), 303);
        self::assertSame($customerId, $order->getCustomer()->getId());
        self::assertSame('06 99 88 77 66', $order->getCustomer()->getPhone());

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'C\'est noté !');
        self::assertSame('/mes-commandes/'.$order->getReference().'/modifier', $crawler->filter('[data-edit-notice] a')->attr('href'));

        // With an active order on this sale, the order page sends to its edition.
        $client->request('GET', $saleUrl);
        self::assertResponseRedirects('/mes-commandes/'.$order->getReference().'/modifier');

        // Someone else, logged in, cannot open this confirmation.
        $client->loginUser($this->reload(Customer::class, $this->createOtherCustomerId()), 'main');
        $client->request('GET', \sprintf('%s/merci/%s', $saleUrl, $order->getReference()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testStockErrorShowsTheFormAgainWithUpToDateQuantities(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $saleUrl = $this->saleUrl($sale);
        $deliveryId = (string) $this->deliveryOf($sale)->getId();

        $client->request('GET', $saleUrl);

        // While the visitor fills the form, someone else takes three brioches.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE sale_offer SET reserved_qty = 3000 WHERE id = ?', [self::idOf($brioche)]);

        $crawler = $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => $deliveryId,
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '4',
            \sprintf('order_form[quantities][%d]', self::idOf($baguette)) => '1',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-stock-alert]', 'Il ne reste plus que 2 « Brioche ».');
        self::assertSelectorTextContains('[data-stock-alert]', 'On a ajusté ton panier');

        $briocheInput = $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)));
        self::assertSame('2', $briocheInput->attr('value'));
        self::assertSame('2', $briocheInput->attr('max'));
        self::assertSame('Plus que 2', trim($crawler->filter('[data-low-stock]')->text()));
        self::assertSame('1', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($baguette)))->attr('value'));
        self::assertSame('jeanne@example.test', $crawler->filter('input[name="order_form[email]"]')->attr('value'));
        self::assertSelectorTextContains('[data-order-bar]', '3 articles');
        self::assertSelectorTextContains('[data-order-bar]', "14,20\u{a0}€");

        // Nothing was created nor reserved.
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
        self::assertSame(0, self::getContainer()->get(CustomerRepository::class)->count([]));
        self::assertSame(3, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($baguette));

        // Validating the adjusted basket works.
        $client->submitForm('Valider ma commande');
        self::assertResponseRedirects();
        self::assertSame(5, $this->reservedQty($brioche));
        self::assertSame(2 * 650 + 120, $this->onlyOrder()->getTotalCents());
    }

    public function testValidationErrorsAreShownInFrench(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $home = $this->addHomeDelivery($sale);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $homeId = (string) $home->getId();

        // Empty basket, no delivery chosen, no identity.
        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choisis au moins un produit.');
        self::assertSelectorTextContains('main', 'Choisis où récupérer ta commande.');
        self::assertSelectorTextContains('main', 'Indique une adresse e-mail.');
        self::assertSelectorTextContains('main', 'Indique un prénom.');

        // Home delivery without an address.
        $client->submitForm('Valider ma commande', [
            'order_form[delivery]' => $homeId,
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '1',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[acceptTerms]' => '1',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Indique ton adresse pour la livraison à domicile.');
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));

        $client->submitForm('Valider ma commande', ['order_form[deliveryAddress]' => '12 route des Écoliers, 26400 Saoû']);
        self::assertResponseRedirects();
        self::assertSame('12 route des Écoliers, 26400 Saoû', $this->onlyOrder()->getDeliveryAddress());
    }

    public function testAReturningCustomerCanResumeTheirPreviousOrder(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $customer = $this->createCustomer();

        $pastSale = $this->createSale($shop);
        $pastBrioche = $this->createOffer($pastSale, 'Brioche', 650);
        $pastBaguette = $this->createOffer($pastSale, 'Baguette', 120);
        $pastGone = $this->createOffer($pastSale, 'Galette des rois', 1800);
        $this->placeOrder($pastSale, $customer, [self::idOf($pastBrioche) => 4, self::idOf($pastBaguette) => 2, self::idOf($pastGone) => 1]);

        // New sale: same brioche (only 3 left) and baguette, no galette.
        $sale = $this->createSale($shop);
        $brioche = SaleOfferFactory::createOne(['sale' => $sale, 'product' => $pastBrioche->getProduct(), 'maxQty' => 3000]);
        $baguette = SaleOfferFactory::createOne(['sale' => $sale, 'product' => $pastBaguette->getProduct()]);
        $saleUrl = $this->saleUrl($sale);
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', $saleUrl);
        self::assertResponseIsSuccessful();
        self::assertSame('Reprendre ma commande précédente', trim($crawler->filter('[data-resume-order]')->text()));
        self::assertSame('0', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)))->attr('value'));

        $crawler = $client->click($crawler->filter('[data-resume-order]')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('3', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)))->attr('value'));
        self::assertSame('2', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($baguette)))->attr('value'));
        self::assertSelectorTextContains('[data-order-bar]', '5 articles');
        self::assertSelectorNotExists('[data-resume-order]');

        $client->submitForm('Valider ma commande');
        self::assertResponseRedirects();
        self::assertSame(3, $this->reservedQty($brioche));
    }

    public function testNoFormWhenTheSaleDoesNotTakeOrders(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $cases = [
            'Les commandes ne sont pas encore ouvertes' => SaleFactory::new()->scheduled()->create(['shop' => $shop]),
            'Les commandes sont closes' => SaleFactory::new()->closed()->create(['shop' => $shop]),
            'Cette vente est terminée' => SaleFactory::new()->done()->create(['shop' => $shop]),
            'Cette vente est annulée' => SaleFactory::new()->cancelled()->create(['shop' => $shop]),
        ];
        // Still flagged "open" but the deadline has just passed (scheduler not run yet).
        $late = SaleFactory::createOne(['shop' => $shop, 'deadlineAt' => new \DateTimeImmutable('-5 minutes')]);

        $urls = [];
        foreach ($cases as $message => $sale) {
            SaleDeliveryFactory::createOne(['sale' => $sale]);
            $this->createOffer($sale, 'Brioche', 650, 5);
            $urls[$message] = $this->saleUrl($sale);
        }
        $lateUrl = $this->saleUrl($late);

        foreach ($urls as $message => $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $message);
            self::assertSelectorNotExists('form');
            self::assertSelectorNotExists('input');

            // Posting anyway creates nothing.
            $client->request('POST', $url, ['order_form' => ['email' => 'x@example.test']]);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('form');
        }

        $client->request('GET', $lateUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Les commandes sont closes');
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testUnknownShopOrSaleAnswers404(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $otherShop = $this->shopOf($this->createProducer());
        $sale = $this->createSale($shop);
        $code = $sale->getCode();
        $slug = $shop->getSlug();
        $otherSlug = $otherShop->getSlug();

        $client->request('GET', \sprintf('/boutique-inconnue/vente/%s', $code));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', \sprintf('/%s/vente/ZZZZZZ', $slug));
        self::assertResponseStatusCodeSame(404);
        // A sale is only reachable under its own shop.
        $client->request('GET', \sprintf('/%s/vente/%s', $otherSlug, $code));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', \sprintf('/%s/vente/%s/merci/PS-AAAA-BBBB', $slug, $code));
        self::assertResponseStatusCodeSame(404);
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

    private function saleUrl(Sale $sale): string
    {
        return \sprintf('/%s/vente/%s', $sale->getShop()->getSlug(), $sale->getCode());
    }

    private function onlyOrder(): Order
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findBy([], ['id' => 'DESC']);
        self::assertNotEmpty($orders);

        return $orders[0];
    }

    private function createOtherCustomerId(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Customer('autre@example.test');
        $entityManager->persist($other);
        $entityManager->flush();

        return $other->getId() ?? throw new \LogicException('Customer not persisted.');
    }
}
