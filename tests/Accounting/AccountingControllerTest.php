<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Shared\Service\Quantity;
use App\Shop\Entity\User;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class AccountingControllerTest extends WebTestCase
{
    use Factories;

    public function testShowsTheJournalOfTheChosenMonth(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $this->deliver($producer, '2026-09-14', 'Pain de campagne', 520, 550, 2);
        $this->deliver($producer, '2026-09-21', 'Savon', 450, 2000, 1);
        $this->deliver($producer, '2026-10-05', 'Brioche', 800, 550, 5);

        $client->loginUser($producer, 'producer');
        $crawler = $client->request('GET', '/pro/comptabilite?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Comptabilité');
        self::assertSelectorTextContains('main', 'septembre 2026');
        self::assertSame('09', $crawler->filter('select[name="mois_numero"] option[selected]')->attr('value'));
        self::assertSame('2026', $crawler->filter('select[name="annee"] option[selected]')->attr('value'));
        // Mobile cards mirror the tables.
        self::assertCount(2, $crawler->filter('[data-journal-cards="sales"] li'));
        self::assertStringContainsString('Pain de campagne', $crawler->filter('[data-journal-cards="products"]')->text());

        // 10,40 € at 5,5 % → 9,86 + 0,54 ; 4,50 € at 20 % → 3,75 + 0,75.
        self::assertSame(
            ['Commandes livrées 2', "CA TTC 14,90\u{00A0}€", "CA HT 13,61\u{00A0}€", "TVA 1,29\u{00A0}€"],
            $crawler->filter('[data-journal-summary] > div')->each(static fn (Crawler $figure): string => $figure->text()),
        );
        self::assertSame(
            ["5,5\u{00A0}% 10,40\u{00A0}€ 9,86\u{00A0}€ 0,54\u{00A0}€", "20\u{00A0}% 4,50\u{00A0}€ 3,75\u{00A0}€ 0,75\u{00A0}€"],
            $crawler->filter('[data-journal="vat"] tbody tr')->each(static fn (Crawler $row): string => $row->text()),
        );
        self::assertCount(2, $crawler->filter('[data-journal="sales"] tbody tr'));
        self::assertSame(['Pain de campagne', 'Savon'], $crawler->filter('[data-journal="products"] tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSelectorTextNotContains('main', 'Brioche');

        self::assertSame('/pro/comptabilite/journal.csv?mois=2026-09', $crawler->selectLink('CSV')->attr('href'));
        self::assertSame('/pro/comptabilite/journal.pdf?mois=2026-09', $crawler->selectLink('PDF')->attr('href'));
        self::assertSame('/pro/comptabilite?mois=2026-08', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertSame('/pro/comptabilite?mois=2026-10', $crawler->filter('a[rel="next"]')->attr('href'));

        // The tool never records a payment: the journal must not suggest otherwise.
        self::assertDoesNotMatchRegularExpression('/encaiss|pay[ée]e?s?\b/iu', $crawler->filter('main')->text());
    }

    public function testTheMonthPickerSendsAMonthAndAYear(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $this->deliver($producer, '2026-09-14', 'Pain de campagne', 520, 550, 2);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/comptabilite');
        $client->submit($crawler->filter('form[data-month-picker]')->form(['mois_numero' => '09', 'annee' => '2026']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'septembre 2026');
        self::assertSelectorTextContains('[data-journal="products"]', 'Pain de campagne');
    }

    public function testDefaultsToTheCurrentMonthAndHandlesAnEmptyOne(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        foreach (['/pro/comptabilite', '/pro/comptabilite?mois=n-importe-quoi', '/pro/comptabilite?mois=2026-13'] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertSame(date('m'), $crawler->filter('select[name="mois_numero"] option[selected]')->attr('value'));
            self::assertSame(date('Y'), $crawler->filter('select[name="annee"] option[selected]')->attr('value'));
            self::assertSelectorTextContains('main', 'Aucune commande livrée');
            self::assertSelectorNotExists('a[rel="next"]');
            self::assertCount(0, $crawler->selectLink('CSV'));
        }
    }

    public function testCsvExport(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $this->deliver($producer, '2026-09-14', 'Pain de campagne', 520, 550, 2);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/comptabilite/journal.csv?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=journal-des-ventes-2026-09.csv');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $csv = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString(';"Pain de campagne";5,5;2;pièce;10,40;9,86;0,54', $csv);
        self::assertStringContainsString('Total;;;;"Total du mois";;;;10,40;9,86;0,54', $csv);
    }

    public function testPdfExport(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $this->deliver($producer, '2026-09-14', 'Pain de campagne', 520, 550, 2);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/comptabilite/journal.pdf?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=journal-des-ventes-2026-09.pdf');
        self::assertStringStartsWith('%PDF-1.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAProducerNeverSeesTheSalesOfAnotherShop(): void
    {
        $client = self::createClient();
        $this->deliver(UserFactory::createOne(), '2026-09-14', 'Pain secret du voisin', 520, 550, 2);

        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/comptabilite?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune commande livrée');
        self::assertSelectorTextNotContains('main', 'Pain secret du voisin');

        $client->request('GET', '/pro/comptabilite/journal.csv?mois=2026-09');

        self::assertResponseIsSuccessful();
        $csv = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Pain secret du voisin', $csv);
        self::assertStringContainsString('"Total du mois";;;;0,00;0,00;0,00', $csv);

        $client->request('GET', '/pro/comptabilite/journal.pdf?mois=2026-09');
        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUrls(): iterable
    {
        yield 'pro_accounting' => ['/pro/comptabilite'];
        yield 'pro_accounting_csv' => ['/pro/comptabilite/journal.csv'];
        yield 'pro_accounting_pdf' => ['/pro/comptabilite/journal.pdf'];
    }

    #[DataProvider('provideUrls')]
    public function testRequiresAProducer(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);
        self::assertResponseRedirects('/pro/connexion');

        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    private function deliver(User $producer, string $deliveryDate, string $product, int $priceCents, int $vatRateBp, int $quantity): void
    {
        $shop = $producer->getShop();
        self::assertNotNull($shop);

        $date = new \DateTimeImmutable($deliveryDate);
        $sale = SaleFactory::new()->done()->create(['shop' => $shop, 'opensAt' => $date->modify('-10 days'), 'deadlineAt' => $date->modify('-3 days'), 'deliveryDate' => $date]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => $product, 'priceCents' => $priceCents, 'vatRateBp' => $vatRateBp])]);
        OrderLineFactory::createOne(['order' => OrderFactory::new()->delivered()->create(['sale' => $sale]), 'saleOffer' => $offer, 'quantity' => Quantity::units($quantity)]);
    }
}
