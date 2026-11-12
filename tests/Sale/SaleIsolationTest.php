<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\User;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * SPEC §1.3: a producer never sees nor changes the data of another shop, on every route of this feature set.
 */
final class SaleIsolationTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    private User $intruder;
    private Sale $sale;
    private SaleSchedule $schedule;
    private Order $order;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignResourceRoutes(): iterable
    {
        yield 'pro_sale_show' => ['GET', '/pro/ventes/{sale}'];
        yield 'pro_sale_edit' => ['GET', '/pro/ventes/{sale}/modifier'];
        yield 'pro_sale_edit (POST)' => ['POST', '/pro/ventes/{sale}/modifier'];
        yield 'pro_sale_cancel' => ['POST', '/pro/ventes/{sale}/annuler'];
        yield 'pro_sale_delivery_sheet' => ['GET', '/pro/ventes/{sale}/livraison'];
        yield 'pro_order_mark' => ['POST', '/pro/commandes/{order}/statut'];
        yield 'pro_order_weigh' => ['POST', '/pro/commandes/{order}/poids'];
        yield 'pro_schedule_edit' => ['GET', '/pro/recurrences/{schedule}/modifier'];
        yield 'pro_schedule_edit (POST)' => ['POST', '/pro/recurrences/{schedule}/modifier'];
        yield 'pro_schedule_toggle' => ['POST', '/pro/recurrences/{schedule}/activation'];
    }

    #[DataProvider('foreignResourceRoutes')]
    public function testAnotherProducerGetsNotFound(string $method, string $path): void
    {
        $client = $this->createClientWithForeignShop();
        $client->loginUser($this->intruder, 'producer');

        $client->request($method, $this->resolve($path), ['status' => 'delivered', '_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $this->sale->getId())->getStatus());
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->order->getId())->getStatus());
        self::assertTrue(self::reload(SaleSchedule::class, $this->schedule->getId())->isActive());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listRoutes(): iterable
    {
        yield 'pro_dashboard' => ['/pro'];
        yield 'pro_sale_index' => ['/pro/ventes'];
        yield 'pro_sale_new' => ['/pro/ventes/nouvelle'];
        yield 'pro_schedule_index' => ['/pro/recurrences'];
        yield 'pro_schedule_new' => ['/pro/recurrences/nouvelle'];
    }

    #[DataProvider('listRoutes')]
    public function testListsAndFormsOnlyShowTheCurrentShop(string $path): void
    {
        $client = $this->createClientWithForeignShop();
        // The intruder has their own product so the dashboard is not the onboarding screen.
        ProductFactory::createOne(['shop' => self::shopOf($this->intruder), 'name' => 'Mon pain à moi']);
        $client->loginUser($this->intruder, 'producer');

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        foreach (['Vente du voisin', 'Récurrence du voisin', 'Pain du voisin', 'Place du voisin'] as $foreign) {
            self::assertStringNotContainsString($foreign, $text);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allRoutes(): iterable
    {
        foreach (self::listRoutes() as $name => [$path]) {
            yield $name => ['GET', $path];
        }
        yield from self::foreignResourceRoutes();
    }

    #[DataProvider('allRoutes')]
    public function testAnonymousVisitorsAreSentToTheLoginPage(string $method, string $path): void
    {
        $client = $this->createClientWithForeignShop();

        $client->request($method, $this->resolve($path));

        self::assertResponseRedirects('/pro/connexion');
    }

    #[DataProvider('allRoutes')]
    public function testACustomerSessionGivesNoAccess(string $method, string $path): void
    {
        $client = $this->createClientWithForeignShop();
        $client->loginUser($this->order->getCustomer(), 'main');

        $client->request($method, $this->resolve($path));

        self::assertResponseRedirects('/pro/connexion');
    }

    public function testTheOwnerReachesTheSameResources(): void
    {
        $client = $this->createClientWithForeignShop();
        $owner = self::entityManager()->getRepository(User::class)->findOneBy(['shop' => $this->sale->getShop()]);
        self::assertNotNull($owner);
        $client->loginUser($owner, 'producer');

        foreach (['/pro/ventes/{sale}', '/pro/ventes/{sale}/modifier', '/pro/ventes/{sale}/livraison', '/pro/recurrences/{schedule}/modifier', '/pro/ventes', '/pro/recurrences', '/pro'] as $path) {
            $client->request('GET', $this->resolve($path));
            self::assertResponseIsSuccessful($path);
        }
    }

    /**
     * Another shop with a sale, an order and a recurrence; `$this->intruder` is a producer of a different shop.
     */
    private function createClientWithForeignShop(): KernelBrowser
    {
        $client = self::createClient();

        $owner = self::subscribedProducer();
        $shop = self::shopOf($owner);
        $product = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain du voisin']);
        $point = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du voisin']);
        $this->sale = SaleFactory::createOne(['shop' => $shop, 'title' => 'Vente du voisin']);
        SaleDeliveryFactory::createOne(['sale' => $this->sale, 'deliveryPoint' => $point]);
        $offer = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => $product]);
        $this->order = self::order($this->sale, $offer);
        $this->schedule = self::schedule($shop, [$product], [$point]);
        $this->schedule->setName('Récurrence du voisin');
        self::entityManager()->flush();

        $this->intruder = self::subscribedProducer();

        return $client;
    }

    private function resolve(string $path): string
    {
        return strtr($path, [
            '{sale}' => (string) $this->sale->getId(),
            '{order}' => (string) $this->order->getId(),
            '{schedule}' => (string) $this->schedule->getId(),
        ]);
    }
}
