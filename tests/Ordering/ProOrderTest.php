<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Customer\Repository\CustomerRepository;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Repository\OrderRepository;
use App\Shared\Enum\Actor;
use App\Shop\Entity\User;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProOrderTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testAProducerAddsAnOrderTakenOnThePhone(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $saleId = self::idOf($sale);
        $deliveryId = (string) $this->deliveryOf($sale)->getId();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ajouter une commande');
        self::assertSelectorExists('input[name="order_form[email]"]');
        self::assertCount(2, $crawler->filter('input[type="number"]'));
        self::assertSelectorTextContains(\sprintf('[data-offer="%d"] [data-available]', self::idOf($brioche)), '5');
        self::assertSelectorTextContains(\sprintf('[data-offer="%d"] [data-available]', self::idOf($baguette)), 'Illimité');
        self::assertSelectorNotExists('[data-after-deadline]');
        self::assertStringNotContainsStringIgnoringCase('payé', $crawler->filter('main')->text());

        $client->submitForm('Ajouter la commande', [
            'order_form[email]' => 'mamie.odette@example.test',
            'order_form[firstName]' => 'Odette',
            'order_form[lastName]' => 'Blanc',
            'order_form[phone]' => '04 75 11 22 33',
            'order_form[delivery]' => $deliveryId,
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '2',
            \sprintf('order_form[quantities][%d]', self::idOf($baguette)) => '1',
            'order_form[note]' => 'Prise au téléphone',
        ]);

        // pro_sale_show belongs to another work package: fallback path.
        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', $saleId), 303);

        $order = $this->lastOrder();
        self::assertSame('mamie.odette@example.test', $order->getCustomer()->getEmail());
        self::assertSame('Odette Blanc', $order->getCustomerDisplayName());
        self::assertSame('04 75 11 22 33', $order->getCustomerPhone());
        // The identity lives on the order: a producer never fills in a customer's profile.
        self::assertNull($order->getCustomer()->getFirstName());
        self::assertNull($order->getCustomer()->getPhone());
        self::assertSame(2 * 650 + 120, $order->getTotalCents());
        self::assertSame('Prise au téléphone', $order->getNote());
        self::assertSame(2, $this->reservedQty($brioche));
        $event = $order->getEvents()->first();
        self::assertNotFalse($event);
        self::assertSame(OrderEventType::Placed, $event->getType());
        self::assertSame(Actor::Producer, $event->getActor());
    }

    public function testAnOrderForAKnownEmailIsAttachedToTheExistingCustomer(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $known = $this->createCustomer('lea@example.test', 'Zéphyrine', 'Okonkwo-Vasseur');
        $knownId = $known->getId();
        $saleId = self::idOf($sale);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId));
        $client->submitForm('Ajouter la commande', [
            'order_form[email]' => 'Lea@Example.test',
            'order_form[firstName]' => 'Lea',
            'order_form[lastName]' => 'M.',
            'order_form[phone]' => '04 75 00 00 00',
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '1',
        ]);

        self::assertResponseRedirects();
        $order = $this->lastOrder();
        self::assertSame($knownId, $order->getCustomer()->getId());
        self::assertSame(1, self::getContainer()->get(CustomerRepository::class)->count([]));

        // The account's profile is neither changed nor shown: only what the producer typed.
        self::assertSame('Zéphyrine Okonkwo-Vasseur', $order->getCustomer()->getDisplayName());
        self::assertSame('06 12 34 56 78', $order->getCustomer()->getPhone());
        self::assertSame('Lea M.', $order->getCustomerDisplayName());
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Commande ajoutée pour Lea M.');

        foreach (['', '/livraison', '/commandes.csv', \sprintf('/commandes/%d/modifier', self::idOf($order))] as $page) {
            $client->request('GET', \sprintf('/pro/ventes/%d%s', $saleId, $page));
            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('M.', $content, $page);
            self::assertStringNotContainsString('Zéphyrine', $content, $page);
            self::assertStringNotContainsString('Okonkwo-Vasseur', $content, $page);
            self::assertStringNotContainsString('06 12 34 56 78', $content, $page);
        }
    }

    public function testStockAndValidationErrorsOnTheProducerForm(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 2);
        $saleId = self::idOf($sale);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId));
        $client->submitForm('Ajouter la commande', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choisis au moins un produit.');
        self::assertSelectorTextContains('main', 'Sans e-mail, indique au moins un téléphone pour joindre ce client.');
        self::assertSelectorTextContains('main', 'Indique un nom.');

        // The browser "max" attribute is only a hint: the server refuses the third brioche.
        $form = $client->getCrawler()->selectButton('Ajouter la commande')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['order_form']);
        $values['order_form']['email'] = 'odette@example.test';
        $values['order_form']['firstName'] = 'Odette';
        $values['order_form']['lastName'] = 'Blanc';
        $values['order_form']['quantities'] = [self::idOf($brioche) => '3'];
        $crawler = $client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-stock-alert]', 'Il ne reste plus que 2 « Brioche ».');
        self::assertSame('2', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)))->attr('value'));
        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testAProducerEditsAndCancelsAnOrderEvenAfterTheDeadline(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = SaleFactory::new()->closed()->create(['shop' => $this->shopOf($producer)]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $order = $this->placeOrder($sale, $this->createCustomer('lea@example.test', 'Zéphyrine', 'Martin'), [self::idOf($brioche) => 2]);
        $saleId = self::idOf($sale);
        $orderId = self::idOf($order);
        $client->loginUser($producer, 'producer');

        // Adding is possible after the deadline too.
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-after-deadline]');

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Commande de Zéphyrine Martin');
        self::assertSelectorNotExists('input[name="order_form[email]"]');
        self::assertSame('2', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($brioche)))->attr('value'));
        self::assertSelectorTextContains(\sprintf('[data-offer="%d"] [data-available]', self::idOf($brioche)), '5');

        $client->submitForm('Enregistrer la commande', [
            \sprintf('order_form[quantities][%d]', self::idOf($brioche)) => '4',
            \sprintf('order_form[quantities][%d]', self::idOf($baguette)) => '2',
        ]);

        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', $saleId), 303);
        self::assertSame(4, $this->reservedQty($brioche));
        $stored = $this->reload(Order::class, $orderId);
        self::assertSame(4 * 650 + 2 * 120, $stored->getTotalCents());
        $last = $stored->getEvents()->last();
        self::assertNotFalse($last);
        self::assertSame(OrderEventType::Updated, $last->getType());
        self::assertSame(Actor::Producer, $last->getActor());

        // Cancel: POST + CSRF only.
        $client->request('POST', \sprintf('/pro/ventes/%d/commandes/%d/annuler', $saleId, $orderId), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/annuler', $saleId, $orderId));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(4, $this->reservedQty($brioche));

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId));
        $client->submit($crawler->filter('form[action$="/annuler"]')->form());

        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', $saleId), 303);
        self::assertSame(OrderStatus::Cancelled, $this->reload(Order::class, $orderId)->getStatus());
        self::assertSame(0, $this->reservedQty($brioche));
        self::assertSame(0, $this->reservedQty($baguette));

        // A cancelled order is not editable any more.
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId));
        self::assertResponseRedirects(\sprintf('/pro/ventes/%d', $saleId));
    }

    public function testNothingChangesOnceTheSaleIsDoneOrCancelled(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $shop = $this->shopOf($producer);
        $customer = $this->createCustomer();

        $cases = [];
        foreach ([SaleFactory::new(), SaleFactory::new()] as $index => $factory) {
            $sale = $factory->create(['shop' => $shop]);
            SaleDeliveryFactory::createOne(['sale' => $sale]);
            $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
            $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
            // The sale ends after the order was taken.
            0 === $index ? $sale->close()->markDone() : $sale->cancel();
            $cases[] = [self::idOf($sale), self::idOf($order), $brioche];
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($producer, 'producer');

        foreach ($cases as [$saleId, $orderId, $brioche]) {
            $back = \sprintf('/pro/ventes/%d', $saleId);

            $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId));
            self::assertResponseRedirects($back);
            $client->request('POST', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId), ['order_form' => ['email' => 'x@example.test']]);
            self::assertResponseRedirects($back);

            $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId));
            self::assertResponseRedirects($back);
            $client->request('POST', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId), ['order_form' => ['quantities' => [self::idOf($brioche) => '5']]]);
            self::assertResponseRedirects($back);

            self::assertTrue($this->reload(Order::class, $orderId)->isConfirmed());
            self::assertSame(2, $this->reservedQty($brioche));
        }

        self::assertSame(2, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testAnotherProducerGets404OnEveryRoute(): void
    {
        $client = self::createClient();
        $owner = $this->createProducer();
        $intruder = $this->createProducer();
        $sale = $this->createSale($this->shopOf($owner));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2]);
        $saleId = self::idOf($sale);
        $orderId = self::idOf($order);

        // The intruder's own sale: an order cannot be reached through a sale it does not belong to.
        $intruderSale = $this->createSale($this->shopOf($intruder));
        $intruderSaleId = self::idOf($intruderSale);
        $client->loginUser($intruder, 'producer');

        $requests = [
            ['GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId)],
            ['POST', \sprintf('/pro/ventes/%d/commandes/nouvelle', $saleId)],
            ['GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId)],
            ['POST', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId)],
            ['POST', \sprintf('/pro/ventes/%d/commandes/%d/annuler', $saleId, $orderId)],
            ['GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $intruderSaleId, $orderId)],
            ['POST', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $intruderSaleId, $orderId)],
            ['POST', \sprintf('/pro/ventes/%d/commandes/%d/annuler', $intruderSaleId, $orderId)],
            ['GET', '/pro/ventes/999999/commandes/nouvelle'],
        ];

        foreach ($requests as [$method, $url]) {
            $client->request($method, $url, 'POST' === $method ? ['order_form' => ['quantities' => [self::idOf($brioche) => '0']], '_token' => 'x'] : []);
            self::assertResponseStatusCodeSame(404, \sprintf('%s %s must answer 404 for another producer.', $method, $url));
        }

        self::assertTrue($this->reload(Order::class, $orderId)->isConfirmed());
        self::assertSame(2, $this->reservedQty($brioche));
        self::assertSame(1, self::getContainer()->get(OrderRepository::class)->count([]));

        // The owner does reach them.
        $client->loginUser($this->reload(User::class, $owner->getId() ?? 0), 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', $saleId, $orderId));
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousVisitorsAndCustomersCannotEnter(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $customer = $this->createCustomer();
        $order = $this->placeOrder($sale, $customer, [self::idOf($brioche) => 2]);
        $requests = [
            ['GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($sale))],
            ['GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', self::idOf($sale), self::idOf($order))],
            ['POST', \sprintf('/pro/ventes/%d/commandes/%d/annuler', self::idOf($sale), self::idOf($order))],
        ];

        foreach ($requests as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseRedirects('/pro/connexion');
        }

        // A customer session does not open the producer area.
        $client->loginUser($customer, 'main');
        foreach ($requests as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseRedirects('/pro/connexion');
        }

        self::assertSame(2, $this->reservedQty($brioche));
    }

    private function lastOrder(): Order
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findBy([], ['id' => 'DESC']);
        self::assertNotEmpty($orders);

        return $orders[0];
    }
}
