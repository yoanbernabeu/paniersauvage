<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shop\Entity\Shop;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class HomePageTest extends WebTestCase
{
    public function testTheHomePageIsThePresentationToProducers(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('home');
        self::assertSelectorTextContains('h1', 'Tes clients précommandent');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/"]');
        self::assertSame(
            ['Zéro commission.', 'Tes clients restent les tiens.', 'Logiciel libre.'],
            $crawler->filter('[data-promises] li > p:first-child')->each(static fn ($node): string => $node->text()),
        );
        self::assertCount(5, $crawler->filter('[data-steps] > li'));
        self::assertSelectorTextContains('[data-plan="monthly"]', '10 €');
        self::assertSelectorTextContains('[data-plan="yearly"]', '100 €');
        self::assertSelectorTextContains('[data-comparison]', '100 €');
        self::assertSelectorExists('[data-commitments-link][href="/engagements"]');
        self::assertCount(5, $crawler->filter('[data-faq] details'));
        self::assertGreaterThan(0, $crawler->filter('a[href="/pro/inscription"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/pro/connexion"]')->count());
        self::assertCount(1, $crawler->filter('[data-variant="default"][data-slot="button"]'), 'One wheat button per screen.');
    }

    public function testTheHomePageLeadsCustomersToTheDirectoryAndTheirOrders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('[data-directory-teaser]', 'Tu cherches un producteur près de chez toi ?');
        self::assertSelectorExists('[data-directory-teaser] a[href="/trouver-un-producteur"]');
        self::assertSelectorExists('[data-account-teaser] a[href="/connexion"]');
        self::assertSelectorExists('footer a[data-footer-link="directory"][href="/trouver-un-producteur"]');
        self::assertSelectorExists('footer a[data-footer-link="pro-login"][href="/pro/connexion"]');
    }

    public function testTheOldProducerPageIsGone(): void
    {
        $client = self::createClient();
        $client->request('GET', '/producteurs');

        self::assertResponseStatusCodeSame(404);
        self::assertTrue(Shop::isReservedSlug('producteurs'));
    }

    public function testSignInScreensLeadBackToTheHomePage(): void
    {
        $client = self::createClient();

        foreach (['/pro/connexion', '/pro/inscription', '/connexion'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[aria-label="Panier Sauvage, accueil"][href="/"]', $path);
        }
    }

    public function testHomePageLoadsNoThirdPartyFont(): void
    {
        $client = self::createClient();

        foreach (['/', '/trouver-un-producteur'] as $path) {
            $client->request('GET', $path);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('fonts.googleapis.com', $html);
            self::assertStringNotContainsString('fonts.gstatic.com', $html);
        }
    }

    public function testUnknownPageAnswers404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/une/page/qui/n-existe/pas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCustom404PageRenders(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get(Environment::class)->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']);

        self::assertStringContainsString('Ce chemin ne mène nulle part', $html);
    }

    public function testThe404PageOfTheProducerAreaLeadsBackToTheDashboard(): void
    {
        self::bootKernel();
        $requests = self::getContainer()->get(RequestStack::class);

        $requests->push(Request::create('/pro/ventes/999999'));
        $html = self::getContainer()->get(Environment::class)->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']);
        self::assertStringContainsString('data-error-404="pro"', $html);
        self::assertStringContainsString('ne fait pas partie de ta boutique', $html);
        self::assertStringContainsString('href="/pro"', $html);
        $requests->pop();

        $requests->push(Request::create('/produits-frais'));
        $html = self::getContainer()->get(Environment::class)->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']);
        self::assertStringContainsString('data-error-404="public"', $html);
        self::assertStringContainsString('Revenir à l\'accueil', $html);
    }
}
