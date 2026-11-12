<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\DataFixtures\AppFixtures;
use App\DataFixtures\ValenceFixtures;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopSubscriptionFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The public page of a shop answers `/{slug}`: no slug may take the place of a route.
 */
final class ReservedSlugsTest extends WebTestCase
{
    use Factories;

    public function testTheFirstSegmentOfEveryRouteIsReserved(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        $segments = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $segment = explode('/', trim($route->getPath(), '/'))[0];

            // The home page, and the routes of the shop pages themselves (`/{slug}…`).
            if ('' === $segment || str_starts_with($segment, '{')) {
                continue;
            }

            $segments[$segment][] = $name;
        }

        self::assertArrayHasKey('trouver-un-producteur', $segments);
        foreach ($segments as $segment => $routes) {
            self::assertTrue(Shop::isReservedSlug($segment), \sprintf('"%s" (route %s) must be added to Shop::RESERVED_SLUGS.', $segment, implode(', ', $routes)));
        }
    }

    public function testTheShopPageHasTheLowestPriority(): void
    {
        self::bootKernel();
        $names = array_keys(self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all());

        $shopRoutes = array_filter($names, static fn (string $name): bool => \in_array($name, ['shop_show', 'shop_follow', 'shop_unfollow', 'shop_report'], true));
        self::assertNotEmpty($shopRoutes);
        self::assertSame(array_values($shopRoutes), \array_slice(array_values(array_filter($names, static fn (string $name): bool => !str_starts_with($name, '_'))), -\count($shopRoutes)), 'The /{slug} routes must come after every other route.');
    }

    public function testTheDirectoryWinsOverAShopSlug(): void
    {
        $client = self::createClient();
        // Cannot happen through the application (validation), but the route order must hold anyway.
        $shop = ShopFactory::createOne(['slug' => 'trouver-un-producteur']);
        ShopSubscriptionFactory::createOne(['shop' => $shop]);

        $client->request('GET', '/trouver-un-producteur');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('producer_directory');
    }

    public function testTheDemoShopsDoNotUseAReservedSlug(): void
    {
        foreach ([...AppFixtures::DEMO_SHOP_SLUGS, ...ValenceFixtures::SHOP_SLUGS] as $slug) {
            self::assertFalse(Shop::isReservedSlug($slug), $slug);
        }
    }
}
