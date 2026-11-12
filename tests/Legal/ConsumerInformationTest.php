<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Catalog\Entity\Product;
use App\Ordering\Entity\Order;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Form\OrderFormType;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Ordering\OrderingFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Zenstruck\Foundry\Test\Factories;

/**
 * Distance selling: who sells, at what price, how to pay and pick up, the right of withdrawal
 * (non-perishable products only) and the rules for alcohol, before and after ordering.
 */
final class ConsumerInformationTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    private const string HEALTH_WARNING = 'L\'abus d\'alcool est dangereux pour la santé, à consommer avec modération.';

    public function testTheOrderPageNamesTheSellerAndHowToPay(): void
    {
        $client = self::createClient();
        $sale = $this->saleOf($this->producerShop());
        $this->createOffer($sale, 'Pain de campagne', 520);

        $client->request('GET', $this->saleUrl($sale));

        self::assertResponseIsSuccessful();
        $identity = '[data-precontractual] [data-seller-identity]';
        self::assertSelectorTextContains($identity, 'Fournil du Pont (Camille Dupont)');
        self::assertSelectorTextContains($identity, '12345678900015');
        self::assertSelectorTextContains($identity, '3 chemin du Pont, 26400 Saoû');
        self::assertSelectorExists($identity.' a[href="tel:0612345678"]');
        self::assertSelectorExists($identity.' a[href="mailto:camille@example.test"]');
        self::assertSelectorTextContains('[data-precontractual]', 'toutes taxes comprises');
        self::assertSelectorTextContains('[data-precontractual] [data-payment-info]', 'Moyens acceptés : Espèces ou Wero');
        // Only perishable food: no right of withdrawal, nothing about alcohol.
        self::assertSelectorTextContains('[data-withdrawal-info]', 'exclus du droit de rétractation');
        self::assertSelectorNotExists('[data-withdrawable]');
        self::assertSelectorNotExists('[data-alcohol-warning]');
        self::assertSelectorNotExists('[data-age-check]');
    }

    public function testAShopWithoutSiretNorAddressStillNamesItsSeller(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne(['firstName' => 'Léo', 'lastName' => 'Petit', 'email' => 'leo@example.test']);
        $sale = $this->saleOf($this->shopOf($producer));
        $this->createOffer($sale, 'Salade', 150);

        $client->request('GET', $this->saleUrl($sale));

        self::assertSelectorTextContains('[data-seller-identity]', 'Léo Petit');
        self::assertSelectorTextNotContains('[data-seller-identity]', 'SIRET');
        self::assertSelectorTextNotContains('[data-seller-identity]', 'Adresse');
        self::assertSelectorExists('[data-seller-identity] a[href="mailto:leo@example.test"]');
    }

    public function testNonPerishableProductsCarryTheRightOfWithdrawal(): void
    {
        $client = self::createClient();
        $sale = $this->saleOf($this->producerShop());
        $this->createOffer($sale, 'Pain de campagne', 520);
        $this->offerOf($sale, ProductFactory::new()->notPerishable(), 'Miel de lavande', 1100);

        $crawler = $client->request('GET', $this->saleUrl($sale));

        self::assertCount(1, $crawler->filter('[data-withdrawable]'));
        self::assertSelectorTextContains('[data-withdrawal-info]', '14 jours après le retrait');
        self::assertSelectorExists('[data-withdrawal-info] a[href="/cgu#formulaire-retractation"]');
    }

    public function testAnAlcoholicDrinkAsksForTheAgeOfTheBuyer(): void
    {
        $client = self::createClient();
        $sale = $this->saleOf($this->producerShop());
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $wine = $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Cornas 2022', 3200);

        $crawler = $client->request('GET', $this->saleUrl($sale));
        self::assertSelectorTextContains('[data-offer="'.self::idOf($wine).'"] [data-alcohol-warning]', self::HEALTH_WARNING);
        self::assertSelectorNotExists('[data-offer="'.self::idOf($bread).'"] [data-alcohol-warning]');
        self::assertSelectorTextContains('[data-precontractual] [data-alcohol-warning]', self::HEALTH_WARNING);
        self::assertSelectorTextContains('[data-age-check] label', 'Je certifie avoir 18 ans ou plus');
        self::assertNull($crawler->filter('[data-age-check] input')->attr('required'));
        self::assertCount(1, $crawler->filter('[data-withdrawable]'));

        // Wine in the basket, box not ticked: refused by the server.
        $client->submitForm('Valider ma commande', $this->guestFields($sale, [self::idOf($wine) => 2]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-age-check]', 'La vente d\'alcool est interdite aux mineurs');
        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));

        $client->submitForm('Valider ma commande', [...$this->guestFields($sale, [self::idOf($wine) => 2]), 'order_form[adultCertified]' => '1']);
        self::assertResponseRedirects();
        self::assertSame(1, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testBreadAloneNeedsNoAgeCertificationInASaleWithWine(): void
    {
        $client = self::createClient();
        $sale = $this->saleOf($this->producerShop());
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Cornas 2022', 3200);

        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', $this->guestFields($sale, [self::idOf($bread) => 1]));

        self::assertResponseRedirects();
    }

    public function testAddingWineWhileEditingAlsoAsksForTheAge(): void
    {
        $client = self::createClient();
        $sale = $this->saleOf($this->producerShop());
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $wine = $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Cornas 2022', 3200);
        $customer = CustomerFactory::createOne();
        $order = $this->placeOrder($sale, $customer, [self::idOf($bread) => 1]);
        $client->loginUser($customer, 'main');

        $client->request('GET', \sprintf('/mes-commandes/%s/modifier', $order->getReference()));
        self::assertSelectorExists('[data-age-section] [data-alcohol-warning]');

        $client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($wine)) => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-age-check]', 'certifie avoir 18 ans');

        $client->submitForm('Enregistrer', [\sprintf('order_form[quantities][%d]', self::idOf($wine)) => '1', 'order_form[adultCertified]' => '1']);
        self::assertResponseRedirects();
    }

    public function testTheProducerFormAsksNoAgeCertification(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $sale = $this->saleOf($this->shopOf($producer));
        $wine = $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Cornas 2022', 3200);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($sale)));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="order_form[adultCertified]"]');
        self::assertSelectorNotExists('input[name="order_form[acceptTerms]"]');

        // The producer checks the age at pick-up: nothing to certify here.
        $client->submitForm('Ajouter la commande', [
            'order_form[email]' => 'client@example.test',
            'order_form[firstName]' => 'Paul',
            'order_form[lastName]' => 'Martin',
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($wine)) => '2',
        ]);
        self::assertResponseRedirects();
        self::assertSame(1, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testTheOrderFormRefusesOptionsOfTheWrongType(): void
    {
        self::bootKernel();
        $sale = $this->saleOf($this->producerShop());
        $factory = self::getContainer()->get(FormFactoryInterface::class);

        foreach (['terms', 'age_check'] as $option) {
            try {
                $factory->create(OrderFormType::class, null, ['sale' => $sale, $option => 'yes']);
                self::fail($option.' must be a boolean.');
            } catch (InvalidOptionsException $exception) {
                self::assertStringContainsString($option, $exception->getMessage());
            }
        }
    }

    public function testTheConfirmationRepeatsTheSellerTheWithdrawalAndTheAlcoholRules(): void
    {
        self::bootKernel();
        $sale = $this->saleOf($this->producerShop());
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $honey = $this->offerOf($sale, ProductFactory::new()->notPerishable(), 'Miel de lavande', 1100);
        $beer = $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Blonde du Confluent', 350);
        $order = $this->placeOrder($sale, CustomerFactory::createOne(), [self::idOf($bread) => 1, self::idOf($honey) => 1, self::idOf($beer) => 6]);

        self::assertTrue($order->containsAlcohol());
        self::assertTrue($order->hasWithdrawableItems());
        $email = $this->confirmationOf($order);

        foreach (['Fournil du Pont (Camille Dupont)', 'SIRET 12345678900015', '3 chemin du Pont, 26400 Saoû', 'camille@example.test', 'toutes taxes comprises', 'Miel de lavande, Blonde du Confluent', 'formulaire-retractation', 'à consommer avec modération'] as $text) {
            self::assertEmailHtmlBodyContains($email, $text);
            self::assertEmailTextBodyContains($email, $text);
        }
        self::assertEmailTextBodyNotContains($email, 'Pain de campagne,');
    }

    public function testTheConfirmationOfPerishableFoodSaysThereIsNoWithdrawal(): void
    {
        self::bootKernel();
        $sale = $this->saleOf($this->producerShop());
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $order = $this->placeOrder($sale, CustomerFactory::createOne(), [self::idOf($bread) => 2]);

        self::assertFalse($order->containsAlcohol());
        self::assertFalse($order->hasWithdrawableItems());
        $email = $this->confirmationOf($order);

        self::assertEmailHtmlBodyContains($email, 'pas de droit de rétractation');
        self::assertEmailTextBodyContains($email, 'pas de droit de rétractation');
        self::assertEmailHtmlBodyNotContains($email, 'modération');
        self::assertEmailTextBodyNotContains($email, 'modération');
    }

    public function testEveryEmailLinksToTheLegalPages(): void
    {
        self::bootKernel();
        $sale = $this->saleOf($this->producerShop());
        $order = $this->placeOrder($sale, CustomerFactory::createOne(), [self::idOf($this->createOffer($sale, 'Pain', 100)) => 1]);
        $email = $this->confirmationOf($order);

        self::assertEmailHtmlBodyContains($email, '/mentions-legales"');
        self::assertEmailHtmlBodyContains($email, '/confidentialite"');
        self::assertEmailTextBodyContains($email, '/cgu · Confidentialité : ');
    }

    public function testTheDeliverySheetRemindsToCheckTheAge(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $sale = $this->saleOf($this->shopOf($producer));
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $wine = $this->offerOf($sale, ProductFactory::new()->alcoholic(), 'Cornas 2022', 3200);
        $withWine = $this->placeOrder($sale, CustomerFactory::createOne(), [self::idOf($wine) => 1]);
        $breadOnly = $this->placeOrder($sale, CustomerFactory::createOne(), [self::idOf($bread) => 1]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/ventes/%d/livraison', self::idOf($sale)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(\sprintf('[data-order="%s"] [data-age-check-reminder]', $withWine->getReference()), 'Vérifie l\'âge au retrait');
        self::assertSelectorNotExists(\sprintf('[data-order="%s"] [data-age-check-reminder]', $breadOnly->getReference()));
    }

    public function testTheProducerFlagsPerishableAndAlcoholicProducts(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/produits/nouveau');
        self::assertSelectorTextContains('label[for="product_perishable"]', 'Denrée périssable (exclue du droit de rétractation)');
        self::assertSelectorTextContains('label[for="product_alcoholic"]', 'Boisson alcoolisée');
        self::assertSame('checked', $crawler->filter('#product_perishable')->attr('checked'));
        self::assertNull($crawler->filter('#product_alcoholic')->attr('checked'));
        self::assertNull($crawler->filter('#product_alcoholic')->attr('required'));
        self::assertNull($crawler->filter('#product_perishable')->attr('required'));

        $form = $crawler->selectButton('Enregistrer')->form([
            'product[name]' => 'Cornas 2022',
            'product[priceCents]' => '32,00',
        ]);
        $perishable = $form->get('product[perishable]');
        $alcoholic = $form->get('product[alcoholic]');
        self::assertInstanceOf(ChoiceFormField::class, $perishable);
        self::assertInstanceOf(ChoiceFormField::class, $alcoholic);
        $perishable->untick();
        $alcoholic->tick();
        $client->submit($form);
        self::assertResponseRedirects();

        $product = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Product::class)->findOneBy(['name' => 'Cornas 2022']);
        self::assertInstanceOf(Product::class, $product);
        self::assertFalse($product->isPerishable());
        self::assertTrue($product->isAlcoholic());
    }

    public function testAProductIsAPerishableFoodByDefault(): void
    {
        $product = new Product(new Shop('Ferme', 'ferme'), 'Salade', 150);

        self::assertTrue($product->isPerishable());
        self::assertFalse($product->isAlcoholic());
    }

    private function producerShop(): Shop
    {
        $producer = UserFactory::createOne(['firstName' => 'Camille', 'lastName' => 'Dupont', 'email' => 'camille@example.test', 'shop' => null]);
        $shop = new Shop('Fournil du Pont', 'fournil-du-pont-'.$producer->getId())
            ->setSiret('12345678900015')
            ->setAddress("3 chemin du Pont\n26400 Saoû")
            ->setPhone('06 12 34 56 78')
            ->setPaymentInfo('Espèces ou Wero');
        $shop->addUser($producer);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($shop);
        $entityManager->flush();

        return $shop;
    }

    private function saleOf(Shop $shop): Sale
    {
        return $this->createSale($shop);
    }

    private function offerOf(Sale $sale, ProductFactory $product, string $name, int $priceCents): SaleOffer
    {
        return SaleOfferFactory::createOne([
            'sale' => $sale,
            'product' => $product->with(['shop' => $sale->getShop(), 'name' => $name, 'priceCents' => $priceCents]),
        ]);
    }

    /**
     * @param array<int, int> $quantities
     *
     * @return array<string, string>
     */
    private function guestFields(Sale $sale, array $quantities): array
    {
        $fields = [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            'order_form[email]' => 'invite@example.test',
            'order_form[firstName]' => 'Inès',
            'order_form[lastName]' => 'Invitée',
            'order_form[acceptTerms]' => '1',
        ];
        foreach ($quantities as $offerId => $quantity) {
            $fields[\sprintf('order_form[quantities][%d]', $offerId)] = (string) $quantity;
        }

        return $fields;
    }

    private function saleUrl(Sale $sale): string
    {
        return \sprintf('/%s/vente/%s', $sale->getShop()->getSlug(), $sale->getCode());
    }

    private function confirmationOf(Order $order): Email
    {
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new OrderPlaced($order, Actor::Customer));
        $last = null;
        foreach (self::getMailerMessages() as $message) {
            $last = $message instanceof Email ? $message : $last;
        }
        self::assertInstanceOf(Email::class, $last);

        return $last;
    }
}
