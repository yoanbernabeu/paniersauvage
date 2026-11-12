<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class CustomerOrderTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testACustomerSeesTheirOrderAndCanStillChangeIt(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer());
        $shop->setName('Le Fournil du Pont')->setPaymentInfo('Wero, espèces');
        $sale = $this->createSale($shop);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', '/mes-commandes/'.$reference);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-shop-header]', 'Le Fournil du Pont');
        self::assertSelectorTextContains('[data-order-status]', 'Confirmée');
        self::assertSelectorTextContains('[data-order-summary]', '2 × Brioche');
        self::assertSelectorTextContains('[data-order-summary]', 'À régler à la livraison');
        self::assertSelectorTextContains('[data-order-total]', "13,00\u{a0}€");
        self::assertSelectorTextContains('[data-payment-info]', 'Wero, espèces');
        self::assertSelectorTextContains('[data-edit-notice]', 'Tu peux modifier ou annuler ta commande jusqu\'au');
        self::assertSelectorNotExists('[data-read-only-notice]');
        self::assertCount(1, $crawler->filter(\sprintf('a[href="/mes-commandes/%s/modifier"]', $reference)));
        self::assertCount(1, $crawler->filter(\sprintf('a[href="/mes-commandes/%s/calendrier.ics"]', $reference)));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/mes-commandes/%s/annuler"] input[name="_token"]', $reference)));
        self::assertCount(1, $crawler->filter('main .bg-wheat'));
        self::assertStringNotContainsStringIgnoringCase('payé', $crawler->filter('main')->text());

        // A lower-case reference works too.
        $client->request('GET', '/mes-commandes/'.mb_strtolower($reference));
        self::assertResponseIsSuccessful();

        $client->request('GET', \sprintf('/mes-commandes/%s/calendrier.ics', $reference));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('SUMMARY:Livraison Le Fournil du Pont', (string) $client->getResponse()->getContent());
    }

    public function testACustomerEditsTheirOrder(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $home = $this->addHomeDelivery($sale);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $orderId = self::idOf($order);
        $homeId = (string) $home->getId();
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $reference));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Modifier ma commande');
        // 2 of mine + 1 left: I may go up to 3.
        $briocheInput = $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)));
        self::assertSame('2', $briocheInput->attr('value'));
        self::assertSame('3', $briocheInput->attr('max'));
        self::assertSelectorTextContains('[data-low-stock]', 'Plus que 3');
        self::assertSelectorTextContains('[data-order-bar]', '2 articles');
        self::assertSelectorNotExists('input[name="order_form[email]"]');
        self::assertCount(1, $crawler->filter('main .bg-wheat'));

        // Asking for more than available: form again, quantities adjusted.
        $crawler = $client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '5']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-stock-alert]', 'Il ne reste plus que 3 « Brioche ».');
        self::assertSame('3', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)))->attr('value'));
        self::assertSame(4, $this->reservedQty($brioche));

        $client->submitForm('Enregistrer', [
            'order_form[delivery]' => $homeId,
            'order_form[deliveryAddress]' => '12 route des Écoliers',
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '3',
            \sprintf('order_form[quantities][%d]', self::idOf($baguette)) => '4',
            'order_form[note]' => 'Sonne deux fois',
        ]);

        self::assertResponseRedirects('/mes-commandes/'.$reference, 303);
        self::assertSame(5, $this->reservedQty($brioche));
        self::assertSame(4, $this->reservedQty($baguette));
        $stored = $this->reload(Order::class, $orderId);
        self::assertSame(3 * 650 + 4 * 120, $stored->getTotalCents());
        self::assertSame('12 route des Écoliers', $stored->getDeliveryAddress());
        self::assertSame('Sonne deux fois', $stored->getNote());
        self::assertTrue($stored->getSaleDelivery()->isHome());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'C\'est modifié');
        self::assertSelectorTextContains('[data-order-summary]', '4 × Baguette');
    }

    public function testRemovingEveryProductCancelsTheOrder(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $orderId = self::idOf($order);
        $client->loginUser($customer, 'main');

        $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $reference));
        $client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '0']);

        self::assertResponseRedirects('/mes-commandes/'.$reference, 303);
        self::assertSame(OrderStatus::Cancelled, $this->reload(Order::class, $orderId)->getStatus());
        self::assertSame(0, $this->reservedQty($brioche));

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ta commande est annulée.');
        self::assertSelectorTextContains('[data-order-status]', 'Annulée');
        self::assertSelectorNotExists('form[action$="/annuler"]');

        // A cancelled order cannot be edited any more.
        $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $reference));
        self::assertResponseRedirects('/mes-commandes/'.$reference);
    }

    public function testACustomerCancelsTheirOrder(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $orderId = self::idOf($order);
        $client->loginUser($customer, 'main');

        // Without a valid CSRF token nothing happens.
        $client->request('POST', \sprintf('/mes-commandes/%s/annuler', $reference), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $this->reservedQty($brioche));

        // GET is not allowed on the cancel route.
        $client->request('GET', \sprintf('/mes-commandes/%s/annuler', $reference));
        self::assertResponseStatusCodeSame(405);

        $crawler = $client->request('GET', '/mes-commandes/'.$reference);
        $client->submit($crawler->filter('form[action$="/annuler"]')->form());

        self::assertResponseRedirects('/mes-commandes/'.$reference, 303);
        self::assertSame(OrderStatus::Cancelled, $this->reload(Order::class, $orderId)->getStatus());
        self::assertSame(0, $this->reservedQty($brioche));

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ta commande est annulée.');
    }

    public function testAfterTheEditCutoffTheOrderIsReadOnlyWithTheProducerContact(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $shop = $this->shopOf($producer);
        $shop->setName('Le Fournil du Pont')->setPhone('04 75 00 00 00');
        $producerEmail = $producer->getEmail();
        $customer = $this->createCustomer();

        // Three ways of being too late: cutoff passed, editing disabled, sale closed.
        $sales = [
            $this->createSale($shop, ['editCutoffAt' => new \DateTimeImmutable('-1 hour')]),
            $this->createSale($shop, ['allowCustomerEdit' => false]),
            SaleFactory::new()->closed()->create(['shop' => $shop]),
        ];
        SaleDeliveryFactory::createOne(['sale' => $sales[2]]);

        $cases = [];
        foreach ($sales as $sale) {
            $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
            $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
            $cases[] = [$order->getReference(), self::idOf($order), $brioche];
        }
        $client->loginUser($customer, 'main');

        foreach ($cases as [$reference, $orderId, $brioche]) {
            $crawler = $client->request('GET', '/mes-commandes/'.$reference);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-read-only-notice]', 'n\'est plus modifiable en ligne');
            self::assertSelectorTextContains('[data-shop-contact]', '04 75 00 00 00');
            self::assertSelectorTextContains('[data-shop-contact]', $producerEmail);
            self::assertSelectorNotExists('form[action$="/annuler"]');
            self::assertCount(0, $crawler->filter('a[href$="/modifier"]'));
            self::assertSelectorTextContains('[data-order-summary]', '2 × Brioche');

            // Edit page: back to the read-only view.
            $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $reference));
            self::assertResponseRedirects('/mes-commandes/'.$reference);
            $client->request('POST', \sprintf('/mes-commandes/%s/modifier', $reference), ['order_form' => ['quantities' => [self::idOf($brioche) => '5']]]);
            self::assertResponseRedirects('/mes-commandes/'.$reference);

            self::assertTrue($this->reload(Order::class, $orderId)->isConfirmed());
            self::assertSame(2, $this->reservedQty($brioche));
        }
    }

    public function testCancellingOrSavingRightAfterTheCutoffIsRefused(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $orderId = self::idOf($order);
        $saleId = self::idOf($sale);
        $client->loginUser($customer, 'main');

        // Both pages are loaded while the order is still editable (valid CSRF tokens)…
        $showPage = $client->request('GET', '/mes-commandes/'.$reference);
        $cancelForm = $showPage->filter('form[action$="/annuler"]')->form();
        $editPage = $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $reference));
        $editForm = $editPage->selectButton('Enregistrer')->form([\sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '4']);

        // …then the edit cutoff passes.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE sale SET edit_cutoff_at = now() - interval '1 day' WHERE id = ?", [$saleId]);

        $client->submit($cancelForm);
        self::assertResponseRedirects('/mes-commandes/'.$reference, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'ne peut plus être modifiée en ligne');

        $client->submit($editForm);
        self::assertResponseRedirects('/mes-commandes/'.$reference);

        self::assertTrue($this->reload(Order::class, $orderId)->isConfirmed());
        self::assertSame(2, $this->reservedQty($brioche));
    }

    public function testACustomerNeverReachesSomeoneElsesOrder(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $owner = $this->createCustomer('lea@example.test');
        $intruder = $this->createCustomer('paul@example.test', 'Paul', 'Curieux');
        $order = $this->placeOrder($sale, $owner, [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $orderId = self::idOf($order);
        $confirmationUrl = \sprintf('/%s/vente/%s/merci/%s', $sale->getShop()->getSlug(), $sale->getCode(), $reference);
        $client->loginUser($intruder, 'main');

        $requests = [
            ['GET', '/mes-commandes/'.$reference],
            ['GET', \sprintf('/mes-commandes/%s/modifier', $reference)],
            ['POST', \sprintf('/mes-commandes/%s/modifier', $reference)],
            ['POST', \sprintf('/mes-commandes/%s/annuler', $reference)],
            ['GET', \sprintf('/mes-commandes/%s/calendrier.ics', $reference)],
            ['GET', $confirmationUrl],
            ['GET', $confirmationUrl.'/calendrier.ics'],
            ['GET', '/mes-commandes/PS-AAAA-BBBB'],
        ];

        foreach ($requests as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseStatusCodeSame(404, \sprintf('%s %s must answer 404 for another customer.', $method, $url));
        }

        self::assertTrue($this->reload(Order::class, $orderId)->isConfirmed());
        self::assertSame(2, $this->reservedQty($brioche));
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2]);
        $reference = $order->getReference();

        $requests = [
            ['GET', '/mes-commandes/'.$reference],
            ['GET', \sprintf('/mes-commandes/%s/modifier', $reference)],
            ['POST', \sprintf('/mes-commandes/%s/annuler', $reference)],
            ['GET', \sprintf('/mes-commandes/%s/calendrier.ics', $reference)],
        ];

        foreach ($requests as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseRedirects();
            self::assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));
        }

        self::assertSame(2, $this->reservedQty($brioche));
    }

    public function testAProducerSessionIsNotACustomerSession(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2]);
        $reference = $order->getReference();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/mes-commandes/'.$reference);

        self::assertResponseRedirects();
        self::assertStringContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'));
    }
}
