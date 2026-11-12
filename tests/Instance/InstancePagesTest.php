<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The layouts, the home page and the legal pages on Panier Sauvage itself (the committed defaults)
 * and on a territorial instance: name, logo, accent, carrier, and the AGPL credit.
 */
final class InstancePagesTest extends WebTestCase
{
    use Factories;
    use InstanceTrait;

    public function testTheDefaultsChangeNothingToPanierSauvage(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Les précommandes de tes clients, sans WhatsApp · Panier Sauvage', $crawler->filter('title')->text());
        self::assertStringStartsWith('Panier Sauvage : tes clients précommandent en ligne', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertStringContainsString('10 € par mois', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertNull($crawler->filter('html')->attr('data-accent'));
        self::assertSelectorNotExists('meta[name="application-name"]');
        self::assertSelectorNotExists('meta[name="generator"]');
        self::assertSelectorNotExists('[data-powered-by]');
        self::assertSelectorNotExists('[data-instance-name]');
        self::assertSelectorNotExists('[data-instance-welcome]');
        self::assertSelectorNotExists('[data-instance-carrier]');
        self::assertSame('Panier Sauvage', $crawler->filter('header a[aria-label="Panier Sauvage, accueil"]')->text());
        self::assertSame('30 jours d\'essai gratuit, sans carte bancaire.', $crawler->filter('[data-hero-pricing]')->text());
        self::assertSelectorExists('[data-pricing] [data-plan="monthly"]');
        self::assertSelectorExists('[data-comparison]');
        self::assertSame('Fait avec soin pour les producteurs et leurs voisins.', $crawler->filter('footer p')->first()->text());
        self::assertSelectorTextContains('footer', 'Panier Sauvage · Trouver un producteur · Espace producteur · logiciel libre (AGPL)');

        $client->request('GET', '/mentions-legales');
        self::assertSelectorNotExists('[data-instance-legal]');
        self::assertSelectorNotExists('[data-legal-carrier]');
        self::assertSelectorTextContains('title', 'Mentions légales · Panier Sauvage');

        $client->request('GET', '/pro/connexion');
        self::assertSelectorNotExists('[data-powered-by]');

        $client->loginUser(UserFactory::createOne(), 'producer');
        $crawler = $client->request('GET', '/pro');
        self::assertSelectorNotExists('[data-powered-by]');
        self::assertSame('#46604F', $crawler->filter('meta[name="theme-color"]')->attr('content'));
    }

    public function testATerritorialInstanceShowsItsNameAndCreditsPanierSauvage(): void
    {
        $this->useTerritorialInstance();
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Les précommandes de tes clients, sans WhatsApp · Panier Toqué du local', $crawler->filter('title')->text());
        self::assertStringStartsWith('Panier Toqué du local : tes clients précommandent', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('riviere', $crawler->filter('html')->attr('data-accent'));
        self::assertSame('Panier Toqué du local', $crawler->filter('meta[name="application-name"]')->attr('content'));
        self::assertSame('Panier Sauvage (AGPL-3.0)', $crawler->filter('meta[name="generator"]')->attr('content'));
        self::assertSame('Panier Toqué du local', $crawler->filter('header a[aria-label="Panier Toqué du local, accueil"] [data-instance-name]')->text());
        self::assertSelectorNotExists('header img', 'No INSTANCE_LOGO: the basket symbol.');

        // The words of the territory and its carrier.
        self::assertSelectorTextContains('[data-instance-welcome] h2', 'Pour les producteurs de Valence Romans Agglo');
        self::assertSame("Les producteurs de l'agglo prennent leurs précommandes ici.\nSans commission.", $crawler->filter('[data-instance-welcome-text]')->text(normalizeWhitespace: false));
        self::assertSelectorTextContains('[data-instance-carrier-intro]', 'Un service porté par Valence Romans Agglo.');
        self::assertSelectorExists('[data-instance-carrier-intro] a[href="https://www.valenceromansagglo.fr"]');
        self::assertSelectorNotExists('[data-instance-sponsored]', 'Standard pricing: nothing offered.');
        self::assertSelectorExists('[data-pricing] [data-plan="monthly"]');

        // The footer credits the software and links to the code this instance runs.
        $this->assertPoweredBy($crawler);
        self::assertSelectorTextContains('footer p', 'Un service porté par Valence Romans Agglo.');
        self::assertSelectorTextContains('footer', 'Panier Toqué du local · Trouver un producteur · Espace producteur · Propulsé par Panier Sauvage, logiciel libre (AGPL)');
        self::assertCount(1, $crawler->filter('[data-variant="default"][data-slot="button"]'), 'Still one wheat button per screen.');

        $crawler = $client->request('GET', '/pro/connexion');
        $this->assertPoweredBy($crawler);
        self::assertSelectorExists('a[aria-label="Panier Toqué du local, accueil"]');

        $client->loginUser(UserFactory::createOne(), 'producer');
        $crawler = $client->request('GET', '/pro');
        $this->assertPoweredBy($crawler);
        self::assertSame('#3B5F6D', $crawler->filter('meta[name="theme-color"]')->attr('content'));
        self::assertSelectorExists('header a[aria-label="Panier Toqué du local, espace producteur"]');
    }

    public function testTheLegalPagesSayWhoPublishesAndCarriesTheInstance(): void
    {
        $this->useTerritorialInstance(['LEGAL_PUBLISHER_NAME' => 'Communauté d\'agglomération Valence Romans Agglo']);
        $client = self::createClient();

        foreach (['/mentions-legales', '/cgu', '/confidentialite', '/engagements'] as $page) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            $notice = $crawler->filter('[data-instance-legal]');
            self::assertCount(1, $notice, $page);
            self::assertStringContainsString('Panier Toqué du local est une instance du logiciel libre Panier Sauvage (licence AGPL), éditée par Communauté d\'agglomération Valence Romans Agglo et portée par Valence Romans Agglo.', $notice->text(), $page);
            self::assertStringContainsString('« Panier Sauvage » désigne ce service', $notice->text(), $page);
            self::assertSame('https://forge.example.org/agglo/panier-toque', $notice->filter('a')->first()->attr('href'));
            self::assertCount(0, $notice->filter('[data-instance-sponsored]'), $page);
            $this->assertPoweredBy($crawler);
        }

        $crawler = $client->request('GET', '/mentions-legales');
        self::assertSelectorTextContains('[data-legal-publisher]', 'Communauté d\'agglomération Valence Romans Agglo');
        self::assertSame('Ce service est porté par Valence Romans Agglo.', $crawler->filter('[data-legal-carrier]')->text());
        self::assertSame('https://forge.example.org/agglo/panier-toque', $crawler->filter('[data-legal-page] [data-source-code-link]')->attr('href'));
    }

    public function testACarrierWithoutUrlIsNamedWithoutLink(): void
    {
        $this->useTerritorialInstance(['INSTANCE_CARRIER_URL' => '', 'INSTANCE_WELCOME_TEXT' => '', 'INSTANCE_TERRITORY_NAME' => '']);
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSelectorTextContains('[data-instance-welcome] h2', 'Pour les producteurs du territoire');
        self::assertSelectorNotExists('[data-instance-welcome-text]');
        self::assertCount(0, $crawler->filter('[data-instance-carrier] a'));
        self::assertSame('Valence Romans Agglo', $crawler->filter('[data-instance-carrier-intro] [data-instance-carrier]')->text());
    }

    public function testARenamedInstanceWithoutCarrierStillCreditsTheSoftware(): void
    {
        $this->useInstance(['INSTANCE_NAME' => 'Paniers du Diois', 'INSTANCE_LOGO' => 'images/instance-test-logo.svg', 'LEGAL_SOURCE_CODE_URL' => 'https://forge.example.org/agglo/panier-toque']);
        $logo = \dirname(__DIR__, 2).'/public/images/instance-test-logo.svg';
        if (!is_dir(\dirname($logo))) {
            mkdir(\dirname($logo), 0o777, true);
        }
        file_put_contents($logo, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        try {
            $client = self::createClient();
            $crawler = $client->request('GET', '/');

            self::assertSelectorNotExists('[data-instance-welcome]');
            self::assertSelectorNotExists('[data-instance-carrier]');
            self::assertNull($crawler->filter('html')->attr('data-accent'), 'Sage stays the default accent.');
            self::assertSame('/images/instance-test-logo.svg', $crawler->filter('header a[aria-label="Paniers du Diois, accueil"] img')->attr('src'));
            self::assertSame('Paniers du Diois', $crawler->filter('header [data-instance-name]')->text());
            $this->assertPoweredBy($crawler);

            $client->request('GET', '/cgu');
            self::assertSelectorTextContains('[data-instance-legal]', 'Paniers du Diois est une instance du logiciel libre Panier Sauvage (licence AGPL), éditée par');
            self::assertSelectorTextNotContains('[data-instance-legal]', 'portée par');
        } finally {
            unlink($logo);
        }
    }

    public function testTheLoginLinkNamesTheInstance(): void
    {
        $this->useTerritorialInstance();
        $client = self::createClient();
        CustomerFactory::createOne(['email' => 'camille@example.test']);

        foreach (['camille@example.test', 'inconnue@example.test'] as $address) {
            $client->request('GET', '/connexion');
            $client->submitForm('Recevoir mon lien', ['login[email]' => $address]);
            $email = self::getMailerMessage();
            self::assertNotNull($email);
            self::assertEmailHeaderSame($email, 'Subject', 'Ton lien de connexion à Panier Toqué du local');
            self::assertEmailTextBodyContains($email, 'te connecter à Panier Toqué du local');
        }
    }

    private function assertPoweredBy(Crawler $crawler): void
    {
        $credit = $crawler->filter('[data-powered-by]');
        self::assertCount(1, $credit);
        self::assertSame('Propulsé par Panier Sauvage, logiciel libre (AGPL)', trim((string) preg_replace('/\s+/', ' ', $credit->text())));
        self::assertSame('https://forge.example.org/agglo/panier-toque', $credit->filter('a')->attr('href'));
    }
}
