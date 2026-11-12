<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Model\ProductRow;
use App\Accounting\Model\VatRateRow;
use App\Accounting\Service\SalesJournalBuilder;
use App\Accounting\Service\SalesJournalCsvExporter;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SalesJournalBuilderTest extends KernelTestCase
{
    use Factories;

    public function testAddsUpDeliveredOrdersByVatRateProductAndSale(): void
    {
        $shop = ShopFactory::createOne();
        $first = $this->sale($shop, '2026-11-02', 'Lundi 2');
        $second = $this->sale($shop, '2026-11-30', 'Lundi 30');

        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550]);
        $jam = ProductFactory::createOne(['shop' => $shop, 'name' => 'Confiture', 'priceCents' => 999, 'vatRateBp' => 1000]);
        $soap = ProductFactory::createOne(['shop' => $shop, 'name' => 'Savon', 'priceCents' => 450, 'vatRateBp' => 2000]);

        $breadFirst = SaleOfferFactory::createOne(['sale' => $first, 'product' => $bread]);
        $jamFirst = SaleOfferFactory::createOne(['sale' => $first, 'product' => $jam]);
        $breadSecond = SaleOfferFactory::createOne(['sale' => $second, 'product' => $bread]);
        $soapSecond = SaleOfferFactory::createOne(['sale' => $second, 'product' => $soap]);

        // Sale 1: two delivered orders.
        $this->order($first, OrderStatus::Delivered, [[$breadFirst, 1], [$jamFirst, 1]]);   // 5,20 + 9,99
        $this->order($first, OrderStatus::Delivered, [[$breadFirst, 1]]);                   // 5,20
        // Sale 2: one delivered order.
        $this->order($second, OrderStatus::Delivered, [[$breadSecond, 1], [$soapSecond, 3]]); // 5,20 + 13,50

        // Left out: not delivered yet, not delivered, cancelled.
        $this->order($first, OrderStatus::Confirmed, [[$breadFirst, 10]]);
        $this->order($first, OrderStatus::NotDelivered, [[$jamFirst, 10]]);
        $this->order($second, OrderStatus::Cancelled, [[$soapSecond, 10]]);
        // Left out: other months and another shop.
        $october = $this->sale($shop, '2026-10-31', 'Octobre');
        $this->order($october, OrderStatus::Delivered, [[SaleOfferFactory::createOne(['sale' => $october, 'product' => $bread]), 4]]);
        $december = $this->sale($shop, '2026-12-01', 'Décembre');
        $this->order($december, OrderStatus::Delivered, [[SaleOfferFactory::createOne(['sale' => $december, 'product' => $bread]), 4]]);
        $foreign = $this->sale(ShopFactory::createOne(), '2026-11-16', 'Autre boutique');
        $this->order($foreign, OrderStatus::Delivered, [[SaleOfferFactory::createOne(['sale' => $foreign]), 4]]);

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-11-18 15:00'));

        self::assertSame('2026-11-01 00:00', $journal->month->format('Y-m-d H:i'));
        self::assertFalse($journal->isEmpty());
        self::assertSame(3, $journal->orderCount);

        // Line by line: 5,20 € at 5,5 % → 4,93 HT + 0,27 ; 9,99 € at 10 % → 9,08 + 0,91 ; 13,50 € at 20 % → 11,25 + 2,25.
        self::assertSame(
            [550 => [3, 1560, 1479, 81], 1000 => [1, 999, 908, 91], 2000 => [3, 1350, 1125, 225]],
            array_column(array_map(static fn (VatRateRow $row): array => [$row->vatRateBp, self::amounts($row)], $journal->byVatRate), 1, 0),
        );

        // Rounding per line: 3 × 4,93 = 14,79 HT, whereas 15,60 / 1,055 would give 14,79 as well
        // here, but the rule is the sum of the lines (see VatCalculator).
        self::assertSame([7, 3909, 3512, 397], [intdiv($journal->total->quantity, Quantity::UNIT), $journal->total->grossCents, $journal->total->netCents, $journal->total->vatCents]);
        self::assertSame($journal->total->grossCents, $journal->total->netCents + $journal->total->vatCents);

        self::assertSame(
            ['Confiture' => [1, 999, 908, 91], 'Pain de campagne' => [3, 1560, 1479, 81], 'Savon' => [3, 1350, 1125, 225]],
            array_column(array_map(static fn (ProductRow $row): array => [$row->label, self::amounts($row)], $journal->byProduct), 1, 0),
        );

        self::assertCount(2, $journal->bySale);
        self::assertSame(['Lundi 2', 2, 2039, 1894, 145], [$journal->bySale[0]->title, $journal->bySale[0]->orderCount, $journal->bySale[0]->amounts->grossCents, $journal->bySale[0]->amounts->netCents, $journal->bySale[0]->amounts->vatCents]);
        self::assertSame(['Lundi 30', 1, 1870, 1618, 252], [$journal->bySale[1]->title, $journal->bySale[1]->orderCount, $journal->bySale[1]->amounts->grossCents, $journal->bySale[1]->amounts->netCents, $journal->bySale[1]->amounts->vatCents]);

        // Every breakdown adds up to the same cent.
        foreach ([$journal->byVatRate, $journal->byProduct, $journal->bySale, $journal->details] as $rows) {
            self::assertSame(3909, array_sum(array_map(static fn (object $row): int => $row->amounts->grossCents, $rows)));
            self::assertSame(3512, array_sum(array_map(static fn (object $row): int => $row->amounts->netCents, $rows)));
            self::assertSame(397, array_sum(array_map(static fn (object $row): int => $row->amounts->vatCents, $rows)));
        }
        self::assertCount(4, $journal->details);
    }

    public function testRoundingIsDonePerOrderLineNotOnTheTotal(): void
    {
        $shop = ShopFactory::createOne();
        $sale = $this->sale($shop, '2026-11-09', 'Lundi 9');
        $baguette = SaleOfferFactory::createOne([
            'sale' => $sale,
            'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Baguette', 'priceCents' => 130, 'vatRateBp' => 550]),
        ]);

        // Three orders of one baguette: 1,30 € → 1,23 HT (123,22) + 0,07 TVA each.
        for ($i = 0; $i < 3; ++$i) {
            $this->order($sale, OrderStatus::Delivered, [[$baguette, 1]]);
        }

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-11-01'));

        // Sum of the lines: 3,69 HT + 0,21 TVA. On the total, 3,90 / 1,055 would give 3,70 + 0,20.
        self::assertSame([3, 390, 369, 21], [intdiv($journal->total->quantity, Quantity::UNIT), $journal->total->grossCents, $journal->total->netCents, $journal->total->vatCents]);
    }

    public function testAProductWhoseVatRateChangedAppearsOncePerRate(): void
    {
        $shop = ShopFactory::createOne();
        $product = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'priceCents' => 600, 'vatRateBp' => 550]);
        $first = $this->sale($shop, '2026-11-02', 'Lundi 2');
        $this->order($first, OrderStatus::Delivered, [[SaleOfferFactory::createOne(['sale' => $first, 'product' => $product]), 1]]);
        $second = $this->sale($shop, '2026-11-09', 'Lundi 9');
        $this->order($second, OrderStatus::Delivered, [[SaleOfferFactory::createOne(['sale' => $second, 'product' => $product, 'vatRateBp' => 1000]), 2]]);

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-11-01'));

        self::assertSame([['Brioche', 550, 600], ['Brioche', 1000, 1200]], array_map(static fn (ProductRow $row): array => [$row->label, $row->vatRateBp, $row->amounts->grossCents], $journal->byProduct));
        self::assertCount(2, $journal->byVatRate);
    }

    public function testAMonthWithoutDeliveredOrderIsEmpty(): void
    {
        $shop = ShopFactory::createOne();
        $sale = $this->sale($shop, '2026-11-09', 'Lundi 9');
        $this->order($sale, OrderStatus::Confirmed, [[SaleOfferFactory::createOne(['sale' => $sale]), 2]]);

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-11-01'));

        self::assertTrue($journal->isEmpty());
        self::assertSame(0, $journal->total->grossCents);
        self::assertSame([], $journal->byVatRate);
        self::assertSame('2026-10-01', $journal->previousMonth()->format('Y-m-d'));
        self::assertSame('2026-12-01', $journal->nextMonth()->format('Y-m-d'));
    }

    public function testCsvExportHasDetailAndTotalRows(): void
    {
        $shop = ShopFactory::createOne();
        $sale = $this->sale($shop, '2026-11-09', '=Lundi 9');
        $offer = SaleOfferFactory::createOne([
            'sale' => $sale,
            'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550]),
        ]);
        $this->order($sale, OrderStatus::Delivered, [[$offer, 2]]);

        $container = self::getContainer();
        $csv = $container->get(SalesJournalCsvExporter::class)->export($container->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-11-01')));

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", trim(substr($csv, 3)));
        self::assertSame('Type;"Date de livraison";Vente;"Code vente";Produit;"Taux de TVA (%)";Quantité;Unité;"CA TTC";"CA HT";TVA;"Mention TVA"', $lines[0]);
        self::assertSame(\sprintf('Détail;09/11/2026;"\'=Lundi 9";%s;"Pain de campagne";5,5;2;pièce;10,40;9,86;0,54;', $sale->getCode()), $lines[1]);
        // Total rows add up several products: no quantity, it would mix pieces and kilograms.
        self::assertSame("Total;;;;\"Total TVA 5,5\u{00A0}%\";5,5;;;10,40;9,86;0,54;", $lines[2]);
        self::assertSame('Total;;;;"Total du mois";;;;10,40;9,86;0,54;', $lines[3]);
        self::assertCount(4, $lines);
    }

    private function sale(Shop $shop, string $deliveryDate, string $title): Sale
    {
        $date = new \DateTimeImmutable($deliveryDate);

        return SaleFactory::createOne([
            'shop' => $shop,
            'title' => $title,
            'opensAt' => $date->modify('-10 days'),
            'deadlineAt' => $date->modify('-3 days'),
            'deliveryDate' => $date,
        ]);
    }

    /**
     * @param list<array{SaleOffer, int}> $lines
     */
    private function order(Sale $sale, OrderStatus $status, array $lines): void
    {
        $order = OrderFactory::createOne(['sale' => $sale, 'status' => $status]);
        foreach ($lines as [$offer, $quantity]) {
            OrderLineFactory::createOne(['order' => $order, 'saleOffer' => $offer, 'quantity' => Quantity::units($quantity)]);
        }
    }

    /**
     * @return array{int, int, int, int} quantity in whole units, gross, net and VAT cents
     */
    private static function amounts(VatRateRow|ProductRow $row): array
    {
        return [intdiv($row->amounts->quantity, Quantity::UNIT), $row->amounts->grossCents, $row->amounts->netCents, $row->amounts->vatCents];
    }
}
