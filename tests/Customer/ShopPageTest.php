<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Shop\Entity\Shop;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ShopPageTest extends WebTestCase
{
    use Factories;

    public function testPublicPageShowsTheProducerAndTheOpenSale(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne([
            'name' => 'Le Fournil du Pont',
            'slug' => 'fournil-du-pont',
            'description' => 'Pains au levain cuits au feu de bois.',
            'paymentInfo' => 'Wero au 06 00 00 00 00, espèces',
        ]);
        $sale = SaleFactory::createOne(['shop' => $shop, 'code' => 'ABC123', 'title' => 'Fournée du lundi']);
        $point = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village']);
        SaleDeliveryFactory::createOne(['sale' => $sale, 'deliveryPoint' => $point]);
        SaleFactory::new(['shop' => $shop])->scheduled()->create();
        SaleFactory::new(['shop' => $shop, 'code' => 'CLOSED'])->closed()->create();

        $crawler = $client->request('GET', '/fournil-du-pont');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le Fournil du Pont');
        self::assertSelectorTextContains('[data-shop-header]', 'Pains au levain');
        self::assertSelectorExists('[data-shop-emblem] svg');

        $cards = $crawler->filter('[data-section="open-sales"] [data-sale]');
        self::assertSame(['ABC123'], $cards->extract(['data-sale']));
        self::assertStringContainsString('Commandes ouvertes', $cards->text());
        self::assertStringContainsString('Fournée du lundi', $cards->text());
        self::assertStringContainsString('Commande jusqu\'au', $cards->text());
        self::assertStringContainsString('Place du village', $cards->text());
        // Route of another feature set: the fallback path is used until it exists.
        self::assertSame('/fournil-du-pont/vente/ABC123', $cards->filter('a')->attr('href'));
        self::assertSelectorNotExists('[data-empty-sales]');

        self::assertCount(1, $crawler->filter('[data-section="scheduled-sales"] li'));
        self::assertSelectorTextContains('[data-section="payment-info"]', 'Wero au 06 00 00 00 00');
        self::assertSelectorTextContains('[data-section="follow"] button[type="submit"]', 'Suivre');
        self::assertCount(1, $crawler->filter('[data-variant="default"][data-slot="button"]'), 'One wheat button per screen.');
    }

    public function testShopWithoutOpenSaleShowsAnIllustratedEmptyState(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ferme-des-saules', 'name' => 'La Ferme des Saules', 'paymentInfo' => null, 'logoPath' => '/uploads/logo.png']);
        SaleFactory::new(['shop' => $shop])->done()->create();
        SaleFactory::new(['shop' => $shop])->cancelled()->create();

        $client->request('GET', '/ferme-des-saules');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-empty-sales]', 'Pas de vente ouverte pour le moment');
        self::assertSelectorExists('[data-empty-sales] svg');
        self::assertSelectorNotExists('[data-sale]');
        self::assertSelectorNotExists('[data-section="scheduled-sales"]');
        self::assertSelectorNotExists('[data-section="payment-info"]');
        self::assertSelectorExists('[data-shop-header] img[src="/uploads/logo.png"]');
    }

    public function testSaleWhoseDeadlineHasPassedIsNoLongerOffered(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ferme-des-saules']);
        // Still flagged "open": the scheduler has not closed it yet.
        SaleFactory::createOne(['shop' => $shop, 'opensAt' => new \DateTimeImmutable('-3 days'), 'deadlineAt' => new \DateTimeImmutable('-1 minute')]);

        $client->request('GET', '/ferme-des-saules');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-empty-sales]');
    }

    public function testSalesOfAnotherShopAreNotShown(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'ferme-des-saules']);
        SaleFactory::createOne(['shop' => ShopFactory::createOne(['slug' => 'fournil-du-pont']), 'code' => 'OTHER1']);

        $client->request('GET', '/ferme-des-saules');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-sale]');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingPages(): iterable
    {
        yield 'unknown shop' => ['/boutique-inconnue'];
        yield 'reserved slug' => ['/admin'];
        yield 'reserved slug: api' => ['/api'];
        yield 'not a slug' => ['/Fournil_du_Pont'];
    }

    #[DataProvider('missingPages')]
    public function testUnknownOrReservedSlugAnswers404(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }

    public function testReservedSlugAnswers404EvenIfAShopSomehowOwnsIt(): void
    {
        $client = self::createClient();
        self::assertTrue(Shop::isReservedSlug('tarifs'));
        ShopFactory::createOne(['slug' => 'tarifs']);

        $client->request('GET', '/tarifs');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShopPageDoesNotShadowTheOtherRoutes(): void
    {
        $client = self::createClient();

        $client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
        self::assertRouteSame('customer_login');

        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');

        $client->request('GET', '/');
        self::assertRouteSame('home');
    }
}
