<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Exception\FirstOrderCapExceededException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\NewCustomerPolicy;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Protection of new customers (both settings off by default): a cap on a first order, and a deposit
 * asked outside the platform, shown as a text and never recorded (SPEC §1).
 */
final class NewCustomerProtectionTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    private KernelBrowser $client;
    private User $producer;
    private Shop $shop;
    private Sale $sale;
    private SaleOffer $bread;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->producer = $this->createProducer();
        $this->shop = $this->shopOf($this->producer);
        $this->shop->setName('Fournil du Pont')->setPhone('06 12 34 56 78')->setFirstOrderCapCents(3000)->setNewCustomerDeposit('5 € par Wero à la commande');
        $this->sale = $this->createSale($this->shop);
        $this->bread = $this->createOffer($this->sale, 'Pain de campagne', 1000);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function testTheSalePageTellsNewCustomersAboutTheCapAndTheDeposit(): void
    {
        $this->client->request('GET', $this->salePath());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-new-customer-notice]', 'Nouveau client chez Fournil du Pont ?');
        self::assertSelectorTextContains('[data-first-order-cap]', "va jusqu'à 30,00\u{a0}€");
        self::assertSelectorTextContains('[data-first-order-cap]', '06 12 34 56 78');
        self::assertSelectorTextContains('[data-new-customer-deposit]', '5 € par Wero à la commande');
        self::assertSelectorTextContains('[data-new-customer-deposit]', 'hors de Panier Sauvage');
    }

    public function testAGuestAboveTheCapIsPolitelyRefusedAndKeepsTheirBasket(): void
    {
        $this->client->request('GET', $this->salePath());
        $crawler = $this->client->submitForm('Valider ma commande', $this->guestForm(4));

        self::assertResponseStatusCodeSame(422);
        $message = $crawler->filter('[data-first-order-refusal]')->text();
        self::assertStringContainsString("Pour une première commande, Fournil du Pont accepte 30,00\u{a0}€ au plus en ligne.", $message);
        self::assertStringContainsString('appelle Fournil du Pont au 06 12 34 56 78', $message);
        self::assertStringEndsWith('vous verrez ensemble pour la suite. Tu as déjà retiré une commande ici ? Connecte-toi pour commander sans limite.', trim($message));
        self::assertSame('4', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($this->bread)))->attr('value'));
        self::assertSame(0, $this->reservedQty($this->bread));
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
        self::assertEmailCount(0);
    }

    public function testAGuestWithinTheCapOrdersAndIsToldAboutTheDeposit(): void
    {
        $this->client->request('GET', $this->salePath());
        $this->client->submitForm('Valider ma commande', $this->guestForm(3));

        self::assertResponseRedirects();
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'acompte demandé, <strong>5 € par Wero à la commande</strong>');
        self::assertEmailTextBodyContains($email, 'Première commande chez Fournil du Pont : acompte demandé, 5 € par Wero à la commande.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-new-customer-deposit]', '5 € par Wero à la commande');
        self::assertSelectorNotExists('[data-first-order-cap]');
    }

    public function testAGuestUsingTheAddressOfARegularIsStillCapped(): void
    {
        $lea = $this->createCustomer();
        $this->pickedUpOrder($lea);

        $this->client->request('GET', $this->salePath());
        $this->client->submitForm('Valider ma commande', ['order_form[email]' => 'lea@example.test'] + $this->guestForm(4));

        self::assertResponseStatusCodeSame(422, 'Typing an address proves nothing: the answer does not depend on it.');
    }

    public function testASignedInRegularIsNeitherCappedNorAskedForADeposit(): void
    {
        $lea = $this->createCustomer();
        $this->pickedUpOrder($lea);
        $this->client->loginUser($lea, 'main');

        $this->client->request('GET', $this->salePath());
        self::assertSelectorNotExists('[data-new-customer-notice]');

        $this->client->submitForm('Valider ma commande', [\sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '5']);
        self::assertResponseRedirects();
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyNotContains($email, 'acompte');
    }

    public function testASignedInNewCustomerIsCappedWithoutTheSignInHint(): void
    {
        $lea = $this->createCustomer();
        // Ordered before, but never came: still a new customer.
        OrderFactory::createOne(['sale' => $this->pastSale(), 'customer' => $lea, 'status' => OrderStatus::NotDelivered]);
        $this->client->loginUser($lea, 'main');

        $crawler = $this->client->request('GET', $this->salePath());
        self::assertSelectorTextContains('[data-new-customer-notice]', 'Première commande chez Fournil du Pont');

        $crawler = $this->client->submitForm('Valider ma commande', [\sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '4']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('Connecte-toi', $crawler->filter('[data-first-order-refusal]')->text());
        self::assertSelectorExists('[data-resume-order]', 'The previous order can still be resumed.');
    }

    public function testANewCustomerCannotRaiseTheirOrderAboveTheCapButCanLowerIt(): void
    {
        $lea = $this->createCustomer();
        $order = $this->placeOrder($this->sale, $lea, [self::idOf($this->bread) => 2]);
        $this->client->loginUser($lea, 'main');

        $this->client->request('GET', '/mes-commandes/'.$order->getReference().'/modifier');
        $crawler = $this->client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '4']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-first-order-refusal]', 'accepte 30,00');
        self::assertSame(2, $this->reservedQty($this->bread));

        // The cap lowered below an existing order: lowering it is always possible.
        $this->shop->setFirstOrderCapCents(500);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '1']);
        self::assertResponseRedirects('/mes-commandes/'.$order->getReference());
        self::assertSame(1, $this->reservedQty($this->bread));
        unset($crawler);
    }

    public function testTheProducerIsNeverCapped(): void
    {
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 10]);

        self::assertSame(10000, $order->getTotalCents());
    }

    public function testTheCustomerSideOfTheManagerChecksTheCap(): void
    {
        $manager = self::getContainer()->get(OrderManager::class);
        $request = new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(3)]);

        $order = $manager->placeOrder($this->sale, $this->createCustomer(), $request, Actor::Customer);
        self::assertSame(3000, $order->getTotalCents(), 'Exactly the cap is allowed.');

        $this->expectException(FirstOrderCapExceededException::class);
        $manager->placeOrder($this->sale, $this->createCustomer('paul@example.test'), new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(4)]), Actor::Customer);
    }

    public function testWithoutPhoneTheRefusalGivesTheEmail(): void
    {
        $this->shop->setPhone(null)->setContactEmail('fournil@example.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', $this->salePath());
        $this->client->submitForm('Valider ma commande', $this->guestForm(4));

        self::assertSelectorTextContains('[data-first-order-refusal]', 'écris à Fournil du Pont (fournil@example.test)');
    }

    public function testBothSettingsAreOffByDefault(): void
    {
        $shop = $this->shopOf($this->createProducer());
        $sale = $this->createSale($shop);
        $offer = $this->createOffer($sale, 'Brioche', 9000);

        self::assertNull($shop->getFirstOrderCapCents());
        self::assertNull($shop->getNewCustomerDeposit());
        $this->client->request('GET', \sprintf('/%s/vente/%s', $shop->getSlug(), $sale->getCode()));
        self::assertSelectorNotExists('[data-new-customer-notice]');

        $this->client->submitForm('Valider ma commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($offer)) => '3',
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ]);
        self::assertResponseRedirects();
    }

    public function testTheProducerSetsBothInTheShopSettings(): void
    {
        $producer = $this->createProducer();
        $this->client->loginUser($producer, 'producer');

        $this->client->request('GET', '/pro/boutique');
        self::assertSelectorTextContains('[data-section="new-customers"] h2', 'Nouveaux clients');
        $this->client->submitForm('Enregistrer', [
            'shop_settings[firstOrderCapCents]' => '25',
            'shop_settings[newCustomerDeposit]' => '  10 € en espèces au premier retrait  ',
        ]);
        self::assertResponseRedirects('/pro/boutique');

        $shop = $this->reload(Shop::class, (int) $this->shopOf($producer)->getId());
        self::assertSame(2500, $shop->getFirstOrderCapCents());
        self::assertSame('10 € en espèces au premier retrait', $shop->getNewCustomerDeposit());

        $this->client->request('GET', '/pro/boutique');
        $this->client->submitForm('Enregistrer', ['shop_settings[firstOrderCapCents]' => '2']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-section="new-customers"]', 'Choisis un plafond entre 5 € et 1 000 €.');

        $this->client->submitForm('Enregistrer', ['shop_settings[firstOrderCapCents]' => '', 'shop_settings[newCustomerDeposit]' => '']);
        $shop = $this->reload(Shop::class, (int) $shop->getId());
        self::assertNull($shop->getFirstOrderCapCents());
        self::assertNull($shop->getNewCustomerDeposit());
    }

    public function testTheCapComparesTheExactTotal(): void
    {
        $this->shop->setFirstOrderCapCents(2999);
        $brioche = $this->createOffer($this->sale, 'Brioche', 2000);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $manager = self::getContainer()->get(OrderManager::class);

        try {
            $manager->placeOrder($this->sale, $this->createCustomer(), new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(3)]), Actor::Customer);
            self::fail('30,00 € is above a cap of 29,99 €.');
        } catch (FirstOrderCapExceededException) {
        }

        $this->expectException(FirstOrderCapExceededException::class);
        $manager->placeOrder($this->sale, $this->createCustomer('paul@example.test'), new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(1), self::idOf($brioche) => Quantity::units(1)]), Actor::Customer);
    }

    public function testAnEditKeepsTheFrozenPricesOfTheOrder(): void
    {
        $lea = $this->createCustomer();
        $order = $this->placeOrder($this->sale, $lea, [self::idOf($this->bread) => 2]);
        $this->bread->setPriceCents(2500);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($lea, 'main');

        $this->client->request('GET', '/mes-commandes/'.$order->getReference().'/modifier');
        $this->client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '3']);

        self::assertResponseRedirects('/mes-commandes/'.$order->getReference(), message: '3 × 10,00 € frozen: exactly the cap.');
    }

    public function testAnEditThatKeepsTheTotalIsAllowedAboveANewCap(): void
    {
        $lea = $this->createCustomer();
        $order = $this->placeOrder($this->sale, $lea, [self::idOf($this->bread) => 3]);
        $this->shop->setFirstOrderCapCents(1000);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $manager = self::getContainer()->get(OrderManager::class);

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(3)], 'Bien cuit'), Actor::Customer);
        self::assertSame('Bien cuit', $order->getNote());

        $manager->updateOrder($order, new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->bread) => Quantity::units(6)]), Actor::Producer);
        self::assertSame(6000, $order->getTotalCents(), 'The producer is never capped.');
    }

    public function testARegularWithSeveralPickedUpOrdersIsKnown(): void
    {
        $lea = $this->createCustomer();
        $this->pickedUpOrder($lea);
        $this->pickedUpOrder($lea);

        self::assertFalse(self::getContainer()->get(NewCustomerPolicy::class)->isNewCustomer($this->shop, $lea));
        self::assertTrue(self::getContainer()->get(NewCustomerPolicy::class)->isNewCustomer($this->shopOf($this->createProducer()), $lea), 'Known here, new elsewhere.');
        self::assertTrue(self::getContainer()->get(NewCustomerPolicy::class)->isNewCustomer($this->shop, null));
    }

    /**
     * @return iterable<string, array{?string, ?string, bool, string}>
     */
    public static function provideContacts(): iterable
    {
        yield 'the phone, trimmed' => ['  06 12 34 56 78 ', null, true, 'ou appelle Fournil du Pont au 06 12 34 56 78 : vous verrez'];
        yield 'a blank phone: the e-mail' => ['   ', 'fournil@example.test', true, 'ou écris à Fournil du Pont (fournil@example.test) : vous verrez'];
        yield 'nothing to reach them' => [null, null, false, 'ou contacte directement Fournil du Pont : vous verrez'];
    }

    #[DataProvider('provideContacts')]
    public function testTheRefusalGivesTheBestContact(?string $phone, ?string $contactEmail, bool $withOwner, string $expected): void
    {
        $shop = new Shop('Fournil du Pont', 'fournil-du-pont-2')->setPhone($phone)->setContactEmail($contactEmail)->setFirstOrderCapCents(1000);
        if ($withOwner) {
            $shop->addUser(new User('owner@example.test'));
        }

        try {
            self::getContainer()->get(NewCustomerPolicy::class)->assertWithinFirstOrderCap($shop, new Customer('jeanne@example.test'), 1001);
            self::fail('Above the cap.');
        } catch (FirstOrderCapExceededException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringContainsString("accepte 10,00\u{a0}€ au plus en ligne", $exception->getMessage());
        }

        self::getContainer()->get(NewCustomerPolicy::class)->assertWithinFirstOrderCap($shop, new Customer('jeanne@example.test'), 1000);
        self::getContainer()->get(NewCustomerPolicy::class)->assertWithinFirstOrderCap($shop->setFirstOrderCapCents(null), new Customer('jeanne@example.test'), 99999);
    }

    public function testAnOrderEnteredByTheProducerAsksNoDeposit(): void
    {
        $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyNotContains($email, 'acompte');
        self::assertEmailTextBodyNotContains($email, 'acompte');
    }

    private function salePath(): string
    {
        return \sprintf('/%s/vente/%s', $this->shop->getSlug(), $this->sale->getCode());
    }

    /**
     * @return array<string, string>
     */
    private function guestForm(int $breads): array
    {
        return [
            'order_form[delivery]' => (string) $this->deliveryOf($this->sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => (string) $breads,
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ];
    }

    private function pastSale(): Sale
    {
        return SaleFactory::createOne(['shop' => $this->shop, 'status' => SaleStatus::Done, 'deliveryDate' => new \DateTimeImmutable('-7 days'), 'opensAt' => new \DateTimeImmutable('-14 days'), 'deadlineAt' => new \DateTimeImmutable('-10 days')]);
    }

    private function pickedUpOrder(Customer $customer): Order
    {
        return OrderFactory::createOne(['sale' => $this->pastSale(), 'customer' => $customer, 'status' => OrderStatus::Delivered]);
    }
}
