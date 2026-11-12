<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\LegalDocuments;
use App\Legal\Service\PublisherIdentity;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LegalPagesTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function pages(): iterable
    {
        yield 'legal notice' => ['/mentions-legales', 'legal_notice', 'Mentions légales', LegalDocuments::NOTICE_VERSION];
        yield 'terms' => ['/cgu', 'legal_terms', 'Conditions générales d\'utilisation', LegalDocuments::TERMS_VERSION];
        yield 'privacy' => ['/confidentialite', 'legal_privacy', 'Politique de confidentialité', LegalDocuments::PRIVACY_VERSION];
        yield 'commitments' => ['/engagements', 'legal_commitments', 'Nos engagements', LegalDocuments::COMMITMENTS_VERSION];
    }

    #[DataProvider('pages')]
    public function testEveryLegalPageShowsItsVersionAndDate(string $path, string $route, string $title, string $version): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertRouteSame($route);
        self::assertSelectorTextContains('h1', $title);
        self::assertSelectorTextContains('[data-legal-version]', 'Version '.$version);
        self::assertSelectorTextContains('[data-legal-version]', 'mise à jour le 10/11/2026');
        self::assertSelectorExists(\sprintf('nav[aria-label="Informations légales"] a[aria-current="page"][href="%s"]', $path));
    }

    public function testTheLegalNoticeNamesThePublisherAndTheHostFromTheEnvironment(): void
    {
        $client = self::createClient();
        $publisher = self::getContainer()->get(PublisherIdentity::class);
        $client->request('GET', '/mentions-legales');

        self::assertSelectorTextContains('[data-legal-publisher]', $publisher->name);
        self::assertSelectorTextContains('[data-legal-publisher]', $publisher->publicationDirector);
        self::assertSelectorTextContains('[data-legal-publisher]', $publisher->contactEmail);
        self::assertSelectorTextContains('[data-legal-publisher]', $publisher->phone);
        self::assertSelectorTextContains('[data-legal-host]', $publisher->hostName);
        self::assertSelectorExists(\sprintf('a[data-source-code-link][href="%s"]', $publisher->sourceCodeUrl));
        self::assertSelectorTextContains('#role + p', 'ne vend rien');
    }

    public function testTheTermsHoldBothPartsTheProcessingAgreementAndTheWithdrawalForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/cgu');

        foreach (['#producteurs', '#clients', '#sous-traitance', '#formulaire-retractation'] as $section) {
            self::assertSelectorExists($section);
        }
        self::assertSelectorExists('[data-withdrawal-form]');
        self::assertSelectorTextContains('body', 'Zéro commission');
        self::assertSelectorTextContains('body', 'directement le producteur');
    }

    public function testThePrivacyPolicyExplainsTheRoles(): void
    {
        $client = self::createClient();
        $client->request('GET', '/confidentialite');

        self::assertSelectorTextContains('#roles + p + ul', 'sous-traitant');
        self::assertSelectorTextContains('#destinataires + ul', 'Brevo');
        self::assertSelectorTextContains('#destinataires + ul', 'Stripe');
        self::assertSelectorTextContains('#destinataires + ul', 'API Adresse');
    }

    public function testTheCommitmentsAreListed(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/engagements');

        self::assertCount(7, $crawler->filter('[data-commitments] > li'));
        self::assertSelectorTextContains('[data-commitments]', 'Zéro commission, à vie');
        self::assertSelectorTextContains('[data-commitments]', 'classé par distance');
    }

    public function testTheThreeLayoutsLinkToTheLegalPages(): void
    {
        $client = self::createClient();

        // Customer layout, auth layout.
        foreach (['/engagements', '/connexion', '/pro/connexion'] as $path) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertCount(4, $crawler->filter('[data-legal-links] a'), $path);
            self::assertSelectorExists('[data-legal-links] a[href="/mentions-legales"]');
        }

        // Producer layout.
        $client->loginUser(UserFactory::createOne(['termsVersion' => LegalDocuments::TERMS_VERSION, 'termsAcceptedAt' => new \DateTimeImmutable()]), 'producer');
        $client->request('GET', '/pro/boutique');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer [data-legal-links] a[href="/cgu"]');
    }
}
