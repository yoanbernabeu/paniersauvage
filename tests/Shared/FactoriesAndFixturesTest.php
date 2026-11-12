<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Billing\Repository\ShopSubscriptionRepository;
use App\Catalog\Repository\ProductRepository;
use App\DataFixtures\AppFixtures;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Shop\Enum\ShopRole;
use App\Shop\Repository\ShopRepository;
use App\Shop\Repository\UserRepository;
use App\Tests\Factory\NotificationLogFactory;
use App\Tests\Factory\OrderEventFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\SaleScheduleFactory;
use App\Tests\Factory\ShopFollowerFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class FactoriesAndFixturesTest extends WebTestCase
{
    use Factories;

    public function testEveryFactoryCreatesAConsistentObject(): void
    {
        self::bootKernel();

        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        self::assertNotNull($shop);

        self::assertTrue(ShopSubscriptionFactory::createOne(['shop' => $shop])->allowsNewSales(new \DateTimeImmutable()));
        self::assertTrue(ShopSubscriptionFactory::new()->active()->create()->allowsNewSales(new \DateTimeImmutable()));
        self::assertFalse(ShopSubscriptionFactory::new()->trialExpired()->create()->allowsNewSales(new \DateTimeImmutable()));
        self::assertFalse(ShopSubscriptionFactory::new()->canceled()->create()->allowsNewSales(new \DateTimeImmutable()));

        $sale = SaleFactory::createOne(['shop' => $shop]);
        self::assertTrue($sale->isOpen());
        self::assertTrue(SaleFactory::new()->scheduled()->create()->isScheduled());
        self::assertTrue(SaleFactory::new()->closed()->create()->isClosed());
        self::assertTrue(SaleFactory::new()->done()->create()->isDone());

        $delivery = SaleDeliveryFactory::createOne(['sale' => $sale]);
        self::assertSame($shop, $delivery->getDeliveryPoint()->getShop());

        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'maxQty' => 10000]);
        self::assertSame($shop, $offer->getProduct()->getShop());
        self::assertSame($offer->getProduct()->getPriceCents(), $offer->getPriceCents());

        $order = OrderFactory::createOne(['sale' => $sale]);
        self::assertSame($delivery, $order->getSaleDelivery(), 'an order lands on the first delivery of its sale');

        $line = OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => 3000]);
        OrderLineFactory::createOne(['order' => $order]);
        self::assertSame(3 * $offer->getPriceCents(), $line->getTotalCents());
        self::assertCount(2, $order->getLines());
        self::assertGreaterThanOrEqual($line->getTotalCents(), $order->getTotalCents());

        self::assertSame($order, OrderEventFactory::createOne(['order' => $order])->getOrder());
        self::assertTrue(ShopFollowerFactory::createOne(['shop' => $shop])->hasEmailConsent());
        self::assertSame($shop, SaleScheduleFactory::createOne(['shop' => $shop])->getShop());
        self::assertNotNull(NotificationLogFactory::createOne()->getRecipient());

        // Everything above really is in the database.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = self::getContainer()->get(OrderRepository::class)->findOneByReference($order->getReference());
        self::assertNotNull($reloaded);
        self::assertSame($order->getTotalCents(), $reloaded->getTotalCents());
        self::assertCount(2, $reloaded->getLines());
    }

    public function testDemoFixturesLoadAndTheBakerCanLogIn(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        $container->get(AppFixtures::class)->load($container->get(EntityManagerInterface::class));
        $now = $container->get(ClockInterface::class)->now();

        $shop = $container->get(ShopRepository::class)->findOneBySlug('fournil-du-pont');
        self::assertNotNull($shop);
        self::assertSame('Fournil du Pont', $shop->getName());
        self::assertSame($shop, $container->get(UserRepository::class)->findOneByEmail('boulanger@example.test')?->getShop());

        $subscription = $container->get(ShopSubscriptionRepository::class)->findOneByShop($shop);
        self::assertTrue($subscription?->isTrialing());
        self::assertTrue($subscription->allowsNewSales($now));

        $prices = [];
        foreach ($container->get(ProductRepository::class)->findByShop($shop) as $product) {
            $prices[$product->getName()] = $product->getPriceCents();
        }
        self::assertSame([
            'Pain de campagne' => 520,
            'Pain complet' => 480,
            'Baguette tradition' => 130,
            'Pain aux graines' => 390,
            'Brioche au beurre' => 800,
            'Pain aux noix' => 560,
        ], $prices);

        $openSales = $container->get(SaleRepository::class)->findOpenByShop($shop);
        self::assertCount(1, $openSales);
        $openSale = $openSales[0];
        self::assertTrue($openSale->acceptsOrders($now));
        self::assertSame('1', $openSale->getDeliveryDate()->format('N'), 'delivered on a Monday');
        self::assertSame('5 20:00', $openSale->getDeadlineAt()->format('N H:i'), 'orders until Friday 8 pm');
        self::assertCount(2, $openSale->getDeliveries());
        self::assertCount(6, $openSale->getOffers());

        $orders = $container->get(OrderRepository::class)->findBySale($openSale);
        self::assertCount(4, $orders);

        // Stock counters match the orders, and the brioche is the limited product.
        foreach ($openSale->getOffers() as $offer) {
            $ordered = 0;
            foreach ($orders as $order) {
                $ordered += $order->getLineFor($offer)?->getQuantity() ?? 0;
            }
            self::assertSame($ordered, $offer->getReservedQty(), $offer->getProduct()->getName());
            self::assertSame('Brioche au beurre' === $offer->getProduct()->getName(), $offer->isLimited());
        }

        self::assertCount(3, $container->get(SaleRepository::class)->findBy(['shop' => $shop, 'status' => SaleStatus::Done]));
        self::assertSame(3000, $shop->getFirstOrderCapCents());
        self::assertSame(ShopRole::Member, $container->get(UserRepository::class)->findOneByEmail(AppFixtures::MEMBER_EMAIL)?->getShopRole());
        self::assertSame('boulanger@example.test', $shop->getOwner()?->getEmail());

        $client->request('GET', '/pro/connexion');
        $client->submitForm('Me connecter', ['_username' => 'boulanger@example.test', '_password' => 'password']);
        self::assertResponseRedirects('/pro');
    }
}
