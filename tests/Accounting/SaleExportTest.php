<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Model\DeliveryGroup;
use App\Accounting\Model\ProductionLine;
use App\Accounting\Service\SaleExportBuilder;
use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Sale\Entity\Sale;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

final class SaleExportTest extends WebTestCase
{
    use Factories;

    public function testProductionSheetTotalsEachProductWithoutCancelledOrders(): void
    {
        self::bootKernel();
        $sale = $this->createSale(UserFactory::createOne());

        $sheet = self::getContainer()->get(SaleExportBuilder::class)->productionSheet($sale);

        self::assertSame(4, $sheet->orderCount);
        // 4 baguettes + 4 loaves + 1 product sold by weight (2 kg of spelt).
        self::assertSame(9, $sheet->itemCount);
        self::assertSame(4060, $sheet->totalCents);
        self::assertSame(
            [['Baguette tradition', 'pièce', 4000, 2, 520], ['Épeautre', 'kg', 2000, 1, 1460], ['Pain de campagne', 'pièce', 4000, 3, 2080]],
            array_map(static fn (ProductionLine $line): array => [$line->label, $line->unit->short(), $line->quantity, $line->orderCount, $line->totalCents], $sheet->lines),
        );

        $html = self::getContainer()->get(Environment::class)->render('accounting/pdf/production.html.twig', ['sheet' => $sheet]);
        $crawler = new Crawler($html);

        self::assertStringContainsString('Fiche de production', $crawler->filter('.kicker')->text());
        self::assertSame('Fournée du lundi', $crawler->filter('h1')->text());
        self::assertSame(['Commandes 4', 'Articles à préparer 9', 'Produits différents 3', "Total des commandes 40,60\u{00A0}€"], $crawler->filter('.figures td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame('Pain de campagne', $crawler->filter('.data tbody tr')->eq(2)->filter('td')->eq(0)->text());
        self::assertSame('4', $crawler->filter('.data tbody tr')->eq(2)->filter('td')->eq(1)->text());
        self::assertStringNotContainsString('display: flex', $html);
        self::assertStringNotContainsString('display: grid', $html);
    }

    public function testDeliverySheetGroupsByDeliveryPointAndSortsByName(): void
    {
        self::bootKernel();
        $sale = $this->createSale(UserFactory::createOne());

        $sheet = self::getContainer()->get(SaleExportBuilder::class)->deliverySheet($sale);

        self::assertSame(4, $sheet->orderCount);
        self::assertSame(4060, $sheet->totalCents);
        self::assertSame(
            [
                ['Place du village', ['Bernard Zoé', 'Éluard Paul', 'Martin Alice'], 2210],
                ['Livraison à domicile', ['Durand Chloé'], 1850],
            ],
            array_map(static fn (DeliveryGroup $group): array => [
                $group->delivery->getDeliveryPoint()->getName(),
                array_map(static fn (Order $order): string => $order->getCustomer()->getLastName().' '.$order->getCustomer()->getFirstName(), $group->orders),
                $group->totalCents,
            ], $sheet->groups),
        );

        $html = self::getContainer()->get(Environment::class)->render('accounting/pdf/delivery.html.twig', ['sheet' => $sheet]);
        $crawler = new Crawler($html);

        self::assertCount(2, $crawler->filter('table.group'));
        self::assertStringContainsString('Place du village', $crawler->filter('table.group')->eq(0)->text());
        self::assertStringContainsString('1 place de la Mairie', $crawler->filter('table.group')->eq(0)->text());
        self::assertStringContainsString("18\u{00A0}h – 19\u{00A0}h", $crawler->filter('table.group')->eq(0)->text());

        // One empty "delivered" box per order, even for an order already marked as delivered.
        self::assertCount(4, $crawler->filter('.data tbody tr'));
        self::assertCount(4, $crawler->filter('.data tbody .box-check'));
        self::assertSame('', trim($crawler->filter('.data tbody .box-check')->eq(0)->text()));

        $paul = $crawler->filter('.data tbody tr')->eq(1);
        self::assertStringContainsString('Éluard Paul', $paul->text());
        self::assertStringContainsString('Tél. 06 11 22 33 44', $paul->text());
        self::assertStringContainsString('2 × Pain de campagne', $paul->text());
        self::assertStringContainsString('Note : Bien cuit, merci', $paul->text());
        self::assertSame("10,40\u{00A0}€", $paul->filter('td')->last()->text());
        self::assertStringNotContainsString('Adresse :', $paul->text());

        $home = $crawler->filter('.data tbody tr')->eq(3);
        self::assertStringContainsString('Durand Chloé', $home->text());
        self::assertStringContainsString('Adresse : 12 chemin des Vignes', $home->text());
        self::assertStringContainsString("2\u{00A0}kg Épeautre", $home->text());
        self::assertStringContainsString("18,50\u{00A0}€", $home->text());

        self::assertStringNotContainsString('Nadia', $html, 'A cancelled order must not be on the delivery sheet.');
        self::assertStringContainsString('Moyens de paiement acceptés', $html);
        self::assertDoesNotMatchRegularExpression('/pay[ée]e?s?\b|encaiss/iu', $crawler->filter('body')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePdfRoutes(): iterable
    {
        yield 'production' => ['production.pdf', 'production-2026-11-16-'];
        yield 'delivery' => ['livraison.pdf', 'livraison-2026-11-16-'];
    }

    #[DataProvider('providePdfRoutes')]
    public function testPdfRoutesAnswerARealPdf(string $file, string $filenamePrefix): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $sale = $this->createSale($producer);

        $client->loginUser($producer, 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/%s', $sale->getId(), $file));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $response = $client->getResponse();
        self::assertSame(\sprintf('inline; filename=%s%s.pdf', $filenamePrefix, strtolower($sale->getCode())), $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $pdf = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertStringContainsString('%%EOF', substr($pdf, -32));
        self::assertGreaterThan(5000, \strlen($pdf));
        self::assertSame((string) \strlen($pdf), $response->headers->get('Content-Length'));
        // Fonts are embedded (DejaVu subset), nothing depends on the reader's machine.
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+DejaVuSans#', $pdf);
    }

    public function testOrdersCsvHasOneRowPerOrderLine(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $sale = $this->createSale($producer);

        $client->loginUser($producer, 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes.csv', $sale->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $response = $client->getResponse();
        self::assertSame(\sprintf('attachment; filename=commandes-2026-11-16-%s.csv', strtolower($sale->getCode())), $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $csv = (string) $response->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringNotContainsString("\xEF\xBB\xBF", substr($csv, 3));

        $rows = $this->parse($csv);
        self::assertSame(SaleExportBuilder::ORDERS_CSV_HEADER, array_shift($rows));
        // 4 active orders (1 + 1 + 2 + 2 lines) and the cancelled one (1 line).
        self::assertCount(7, $rows);
        foreach ($rows as $row) {
            self::assertCount(\count(SaleExportBuilder::ORDERS_CSV_HEADER), $row);
        }

        $byCustomer = [];
        foreach ($rows as $row) {
            $byCustomer[$row[5]][] = array_combine(SaleExportBuilder::ORDERS_CSV_HEADER, $row);
        }

        $paul = $byCustomer['Éluard'][0];
        self::assertSame('Fournée du lundi', $paul['Vente']);
        self::assertSame($sale->getCode(), $paul['Code vente']);
        self::assertSame('16/11/2026', $paul['Date de livraison']);
        self::assertSame('Confirmée', $paul['Statut']);
        self::assertSame('Paul', $paul['Prénom']);
        self::assertSame('paul@example.test', $paul['E-mail']);
        self::assertSame('06 11 22 33 44', $paul['Téléphone']);
        self::assertSame('Place du village', $paul['Point de livraison']);
        self::assertSame('Bien cuit, merci', $paul['Note']);
        self::assertSame('Pain de campagne', $paul['Produit']);
        self::assertSame('2', $paul['Quantité']);
        self::assertSame('5,20', $paul['Prix unitaire TTC']);
        self::assertSame('5,5', $paul['Taux de TVA (%)']);
        self::assertSame('10,40', $paul['Total ligne TTC']);
        self::assertSame('10,40', $paul['Total commande TTC']);

        self::assertCount(2, $byCustomer['Durand']);
        self::assertSame("12 chemin des Vignes\n26400 Saoû", $byCustomer['Durand'][0]['Adresse de livraison']);
        self::assertSame(['Baguette tradition', 'Épeautre'], array_column($byCustomer['Durand'], 'Produit'));
        self::assertSame(['18,50', '18,50'], array_column($byCustomer['Durand'], 'Total commande TTC'));
        self::assertSame('Livrée', $byCustomer['Martin'][0]['Statut']);
        self::assertSame('Annulée', $byCustomer['Petit'][0]['Statut']);
    }

    public function testOrdersCsvNeutralisesFormulasTypedByCustomers(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        self::assertNotNull($shop);
        $sale = SaleFactory::createOne(['shop' => $shop, 'title' => '@Vente']);
        $offer = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => '-Pain'])]);
        $order = OrderFactory::createOne([
            'sale' => $sale,
            'customer' => CustomerFactory::createOne(['firstName' => '+Zoé', 'lastName' => '=HYPERLINK("http://evil.test";"Clique")', 'phone' => '+33 6 12 34 56 78']),
            'note' => "=cmd|' /C calc'!A0",
            'deliveryAddress' => "\t=1+1",
        ]);
        OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => 1000]);

        $client->loginUser($producer, 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/commandes.csv', $sale->getId()));

        self::assertResponseIsSuccessful();
        $row = array_combine(SaleExportBuilder::ORDERS_CSV_HEADER, $this->parse((string) $client->getResponse()->getContent())[1]);

        self::assertSame("'@Vente", $row['Vente']);
        self::assertSame('\'=HYPERLINK("http://evil.test";"Clique")', $row['Nom']);
        self::assertSame("'+Zoé", $row['Prénom']);
        self::assertSame("'+33 6 12 34 56 78", $row['Téléphone']);
        self::assertSame("'=cmd|' /C calc'!A0", $row['Note']);
        self::assertSame("'\t=1+1", $row['Adresse de livraison']);
        self::assertSame("'-Pain", $row['Produit']);

        foreach ($row as $cell) {
            self::assertDoesNotMatchRegularExpression('/^[=+@\t\r]/', $cell);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExports(): iterable
    {
        yield 'pro_sale_production_pdf' => ['production.pdf'];
        yield 'pro_sale_delivery_pdf' => ['livraison.pdf'];
        yield 'pro_sale_orders_csv' => ['commandes.csv'];
    }

    #[DataProvider('provideExports')]
    public function testAnotherProducerGetsA404(string $file): void
    {
        $client = self::createClient();
        $sale = $this->createSale(UserFactory::createOne());

        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', \sprintf('/pro/ventes/%d/%s', $sale->getId(), $file));

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('provideExports')]
    public function testAnonymousVisitorsAreSentToTheLoginForm(string $file): void
    {
        $client = self::createClient();
        $sale = $this->createSale(UserFactory::createOne());

        $client->request('GET', \sprintf('/pro/ventes/%d/%s', $sale->getId(), $file));

        self::assertResponseRedirects('/pro/connexion');
    }

    #[DataProvider('provideExports')]
    public function testAnUnknownSaleIsA404(string $file): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/ventes/999999/'.$file);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A sale delivered on Monday 16 November 2026, with two delivery points, four active
     * orders (one of them already delivered) and a cancelled one. Active total: 40,60 €.
     */
    private function createSale(User $producer): Sale
    {
        $shop = $producer->getShop();
        self::assertNotNull($shop);

        $date = new \DateTimeImmutable('2026-11-16');
        $sale = SaleFactory::createOne([
            'shop' => $shop,
            'title' => 'Fournée du lundi',
            'opensAt' => $date->modify('-7 days 08:00'),
            'deadlineAt' => $date->modify('-3 days 20:00'),
            'deliveryDate' => $date,
        ]);

        $village = SaleDeliveryFactory::createOne([
            'sale' => $sale,
            'deliveryPoint' => DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du village', 'address' => '1 place de la Mairie']),
            'position' => 0,
        ]);
        $home = SaleDeliveryFactory::createOne([
            'sale' => $sale,
            'deliveryPoint' => DeliveryPointFactory::new()->home()->create(['shop' => $shop]),
            'position' => 1,
        ]);

        $bread = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550])]);
        $baguette = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Baguette tradition', 'priceCents' => 130, 'vatRateBp' => 550])]);
        $spelt = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Épeautre', 'priceCents' => 730, 'vatRateBp' => 550, 'unit' => ProductUnit::Kg])]);

        // Created out of alphabetical order on purpose.
        $alice = OrderFactory::new()->delivered()->create(['sale' => $sale, 'saleDelivery' => $village, 'customer' => CustomerFactory::createOne(['firstName' => 'Alice', 'lastName' => 'Martin'])]);
        OrderLineFactory::createOne(['order' => $alice, 'saleOffer' => $bread, 'quantity' => 1000]);

        $paul = OrderFactory::createOne([
            'sale' => $sale,
            'saleDelivery' => $village,
            'customer' => CustomerFactory::createOne(['firstName' => 'Paul', 'lastName' => 'Éluard', 'email' => 'paul@example.test', 'phone' => '06 11 22 33 44']),
            'note' => 'Bien cuit, merci',
        ]);
        OrderLineFactory::createOne(['order' => $paul, 'saleOffer' => $bread, 'quantity' => 2000]);

        $chloe = OrderFactory::createOne([
            'sale' => $sale,
            'saleDelivery' => $home,
            'customer' => CustomerFactory::createOne(['firstName' => 'Chloé', 'lastName' => 'Durand']),
            'deliveryAddress' => "12 chemin des Vignes\n26400 Saoû",
        ]);
        OrderLineFactory::createOne(['order' => $chloe, 'saleOffer' => $baguette, 'quantity' => 3000]);
        OrderLineFactory::createOne(['order' => $chloe, 'saleOffer' => $spelt, 'quantity' => 2000]);

        $zoe = OrderFactory::createOne(['sale' => $sale, 'saleDelivery' => $village, 'customer' => CustomerFactory::createOne(['firstName' => 'Zoé', 'lastName' => 'Bernard'])]);
        OrderLineFactory::createOne(['order' => $zoe, 'saleOffer' => $baguette, 'quantity' => 1000]);
        OrderLineFactory::createOne(['order' => $zoe, 'saleOffer' => $bread, 'quantity' => 1000]);

        $nadia = OrderFactory::new()->cancelled()->create(['sale' => $sale, 'saleDelivery' => $village, 'customer' => CustomerFactory::createOne(['firstName' => 'Nadia', 'lastName' => 'Petit'])]);
        OrderLineFactory::createOne(['order' => $nadia, 'saleOffer' => $bread, 'quantity' => 50000]);

        return $sale;
    }

    /**
     * @return list<list<string>>
     */
    private function parse(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);

        $rows = [];
        while (false !== $row = fgetcsv($stream, null, ';', '"', '')) {
            $rows[] = array_map(static fn (?string $cell): string => $cell ?? '', $row);
        }
        fclose($stream);

        return $rows;
    }
}
