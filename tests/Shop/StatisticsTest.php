<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Statistics\Bar;
use App\Shop\Statistics\Rate;
use App\Shop\Statistics\ShopStatistics;
use App\Shop\Statistics\ShopStats;
use App\Shop\Statistics\SourceShare;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderEventFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Statistiques": every figure is computed in SQL on the shop's own orders.
 */
final class StatisticsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    /** A Wednesday. */
    private const string TODAY = '2026-11-11 10:00:00';

    public function testOrdersPickedUpPerWeekCountTheDeliveredOrdersOfTheShopOnly(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $thisWeek = $this->sale($shop, '2026-11-09');
        $this->order($thisWeek, OrderStatus::Delivered, 1000);
        $this->order($thisWeek, OrderStatus::Delivered, 1000);
        $this->order($thisWeek, OrderStatus::NotDelivered, 1000);
        $this->order($thisWeek, OrderStatus::Cancelled, 1000);
        // Sunday of the previous week, and eleven weeks ago (the first bar).
        $this->order($this->sale($shop, '2026-11-08'), OrderStatus::Delivered, 1000);
        $this->order($this->sale($shop, '2026-08-24'), OrderStatus::Delivered, 1000);
        // Twelve weeks ago: out of the chart. Another shop: never counted.
        $this->order($this->sale($shop, '2026-08-23'), OrderStatus::Delivered, 1000);
        $this->order($this->sale($this->shop(), '2026-11-09'), OrderStatus::Delivered, 1000);

        $bars = $this->statistics()->compute($shop)->weeklyPickups;

        self::assertCount(ShopStatistics::WEEKS, $bars);
        self::assertSame('2026-08-24', $bars[0]->period->format('Y-m-d'));
        self::assertSame('2026-11-09', $bars[11]->period->format('Y-m-d'));
        self::assertSame([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 2], array_map(static fn (Bar $bar): int => $bar->value, $bars));
    }

    public function testRevenuePerMonthIsTheWeighedAmountOfTheDeliveredOrders(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $november = $this->sale($shop, '2026-11-02');
        $weighed = $this->order($november, OrderStatus::Delivered, 2000, weighedThousandths: 1250);
        $this->order($november, OrderStatus::NotDelivered, 5000);
        $this->order($this->sale($shop, '2026-10-31'), OrderStatus::Delivered, 1530);
        $this->order($this->sale($shop, '2025-12-01'), OrderStatus::Delivered, 700);
        $this->order($this->sale($shop, '2025-11-30'), OrderStatus::Delivered, 999);

        $bars = $this->statistics()->compute($shop)->monthlyRevenue;

        self::assertSame(2500, $weighed->getTotalCents(), '1,25 kg at 20 € a kilo.');
        self::assertCount(ShopStatistics::MONTHS, $bars);
        self::assertSame('2025-12-01', $bars[0]->period->format('Y-m-d'));
        self::assertSame(700, $bars[0]->value);
        self::assertSame(1530, $bars[10]->value);
        self::assertSame(2500, $bars[11]->value);
    }

    public function testMedianOrdersPerFinishedSale(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        foreach ([1, 4, 6, 9] as $index => $count) {
            $sale = $this->sale($shop, \sprintf('2026-10-%02d', 5 + $index));
            for ($i = 0; $i < $count; ++$i) {
                $this->order($sale, OrderStatus::Delivered, 500);
            }
            $this->order($sale, OrderStatus::Cancelled, 500);
        }
        // Not finished, or older than six months: left out.
        $this->sale($shop, '2026-11-20', SaleStatus::Open);
        $this->order($this->sale($shop, '2026-04-01'), OrderStatus::Delivered, 500);

        $stats = $this->statistics()->compute($shop);

        self::assertSame(50, $stats->medianOrdersTenths, 'Median of 1, 4, 6 and 9 orders: 5.');
        self::assertSame(4, $stats->finishedSales);

        $this->order($this->sale($shop, '2026-10-20'), OrderStatus::Delivered, 500);
        self::assertSame(40, $this->statistics()->compute($shop)->medianOrdersTenths, 'Median of 1, 1, 4, 6 and 9 orders.');
    }

    public function testASaleWithoutOrderCountsForZero(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $this->sale($shop, '2026-10-05');
        $sale = $this->sale($shop, '2026-10-12');
        $this->order($sale, OrderStatus::Delivered, 500);
        $this->order($sale, OrderStatus::Delivered, 500);
        $this->order($sale, OrderStatus::Delivered, 500);

        self::assertSame(15, $this->statistics()->compute($shop)->medianOrdersTenths, 'Median of 0 and 3: 1,5.');
        self::assertNull($this->statistics()->compute($this->shop())->medianOrdersTenths);
    }

    public function testFillRateOfTheLimitedOffers(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $bread = ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain']);
        $brioche = ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche']);
        $first = $this->sale($shop, '2026-10-05');
        $second = $this->sale($shop, '2026-10-12', SaleStatus::Closed);
        SaleOfferFactory::createOne(['sale' => $first, 'product' => $bread, 'maxQty' => Quantity::units(10), 'reservedQty' => Quantity::units(10)]);
        SaleOfferFactory::createOne(['sale' => $second, 'product' => $bread, 'maxQty' => Quantity::units(10), 'reservedQty' => Quantity::units(5)]);
        SaleOfferFactory::createOne(['sale' => $first, 'product' => $brioche, 'maxQty' => Quantity::units(8), 'reservedQty' => Quantity::units(2)]);
        // Unlimited, still open or cancelled: left out.
        SaleOfferFactory::createOne(['sale' => $second, 'product' => $brioche, 'maxQty' => null, 'reservedQty' => Quantity::units(40)]);
        SaleOfferFactory::createOne(['sale' => $this->sale($shop, '2026-11-20', SaleStatus::Open), 'product' => $bread, 'maxQty' => Quantity::units(10)]);
        SaleOfferFactory::createOne(['sale' => $this->sale($shop, '2026-10-19', SaleStatus::Cancelled), 'product' => $bread, 'maxQty' => Quantity::units(10)]);

        $stats = $this->statistics()->compute($shop);

        self::assertSame([17000, 28000], [$stats->fill->part, $stats->fill->whole]);
        self::assertSame(61, $stats->fill->percent());
        self::assertCount(2, $stats->products);
        self::assertSame('Pain', $stats->products[0]->name);
        self::assertSame(2, $stats->products[0]->offers);
        self::assertSame(1, $stats->products[0]->soldOut);
        self::assertSame(75, $stats->products[0]->fill->percent());
        self::assertSame('Brioche', $stats->products[1]->name);
        self::assertSame(25, $stats->products[1]->fill->percent());
        self::assertSame(0, $stats->products[1]->soldOut);
    }

    public function testRegularCustomersHaveTwoOrdersInSixtyDays(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $lea = CustomerFactory::createOne();
        $paul = CustomerFactory::createOne();
        $once = CustomerFactory::createOne();
        $recent = $this->sale($shop, '2026-11-02');
        $older = $this->sale($shop, '2026-09-01');
        $this->order($recent, OrderStatus::Delivered, 500, $lea);
        $this->order($this->sale($shop, '2026-11-16', SaleStatus::Open), OrderStatus::Confirmed, 500, $lea);
        $this->order($recent, OrderStatus::Delivered, 500, $paul);
        $this->order($this->sale($shop, '2026-10-26'), OrderStatus::Cancelled, 500, $paul);
        $this->order($older, OrderStatus::Delivered, 500, $paul);
        $this->order($recent, OrderStatus::Delivered, 500, $once);
        // Lea's orders elsewhere never count.
        $this->order($this->sale($this->shop(), '2026-11-02'), OrderStatus::Delivered, 500, $paul);

        $stats = $this->statistics()->compute($shop);

        self::assertSame([1, 3], [$stats->regulars->part, $stats->regulars->whole]);
        self::assertSame(33, $stats->regulars->percent());
    }

    public function testAbsenceAndCancellationRatesOfTheFinishedSales(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $sale = $this->sale($shop, '2026-10-12');
        foreach ([OrderStatus::Delivered, OrderStatus::Delivered, OrderStatus::Delivered, OrderStatus::NotDelivered, OrderStatus::Cancelled] as $status) {
            $this->order($sale, $status, 500);
        }
        $this->order($this->sale($shop, '2026-11-20', SaleStatus::Open), OrderStatus::Cancelled, 500);
        $this->order($this->sale($shop, '2026-04-01'), OrderStatus::NotDelivered, 500);

        $stats = $this->statistics()->compute($shop);

        self::assertSame([1, 4, 25], [$stats->absences->part, $stats->absences->whole, $stats->absences->percent()]);
        self::assertSame([1, 5, 20], [$stats->cancellations->part, $stats->cancellations->whole, $stats->cancellations->percent()]);
    }

    public function testProvenanceOfTheOrders(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $shop = $this->shop();
        $sale = $this->sale($shop, '2026-11-02');
        $this->order($sale, OrderStatus::Delivered, 500, source: OrderSource::SharedLink);
        $this->order($sale, OrderStatus::Delivered, 500, source: OrderSource::SharedLink);
        $this->order($sale, OrderStatus::Delivered, 500, source: OrderSource::Directory);
        $this->order($sale, OrderStatus::Delivered, 500);
        $this->order($sale, OrderStatus::Delivered, 500, byProducer: true);
        $this->order($sale, OrderStatus::Cancelled, 500, source: OrderSource::CrossDiscovery);

        $sources = $this->statistics()->compute($shop)->sources;

        self::assertSame(['shared_link', 'directory', 'producer', 'unknown'], array_map(static fn (SourceShare $source): string => $source->key, $sources));
        self::assertSame([2, 1, 1, 1], array_map(static fn (SourceShare $source): int => $source->share->part, $sources));
        self::assertSame(40, $sources[0]->share->percent());
        self::assertSame('Lien partagé par le producteur', $sources[0]->label());
        self::assertSame('Saisies par toi', $sources[2]->label());
        self::assertSame('Adresse tapée ou lien sans provenance', $sources[3]->label());
    }

    public function testRatesAndBarsRoundWithoutFloats(): void
    {
        self::assertNull(new Rate(0, 0)->percent());
        self::assertSame(0, new Rate(0, 3)->percent());
        self::assertSame(67, new Rate(2, 3)->percent());
        self::assertSame(50, new Rate(1, 2)->percent());
        self::assertSame(1, new Rate(1, 200)->percent(), '0,5 % rounds up.');
        self::assertSame(0, new Rate(1, 201)->percent());

        $bars = [new Bar(new \DateTimeImmutable(), 0), new Bar(new \DateTimeImmutable(), 1), new Bar(new \DateTimeImmutable(), 100), new Bar(new \DateTimeImmutable(), 55)];
        self::assertSame([0, 2, 100, 55], array_map(static fn (Bar $bar): int => $bar->heightIn($bars), $bars));
        self::assertSame(0, new Bar(new \DateTimeImmutable(), 0)->heightIn([]));
        self::assertSame(100, new Bar(new \DateTimeImmutable(), 5)->heightIn([]));
        self::assertSame([0, 0], array_map(static fn (Bar $bar): int => $bar->heightIn([$bar, $bar]), [new Bar(new \DateTimeImmutable(), 0), new Bar(new \DateTimeImmutable(), 0)]));

        self::assertSame('bizarre', new SourceShare('bizarre', new Rate(1, 1))->label(), 'An unknown provenance shows as stored.');
        self::assertSame('Annuaire des producteurs', new SourceShare('directory', new Rate(1, 1))->label());
    }

    public function testAShopWithOnlyCancellationsHasFigures(): void
    {
        $empty = static fn (int $cancelled, int $picked): ShopStats => new ShopStats(
            since: new \DateTimeImmutable(),
            weeklyPickups: [new Bar(new \DateTimeImmutable(), $picked)],
            monthlyRevenue: [],
            medianOrdersTenths: null,
            finishedSales: 0,
            fill: new Rate(0, 0),
            products: [],
            regulars: new Rate(0, 0),
            absences: new Rate(0, 0),
            cancellations: new Rate($cancelled, $cancelled),
            sources: [],
        );

        self::assertTrue($empty(0, 0)->isEmpty());
        self::assertFalse($empty(1, 0)->isEmpty());
        self::assertFalse($empty(0, 1)->isEmpty());
    }

    public function testThePageShowsTheFiguresOfTheCurrentShop(): void
    {
        $client = self::createClient();
        self::mockTime(new \DateTimeImmutable(self::TODAY));
        $producer = UserFactory::createOne();
        $shop = $producer->getShop() ?? throw new \LogicException();
        $sale = $this->sale($shop, '2026-11-09');
        $this->order($sale, OrderStatus::Delivered, 1250);
        $this->order($sale, OrderStatus::NotDelivered, 800);
        // Another producer's busy week shows nowhere.
        $other = $this->sale($this->shop(), '2026-11-09');
        for ($i = 0; $i < 3; ++$i) {
            $this->order($other, OrderStatus::Delivered, 99900);
        }
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-stats-empty]');
        self::assertSelectorTextContains('[data-stats="weekly"] h2', 'Commandes retirées par semaine');
        self::assertSame('0,1', trim($crawler->filter('[data-stats-average]')->text()), 'One order in twelve weeks.');
        self::assertStringContainsString('Semaine du 09/11 : 1', $crawler->filter('#chart-weekly-desc')->text());
        self::assertSelectorCount(1, '[data-chart="chart-weekly"] rect');
        self::assertSelectorTextContains('[data-chart="chart-monthly"] table', '12,50');
        self::assertSelectorTextNotContains('[data-chart="chart-monthly"] table', '999');
        self::assertSelectorTextContains('[data-stat="absences"]', "50\u{a0}%");
        self::assertSelectorTextContains('[data-stat="absences"]', '1 commande non retirée sur 2');
        self::assertSelectorTextContains('[data-stats="sources"]', 'Adresse tapée ou lien sans provenance');
        self::assertSelectorExists('a[href="/pro/statistiques"][aria-current="page"]');
        // Drawn on the server, no inline style or script.
        self::assertStringNotContainsString('style=', $crawler->filter('main')->html());
        self::assertStringNotContainsString('<script', $crawler->filter('main')->html());
    }

    public function testANewShopSeesAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/statistiques');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-stats-empty]');
        self::assertSelectorTextContains('[data-stats-fill]', '—');
    }

    public function testTheStatisticsNeedAProducer(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pro/statistiques');

        self::assertResponseRedirects('/pro/connexion');
    }

    private function statistics(): ShopStatistics
    {
        return self::getContainer()->get(ShopStatistics::class);
    }

    private function shop(): Shop
    {
        return UserFactory::createOne()->getShop() ?? throw new \LogicException('No shop.');
    }

    private function sale(Shop $shop, string $deliveryDate, SaleStatus $status = SaleStatus::Done): Sale
    {
        $date = new \DateTimeImmutable($deliveryDate);

        return SaleFactory::createOne([
            'shop' => $shop,
            'status' => $status,
            'deliveryDate' => $date,
            'opensAt' => $date->modify('-7 days 08:00'),
            'deadlineAt' => $date->modify('-3 days 20:00'),
        ]);
    }

    private function order(Sale $sale, OrderStatus $status, int $priceCents, ?Customer $customer = null, ?OrderSource $source = null, bool $byProducer = false, ?int $weighedThousandths = null): Order
    {
        $order = OrderFactory::createOne(['sale' => $sale, 'status' => $status, 'source' => $source, ...($customer instanceof Customer ? ['customer' => $customer] : [])]);
        $line = OrderLineFactory::createOne([
            'order' => $order,
            'saleOffer' => SaleOfferFactory::new(['sale' => $sale, 'priceCents' => $priceCents, 'product' => ProductFactory::new(['shop' => $sale->getShop(), 'priceCents' => $priceCents])->byWeight(250, 500)]),
            'quantity' => Quantity::UNIT,
        ]);
        if (null !== $weighedThousandths) {
            $line->setActualQuantity($weighedThousandths);
            $line->getOrder()->recomputeTotal();
            self::getContainer()->get(EntityManagerInterface::class)->flush();
        }
        OrderEventFactory::createOne(['order' => $order, 'type' => OrderEventType::Placed, 'actor' => $byProducer ? Actor::Producer : Actor::Customer]);

        return $order;
    }
}
