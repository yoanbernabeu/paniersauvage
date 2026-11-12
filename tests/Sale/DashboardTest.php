<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class DashboardTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    public function testAnEmptyShopIsSentToTheOnboarding(): void
    {
        $client = self::createClient();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $client->request('GET', '/pro');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-onboarding]', 'Ta boutique est encore vide');
        self::assertSelectorExists('[data-onboarding] a[href="/pro/bienvenue"]');
        self::assertSelectorNotExists('[data-upcoming-deliveries]');
        self::assertSelectorNotExists('[data-subscription-alert]');
    }

    public function testDashboardShowsUpcomingDeliveriesAndOpenSales(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $shop = self::shopOf($producer);
        ProductFactory::createOne(['shop' => $shop]);

        $open = SaleFactory::createOne(['shop' => $shop, 'title' => 'Fournée ouverte']);
        SaleDeliveryFactory::createOne(['sale' => $open]);
        OrderFactory::createMany(3, ['sale' => $open]);
        OrderFactory::new()->cancelled()->create(['sale' => $open]);
        $closed = SaleFactory::new()->closed()->create(['shop' => $shop, 'title' => 'Fournée à livrer']);
        OrderFactory::createOne(['sale' => $closed]);
        SaleFactory::new()->scheduled()->create(['shop' => $shop, 'title' => 'Fournée programmée']);
        SaleFactory::new()->done()->create(['shop' => $shop, 'title' => 'Fournée passée']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour '.$producer->getFirstName());
        self::assertSelectorNotExists('[data-onboarding]');

        // Next deliveries: the closed sale (in 2 days) comes before the open one (in 7 days).
        $upcoming = $crawler->filter('[data-upcoming-deliveries] li');
        self::assertCount(2, $upcoming);
        self::assertStringContainsString('Fournée à livrer', $upcoming->eq(0)->text());
        self::assertStringContainsString('1 commande', $upcoming->eq(0)->text());
        self::assertStringContainsString('Fournée ouverte', $upcoming->eq(1)->text());
        self::assertCount(1, $upcoming->eq(0)->filter('a[href="/pro/ventes/'.$closed->getId().'/livraison"]'));
        self::assertStringNotContainsString('Fournée passée', $crawler->filter('[data-upcoming-deliveries]')->text());

        // Open sales with their number of orders (cancelled ones excluded).
        $openSales = $crawler->filter('[data-open-sales] li');
        self::assertCount(1, $openSales);
        self::assertStringContainsString('Fournée ouverte', $openSales->text());
        self::assertStringContainsString('3 commandes', $openSales->text());

        // Shortcuts: only the routes that exist.
        self::assertSelectorExists('[data-shortcuts] a[href="/pro/ventes/nouvelle"]');
        self::assertSelectorExists('[data-shortcuts] a[href="/pro/recurrences"]');
        self::assertSelectorNotExists('[data-subscription-alert]');
    }

    public function testDashboardWarnsWhenTheSubscriptionNoLongerAllowsNewSales(): void
    {
        $client = self::createClient();
        $producer = self::expiredProducer();
        SaleFactory::createOne(['shop' => self::shopOf($producer), 'title' => 'Fournée en cours']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro');

        self::assertResponseIsSuccessful();
        // One alert only: the layout banner.
        self::assertCount(1, $crawler->filter('[data-subscription-alert]'));
        self::assertSelectorTextContains('[data-subscription-alert]', 'aucune nouvelle vente ne peut s\'ouvrir ni se créer');
        self::assertSelectorExists('[data-subscription-alert] a[href="/pro/abonnement"]');
        // Sales in progress stay visible, but nothing invites to create a new one.
        self::assertSelectorTextContains('[data-open-sales]', 'Fournée en cours');
        self::assertSelectorNotExists('a[href="/pro/ventes/nouvelle"]');
    }

    public function testDashboardShowsEmptyStates(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        ProductFactory::createOne(['shop' => self::shopOf($producer)]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-upcoming-deliveries] [data-slot="empty"]', 'Aucune livraison prévue');
        self::assertSelectorTextContains('[data-open-sales] [data-slot="empty"]', 'Aucune vente ouverte');
    }

    public function testNavigationListsTheSaleEntries(): void
    {
        $client = self::createClient();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $client->request('GET', '/pro');

        self::assertSelectorTextContains('nav a[aria-current="page"]', 'Accueil');
        self::assertSelectorExists('nav a[href="/pro/ventes"]');
        self::assertSelectorExists('nav a[href="/pro/recurrences"]');
    }
}
