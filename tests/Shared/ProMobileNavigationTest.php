<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProMobileNavigationTest extends WebTestCase
{
    use Factories;

    public function testTheMobileBarHasThreeEntriesAndAMoreMenu(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $crawler = $client->request('GET', '/pro/produits');

        self::assertResponseIsSuccessful();
        $bar = $crawler->filter('nav[data-mobile-nav]');
        self::assertCount(1, $bar);
        self::assertStringContainsString('sm:hidden', (string) $bar->attr('class'));

        $links = $bar->filter('ul.grid > li > a');
        self::assertSame(['Accueil', 'Ventes', 'Produits'], $links->each(static fn ($link): string => trim($link->text())));
        self::assertSame('page', $links->eq(2)->attr('aria-current'));

        $button = $bar->filter('button[data-action="disclosure#toggle"]');
        self::assertSame('false', $button->attr('aria-expanded'));
        self::assertSame('pro-nav-more', $button->attr('aria-controls'));

        $menu = $bar->filter('#pro-nav-more');
        self::assertNotNull($menu->attr('hidden'));
        $labels = $menu->filter('a')->each(static fn ($link): string => trim($link->text()));
        foreach (['Récurrences', 'Points de livraison', 'Comptabilité', 'Ma boutique', 'Abonnement', 'Voir ma page'] as $label) {
            self::assertContains($label, $labels);
        }
        self::assertNotContains('Produits', $labels);

        // The desktop tabs are unchanged, hidden under `sm` only.
        self::assertSelectorExists('header nav.hidden.sm\:block');
    }

    public function testTheMoreButtonShowsWhenTheCurrentPageIsInTheMenu(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $crawler = $client->request('GET', '/pro/boutique');

        self::assertSame('page', $crawler->filter('#pro-nav-more a[href="/pro/boutique"]')->attr('aria-current'));
        self::assertStringContainsString('page en cours', $crawler->filter('nav[data-mobile-nav] button')->text());
    }
}
