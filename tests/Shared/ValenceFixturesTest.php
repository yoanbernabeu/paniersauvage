<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\DataFixtures\ValenceFixtures;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Shop\Repository\ShopRepository;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ValenceFixturesTest extends WebTestCase
{
    use FakeBanTrait;

    private const string AROUND_VALENCE = '/trouver-un-producteur?lat=44.933&lng=4.892&rayon=';

    public function testTheValenceDataSetCoversEverySaleAndOrderStatus(): void
    {
        $this->load(self::createClient());
        $container = self::getContainer();

        self::assertSame([], FakeBan::queries(), 'The demo points carry their coordinates.');

        $saleStatuses = array_map(static fn ($sale): SaleStatus => $sale->getStatus(), $container->get(SaleRepository::class)->findAll());
        foreach (SaleStatus::cases() as $status) {
            self::assertContains($status, $saleStatuses, $status->value);
        }

        $orderStatuses = array_map(static fn ($order): OrderStatus => $order->getStatus(), $container->get(OrderRepository::class)->findAll());
        foreach (OrderStatus::cases() as $status) {
            self::assertContains($status, $orderStatuses, $status->value);
        }
    }

    public function testASearchAroundValenceShowsOnlyTheSearchableShops(): void
    {
        $client = self::createClient();
        $this->load($client);

        $found = $this->slugs($client->request('GET', self::AROUND_VALENCE.'30'));
        sort($found);
        $expected = ValenceFixtures::SEARCHABLE_WITHIN_30_KM;
        sort($expected);
        self::assertSame($expected, $found);

        $found = $this->slugs($client->request('GET', self::AROUND_VALENCE.'50'));
        self::assertContains('nougaterie-du-jabron', $found, 'Montélimar is within 50 km.');
        foreach (['huilerie-de-nyons', 'oeufs-de-portes', 'noix-de-l-isere', 'paniers-de-tain', 'champignonniere-de-livron'] as $hidden) {
            self::assertNotContains($hidden, $found, $hidden);
        }
    }

    public function testTheDemoShopsAreInTheDirectoryExceptTheHiddenOne(): void
    {
        $client = self::createClient();
        $this->load($client);

        $shops = self::getContainer()->get(ShopRepository::class)->findAll();
        foreach ($shops as $shop) {
            self::assertNotSame([], $shop->getCategories(), $shop->getSlug());
            self::assertNotSame([], $shop->getPaymentMethods(), $shop->getSlug());
            self::assertSame('noix-de-l-isere' !== $shop->getSlug(), $shop->isListedInSearch(), $shop->getSlug());
        }

        // The home-delivery-only shop is found inside its zone around Tain-l'Hermitage.
        $found = $this->slugs($client->request('GET', '/trouver-un-producteur?lat=45.07&lng=4.85&rayon=10'));
        self::assertSame('paniers-de-tain', $found[0] ?? null);
        self::assertSelectorTextContains('[data-result="paniers-de-tain"]', 'livre chez toi');

        // Filters on the demo data.
        self::assertContains('vergers-de-la-veore', $this->slugs($client->request('GET', self::AROUND_VALENCE.'30&monnaie-locale=1')));
        self::assertSame(['potager-de-l-eperviere'], $this->slugs($client->request('GET', self::AROUND_VALENCE.'30&cheques-alimentaires=1')));
    }

    private function load(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $container->get(ValenceFixtures::class)->load($container->get(EntityManagerInterface::class));
    }

    /**
     * @return list<string>
     */
    private function slugs(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-result]')->each(static fn (Crawler $node): string => (string) $node->attr('data-result'));
    }
}
