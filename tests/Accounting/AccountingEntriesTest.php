<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Entity\AccountingSettings;
use App\Accounting\Enum\AccountingCounterpart;
use App\Accounting\Model\EntryLine;
use App\Accounting\Model\JournalEntry;
use App\Accounting\Repository\AccountingSettingsRepository;
use App\Accounting\Service\AccountingEntriesBuilder;
use App\Accounting\Service\AccountingEntriesExporter;
use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Sale\Entity\Sale;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Enum\VatRegime;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Accounting entries of the sales journal (CSV and FEC format) and their accounts.
 */
final class AccountingEntriesTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOneBalancedEntryPerDeliveredOrder(): void
    {
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $sale = $this->sale($shop, '2026-09-14');
        $order = $this->order($sale, [['Pain', 400, 550, 2000], ['Vin', 1200, 2000, 1000]], ['customerFirstName' => 'Léa', 'customerLastName' => 'Martin']);
        $this->order($this->sale($shop, '2026-09-21'), [['Savon', 450, 2000, 1000]]);
        // Out of scope: not delivered, other month, other shop.
        $this->order($sale, [['Pain', 400, 550, 1000]], [], 'confirmed');
        $this->order($sale, [['Pain', 400, 550, 1000]], [], 'cancelled');
        $this->order($this->sale($shop, '2026-10-01'), [['Pain', 400, 550, 1000]]);
        $this->order($this->sale($this->shopOf(UserFactory::createOne()), '2026-09-14'), [['Pain', 400, 550, 1000]]);

        $entries = $this->build($shop, '2026-09-01');

        self::assertCount(2, $entries);
        [$first, $second] = $entries;
        self::assertSame('2026-09-14', $first->date->format('Y-m-d'));
        self::assertSame($order->getReference(), $first->piece);
        self::assertFalse($first->vatExempt);
        // 8,00 € at 5,5 % → 7,58 + 0,42 ; 12,00 € at 20 % → 10,00 + 2,00.
        self::assertSame([
            ['411', 'Clients', 'Client Léa Martin - cde '.$order->getReference(), 2000, 0],
            ['706', 'Ventes', 'Ventes TVA 5,5 % - cde '.$order->getReference(), 0, 758],
            ['706', 'Ventes', 'Ventes TVA 20 % - cde '.$order->getReference(), 0, 1000],
            ['44571', 'TVA collectée', 'TVA collectée 5,5 % - cde '.$order->getReference(), 0, 42],
            ['44571', 'TVA collectée', 'TVA collectée 20 % - cde '.$order->getReference(), 0, 200],
        ], $this->rows($first));
        self::assertSame(2000, $first->debitCents());
        self::assertSame(2000, $first->creditCents());
        self::assertSame('2026-09-21', $second->date->format('Y-m-d'));
        self::assertSame($second->debitCents(), $second->creditCents());
    }

    public function testLinesAreAddedUpByRateInRateOrder(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        // 20 % first, two lines at 5,5 %, and a 1-cent line whose VAT rounds to 0.
        $order = $this->order($this->sale($shop, '2026-09-14'), [['Vin', 1200, 2000, 1000], ['Pain', 400, 550, 1000], ['Brioche', 600, 550, 1000], ['Bonbon', 1, 210, 1000]]);

        [$entry] = $this->build($shop, '2026-09-01');
        $reference = $order->getReference();

        self::assertSame([
            ['411', 'Clients', 'Client '.$order->getCustomerDisplayName().' - cde '.$reference, 2201, 0],
            ['706', 'Ventes', 'Ventes TVA 2,1 % - cde '.$reference, 0, 1],
            ['706', 'Ventes', 'Ventes TVA 5,5 % - cde '.$reference, 0, 948],
            ['706', 'Ventes', 'Ventes TVA 20 % - cde '.$reference, 0, 1000],
            ['44571', 'TVA collectée', 'TVA collectée 5,5 % - cde '.$reference, 0, 52],
            ['44571', 'TVA collectée', 'TVA collectée 20 % - cde '.$reference, 0, 200],
        ], $this->rows($entry));
    }

    public function testTheMonthStartsAtMidnightOnTheFirst(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $this->order($this->sale($shop, '2026-09-01'), [['Pain', 400, 550, 1000]]);

        self::assertCount(1, self::getContainer()->get(AccountingEntriesBuilder::class)->build($shop, new \DateTimeImmutable('2026-09-17 15:30'), new AccountingSettings($shop)));
    }

    public function testWeighedLinesUseTheActualWeight(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $sale = $this->sale($shop, '2026-09-14');
        $order = OrderFactory::new()->delivered()->create(['sale' => $sale]);
        $cheese = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Tomme', 'unit' => ProductUnit::Kg, 'priceCents' => 2000, 'vatRateBp' => 550])]);
        // 1 kg ordered, 1,250 kg weighed: 25,00 €.
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $cheese, 'quantity' => 1000, 'actualQuantity' => 1250]);

        [$entry] = $this->build($shop, '2026-09-01');

        self::assertSame(2500, $entry->debitCents());
        self::assertSame([2370, 130], [$entry->lines[1]->creditCents, $entry->lines[2]->creditCents]);
    }

    public function testFranchiseOrdersHaveNoVatLine(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $shop->setVatRegime(VatRegime::Franchise);
        $order = $this->order($this->sale($shop, '2026-09-14'), [['Miel', 1000, 550, 2000], ['Savon', 450, 2000, 1000]]);

        [$entry] = $this->build($shop, '2026-09-01');

        self::assertTrue($entry->vatExempt);
        self::assertSame([
            ['411', 'Clients', 'Client '.$order->getCustomerDisplayName().' - cde '.$order->getReference(), 2450, 0],
            ['706', 'Ventes', 'Ventes franchise TVA art. 293 B CGI - cde '.$order->getReference(), 0, 2450],
        ], $this->rows($entry));
    }

    public function testTheCashCounterpartAndCustomAccountsAreUsed(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $order = $this->order($this->sale($shop, '2026-09-14'), [['Pain', 400, 550, 1000]]);
        $settings = new AccountingSettings($shop)
            ->setCounterpart(AccountingCounterpart::Cash)
            ->setCashAccount(' 531 ')
            ->setSalesAccount('701 100')
            ->setVatAccount('445712');

        [$entry] = self::getContainer()->get(AccountingEntriesBuilder::class)->build($shop, new \DateTimeImmutable('2026-09-01'), $settings);

        self::assertSame(['531', 'Caisse', 'Caisse '.$order->getCustomerDisplayName().' - cde '.$order->getReference(), 400, 0], $this->rows($entry)[0]);
        self::assertSame('701100', $entry->lines[1]->account);
        self::assertSame('445712', $entry->lines[2]->account);
    }

    public function testAnOrderWithoutAmountHasNoEntry(): void
    {
        $shop = $this->shopOf(UserFactory::createOne());
        $sale = $this->sale($shop, '2026-09-14');
        $order = OrderFactory::new()->delivered()->create(['sale' => $sale]);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'unit' => ProductUnit::Kg, 'priceCents' => 2000])]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => 1000, 'actualQuantity' => 0]);

        self::assertSame([], $this->build($shop, '2026-09-01'));
    }

    public function testTheCsvExportIsImportable(): void
    {
        $producer = UserFactory::createOne();
        $order = $this->order($this->sale($this->shopOf($producer), '2026-09-14'), [['Pain', 400, 550, 2000]], ['customerFirstName' => '=Léa', 'customerLastName' => 'Martin']);

        $this->client->loginUser($producer, 'producer');
        $this->client->request('GET', '/pro/comptabilite/ecritures.csv?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('ecritures-ventes-2026-09.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $reference = $order->getReference();
        self::assertSame(
            "\u{FEFF}Date;Journal;Compte;Libellé;Débit;Crédit;Pièce\r\n"
            ."14/09/2026;VE;411;\"Client =Léa Martin - cde {$reference}\";8,00;;{$reference}\r\n"
            ."14/09/2026;VE;706;\"Ventes TVA 5,5 % - cde {$reference}\";;7,58;{$reference}\r\n"
            ."14/09/2026;VE;44571;\"TVA collectée 5,5 % - cde {$reference}\";;0,42;{$reference}\r\n",
            $this->client->getResponse()->getContent(),
        );
    }

    public function testTheFecExportHasTheEighteenOfficialColumns(): void
    {
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $first = $this->order($this->sale($shop, '2026-09-14'), [['Pain', 400, 550, 1000]], ['customerFirstName' => "Léa\tLou", 'customerLastName' => "Mar\ntin"]);
        $second = $this->order($this->sale($shop, '2026-09-21'), [['Savon', 450, 2000, 1000]]);

        $this->client->loginUser($producer, 'producer');
        $this->client->request('GET', '/pro/comptabilite/ecritures-fec.txt?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringContainsString('ecritures-ventes-2026-09-format-fec.txt', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $lines = explode("\r\n", (string) $this->client->getResponse()->getContent());
        self::assertSame('', array_pop($lines), 'Every record ends with a line break.');
        self::assertSame(implode("\t", AccountingEntriesExporter::FEC_HEADER), $lines[0]);
        self::assertCount(18, AccountingEntriesExporter::FEC_HEADER);
        self::assertCount(1 + 3 + 3, $lines);
        foreach ($lines as $line) {
            self::assertCount(18, explode("\t", $line));
        }

        self::assertSame(
            ['VE', 'Journal des ventes', 'VE2026090001', '20260914', '411', 'Clients', '', '', $first->getReference(), '20260914', 'Client Léa Lou Mar tin - cde '.$first->getReference(), '4,00', '0,00', '', '', '', '', ''],
            explode("\t", $lines[1]),
        );
        self::assertSame(['VE2026090001', '706', '0,00', '3,79'], $this->pick(explode("\t", $lines[2]), [2, 4, 11, 12]));
        self::assertSame(['VE2026090002', '20260921', '44571', $second->getReference(), '0,75'], $this->pick(explode("\t", $lines[6]), [2, 3, 4, 8, 12]));
    }

    public function testTheScreenExplainsTheEntriesAndLinksTheExports(): void
    {
        $producer = UserFactory::createOne();
        $this->order($this->sale($this->shopOf($producer), '2026-09-14'), [['Pain', 400, 550, 1000]]);

        $this->client->loginUser($producer, 'producer');
        $crawler = $this->client->request('GET', '/pro/comptabilite?mois=2026-09');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-entries-export] h2', 'Pour ton comptable');
        self::assertSelectorTextContains('[data-no-payment]', 'Panier Sauvage n\'enregistre aucun paiement');
        self::assertSelectorTextContains('[data-no-payment]', 'lettre le compte client 411');
        self::assertSelectorTextContains('[data-entries-export]', 'art. 293 B');
        self::assertSame('/pro/comptabilite/ecritures.csv?mois=2026-09', $crawler->selectLink('Écritures (CSV)')->attr('href'));
        self::assertSame('/pro/comptabilite/ecritures-fec.txt?mois=2026-09', $crawler->selectLink('Écritures au format FEC')->attr('href'));
        self::assertSame('/pro/comptabilite/comptes', $crawler->filter('[data-accounts-link]')->attr('href'));
        self::assertSame(['411', '706', '44571'], $crawler->filter('[data-accounts] li span:first-child')->each(static fn ($node): string => $node->text()));

        // An empty month: the explanation stays, no download.
        $this->client->request('GET', '/pro/comptabilite?mois=2026-08');
        self::assertSelectorExists('[data-entries-export]');
        self::assertSelectorNotExists('a[href^="/pro/comptabilite/ecritures"]');
    }

    public function testTheProducerChoosesTheAccounts(): void
    {
        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/comptabilite/comptes');
        self::assertResponseIsSuccessful();
        self::assertSame('706', $crawler->filter('#accounting_settings_salesAccount')->attr('value'));
        self::assertSame('text', $crawler->filter('#accounting_settings_salesAccount')->attr('inputmode'));
        foreach (['Compte de ventes', 'Compte de TVA collectée', 'Compte débité', 'Compte client', 'Compte de caisse', 'Code journal'] as $label) {
            self::assertSelectorTextContains('main form', $label);
        }
        self::assertSame('customer', $crawler->filter('input[name="accounting_settings[counterpart]"]:checked')->attr('value'));

        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'accounting_settings[salesAccount]' => '701',
            'accounting_settings[counterpart]' => 'cash',
            'accounting_settings[cashAccount]' => '531',
            'accounting_settings[journalCode]' => 've',
        ]));

        self::assertResponseRedirects('/pro/comptabilite', 303);
        $settings = self::getContainer()->get(AccountingSettingsRepository::class)->forShop($this->shopOf($producer));
        self::assertNotNull($settings->getId());
        self::assertSame(['701', '44571', AccountingCounterpart::Cash, '531', 'VE', '531'], [$settings->getSalesAccount(), $settings->getVatAccount(), $settings->getCounterpart(), $settings->getCashAccount(), $settings->getJournalCode(), $settings->counterpartAccount()]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Tes comptes sont enregistrés');
        self::assertSelectorTextContains('[data-no-payment]', 'compte de caisse 531');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function provideInvalidAccounts(): iterable
    {
        yield 'letters first' => [['accounting_settings[salesAccount]' => 'VENTES'], 'commence par un chiffre'];
        yield 'too short' => [['accounting_settings[vatAccount]' => '44'], 'de 3 à 20'];
        yield 'symbols' => [['accounting_settings[customerAccount]' => '411-A'], 'commence par un chiffre'];
        yield 'long journal' => [['accounting_settings[journalCode]' => 'VENTES1'], 'Le code journal'];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('provideInvalidAccounts')]
    public function testInvalidAccountsAreRefused(array $fields, string $error): void
    {
        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/comptabilite/comptes');
        $this->client->submit($crawler->selectButton('Enregistrer')->form($fields));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', $error);
        self::assertNull(self::getContainer()->get(AccountingSettingsRepository::class)->forShop($this->shopOf($producer))->getId());
    }

    public function testAnEmptyFieldFallsBackToTheDefault(): void
    {
        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $crawler = $this->client->request('GET', '/pro/comptabilite/comptes');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['accounting_settings[salesAccount]' => '']));

        self::assertResponseRedirects('/pro/comptabilite', 303);
        self::assertSame('706', self::getContainer()->get(AccountingSettingsRepository::class)->forShop($this->shopOf($producer))->getSalesAccount());
    }

    public function testEachProducerOnlyExportsAndSetsTheirOwnAccounts(): void
    {
        $other = UserFactory::createOne();
        $otherOrder = $this->order($this->sale($this->shopOf($other), '2026-09-14'), [['Pain', 400, 550, 1000]]);
        $otherSettings = new AccountingSettings($this->shopOf($other))->setSalesAccount('707');
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($otherSettings);
        $entityManager->flush();

        $producer = UserFactory::createOne();
        $this->client->loginUser($producer, 'producer');

        $this->client->request('GET', '/pro/comptabilite/ecritures.csv?mois=2026-09&shop='.$this->shopOf($other)->getId());
        self::assertStringNotContainsString($otherOrder->getReference(), (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/pro/comptabilite/ecritures-fec.txt?mois=2026-09');
        self::assertStringNotContainsString($otherOrder->getReference(), (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/pro/comptabilite/comptes');
        self::assertSame('706', $crawler->filter('#accounting_settings_salesAccount')->attr('value'), 'The other shop\'s accounts are never shown.');
        $this->client->submit($crawler->selectButton('Enregistrer')->form(['accounting_settings[salesAccount]' => '701']));

        $entityManager->clear();
        self::assertSame('707', self::getContainer()->get(AccountingSettingsRepository::class)->forShop($this->shopOf($other))->getSalesAccount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'pro_accounting_entries_csv' => ['GET', '/pro/comptabilite/ecritures.csv'];
        yield 'pro_accounting_fec' => ['GET', '/pro/comptabilite/ecritures-fec.txt'];
        yield 'pro_accounting_settings' => ['GET', '/pro/comptabilite/comptes'];
        yield 'pro_accounting_settings (POST)' => ['POST', '/pro/comptabilite/comptes'];
    }

    #[DataProvider('provideRoutes')]
    public function testRequiresAProducerWithAShop(string $method, string $url): void
    {
        $this->client->request($method, $url);
        self::assertResponseRedirects('/pro/connexion');

        $this->client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $this->client->request($method, $url);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<JournalEntry>
     */
    private function build(Shop $shop, string $month): array
    {
        return self::getContainer()->get(AccountingEntriesBuilder::class)->build($shop, new \DateTimeImmutable($month), new AccountingSettings($shop));
    }

    /**
     * @return list<array{string, string, string, int, int}>
     */
    private function rows(JournalEntry $entry): array
    {
        return array_map(static fn (EntryLine $line): array => [$line->account, $line->accountLabel, $line->label, $line->debitCents, $line->creditCents], $entry->lines);
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $indexes
     *
     * @return list<string>
     */
    private function pick(array $cells, array $indexes): array
    {
        return array_map(static fn (int $index): string => $cells[$index], $indexes);
    }

    private function shopOf(User $producer): Shop
    {
        $shop = $producer->getShop();
        self::assertNotNull($shop);

        return $shop;
    }

    private function sale(Shop $shop, string $deliveryDate): Sale
    {
        $date = new \DateTimeImmutable($deliveryDate);

        return SaleFactory::new()->done()->create(['shop' => $shop, 'opensAt' => $date->modify('-10 days'), 'deadlineAt' => $date->modify('-3 days'), 'deliveryDate' => $date]);
    }

    /**
     * @param list<array{string, int, int, int}> $lines    product, price, VAT rate, quantity (thousandths)
     * @param array<string, string>              $identity
     */
    private function order(Sale $sale, array $lines, array $identity = [], string $status = 'delivered'): Order
    {
        $factory = match ($status) {
            'delivered' => OrderFactory::new()->delivered(),
            'cancelled' => OrderFactory::new()->cancelled(),
            default => OrderFactory::new(),
        };
        $order = $factory->create(['sale' => $sale]);
        if ([] !== $identity) {
            $order->setCustomerIdentity($identity['customerFirstName'] ?? null, $identity['customerLastName'] ?? null, null);
            self::getContainer()->get('doctrine')->getManager()->flush();
        }

        foreach ($lines as [$name, $price, $rate, $quantity]) {
            $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $sale->getShop(), 'name' => $name, 'priceCents' => $price, 'vatRateBp' => $rate])]);
            OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => $quantity]);
        }

        return $order;
    }
}
