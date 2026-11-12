<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Enum\SaleStatus;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * "Statistiques": a few sober figures of one shop, computed in SQL on its own tables (no tracking,
 * no third-party tool).
 * Dates are stored in Paris time, so weeks and months are local ones.
 */
final readonly class ShopStatistics
{
    /** Weeks of the main chart, the current one included. */
    public const int WEEKS = 12;

    /** Months of the revenue chart, the current one included. */
    public const int MONTHS = 12;

    /** Period of the rates, medians and sources: the last six months of deliveries. */
    public const int PERIOD_DAYS = 182;

    /** A regular customer has at least two orders delivered within this many days. */
    public const int REGULAR_DAYS = 60;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function compute(Shop $shop): ShopStats
    {
        $today = $this->clock->now();
        $since = $today->modify(\sprintf('-%d days', self::PERIOD_DAYS));
        $parameters = [
            'shop' => $shop->getId(),
            'today' => $today->format('Y-m-d'),
            'since' => $since->format('Y-m-d'),
            'delivered' => OrderStatus::Delivered->value,
            'absent' => OrderStatus::NotDelivered->value,
            'cancelled' => OrderStatus::Cancelled->value,
            'done' => SaleStatus::Done->value,
            'closed' => SaleStatus::Closed->value,
        ];

        [$medianTenths, $finishedSales] = $this->median($parameters);
        $products = $this->products($parameters);
        $rates = $this->rates($parameters);

        return new ShopStats(
            since: $since,
            weeklyPickups: $this->series('week', $today->modify(\sprintf('-%d weeks', self::WEEKS - 1)), 'count(o.id)', $parameters),
            monthlyRevenue: $this->series('month', $today->modify('first day of this month')->modify(\sprintf('-%d months', self::MONTHS - 1)), 'coalesce(sum(o.total_cents), 0)', $parameters),
            medianOrdersTenths: $medianTenths,
            finishedSales: $finishedSales,
            fill: new Rate(
                array_sum(array_map(static fn (array $product): int => $product['reserved'], $products)),
                array_sum(array_map(static fn (array $product): int => $product['max'], $products)),
            ),
            products: array_map(static fn (array $product): ProductFill => new ProductFill($product['name'], $product['offers'], new Rate($product['reserved'], $product['max']), $product['sold_out']), $products),
            regulars: $this->regulars($parameters, $today->modify(\sprintf('-%d days', self::REGULAR_DAYS))),
            absences: new Rate($rates['absent'], $rates['picked'] + $rates['absent']),
            cancellations: new Rate($rates['cancelled'], $rates['total']),
            sources: $this->sources($parameters),
        );
    }

    /**
     * Orders picked up per week (or amount picked up per month) of delivery, empty periods included.
     *
     * @param 'week'|'month'       $unit
     * @param array<string, mixed> $parameters
     *
     * @return list<Bar>
     */
    private function series(string $unit, \DateTimeImmutable $first, string $aggregate, array $parameters): array
    {
        /** @var list<array{period: string, value: int}> $rows PostgreSQL integers come back as PHP integers */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT to_char(p.period, \'YYYY-MM-DD\') AS period, %2$s AS value
                 FROM generate_series(date_trunc(\'%1$s\', CAST(:first AS date)), date_trunc(\'%1$s\', CAST(:today AS date)), interval \'1 %1$s\') AS p(period)
                 LEFT JOIN sale s ON s.shop_id = :shop AND s.delivery_date >= p.period AND s.delivery_date < p.period + interval \'1 %1$s\'
                 LEFT JOIN customer_order o ON o.sale_id = s.id AND o.status = :delivered
                 GROUP BY p.period
                 ORDER BY p.period',
                $unit,
                $aggregate,
            ),
            ['first' => $first->format('Y-m-d'), ...$parameters],
        );

        return array_map(static fn (array $row): Bar => new Bar(new \DateTimeImmutable($row['period']), $row['value']), $rows);
    }

    /**
     * Median of the orders (not cancelled) per sale finished in the period.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{?int, int} median in tenths, number of sales
     */
    private function median(array $parameters): array
    {
        /** @var array{median_tenths: ?string, sales: int} $row an aggregate always returns one row; the median is a numeric */
        $row = $this->connection->fetchAssociative(
            'SELECT round((percentile_cont(0.5) WITHIN GROUP (ORDER BY x.n) * 10)::numeric) AS median_tenths, count(*) AS sales
             FROM (
                 SELECT s.id, count(o.id) AS n
                 FROM sale s
                 LEFT JOIN customer_order o ON o.sale_id = s.id AND o.status <> :cancelled
                 WHERE s.shop_id = :shop AND s.status = :done AND s.delivery_date >= :since
                 GROUP BY s.id
             ) x',
            $parameters,
        );

        return [null === $row['median_tenths'] ? null : (int) $row['median_tenths'], $row['sales']];
    }

    /**
     * Limited offers (a maximum quantity) of the sales closed or finished in the period, per product.
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<array{name: string, offers: int, reserved: int, max: int, sold_out: int}>
     */
    private function products(array $parameters): array
    {
        /** @var list<array{name: string, offers: int, reserved: int, max: int, sold_out: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.name, count(*) AS offers, sum(so.reserved_qty) AS reserved, sum(so.max_qty) AS max,
                    count(*) FILTER (WHERE so.reserved_qty >= so.max_qty) AS sold_out
             FROM sale_offer so
             JOIN sale s ON s.id = so.sale_id
             JOIN product p ON p.id = so.product_id
             WHERE s.shop_id = :shop AND s.status IN (:closed, :done) AND s.delivery_date >= :since AND so.max_qty > 0
             GROUP BY p.id, p.name
             ORDER BY count(*) DESC, sum(so.reserved_qty) * 1.0 / sum(so.max_qty) DESC, p.name',
            $parameters,
        );

        return $rows;
    }

    /**
     * Customers with at least two orders (not cancelled) delivered since the date, out of all the
     * customers who ordered for that period.
     *
     * @param array<string, mixed> $parameters
     */
    private function regulars(array $parameters, \DateTimeImmutable $since): Rate
    {
        /** @var array{regulars: int, customers: int} $row an aggregate always returns one row */
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) FILTER (WHERE x.n >= 2) AS regulars, count(*) AS customers
             FROM (
                 SELECT o.customer_id, count(*) AS n
                 FROM customer_order o
                 JOIN sale s ON s.id = o.sale_id
                 WHERE s.shop_id = :shop AND o.status <> :cancelled AND s.delivery_date >= :regular_since
                 GROUP BY o.customer_id
             ) x',
            ['regular_since' => $since->format('Y-m-d'), ...$parameters],
        );

        return new Rate($row['regulars'], $row['customers']);
    }

    /**
     * Orders of the sales finished in the period, by outcome.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{picked: int, absent: int, cancelled: int, total: int}
     */
    private function rates(array $parameters): array
    {
        /** @var array{picked: int, absent: int, cancelled: int, total: int} $row an aggregate always returns one row */
        $row = $this->connection->fetchAssociative(
            'SELECT count(*) FILTER (WHERE o.status = :delivered) AS picked,
                    count(*) FILTER (WHERE o.status = :absent) AS absent,
                    count(*) FILTER (WHERE o.status = :cancelled) AS cancelled,
                    count(*) AS total
             FROM customer_order o
             JOIN sale s ON s.id = o.sale_id
             WHERE s.shop_id = :shop AND s.status = :done AND s.delivery_date >= :since',
            $parameters,
        );

        return $row;
    }

    /**
     * Orders (not cancelled) delivered in the period, by provenance: entered by the producer
     * (`placed` by the producer), else Order::source, else unknown.
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<SourceShare>
     */
    private function sources(array $parameters): array
    {
        /** @var list<array{source: string, orders: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT x.source, count(*) AS orders
             FROM (
                 SELECT CASE
                            WHEN EXISTS (SELECT 1 FROM order_event e WHERE e.order_id = o.id AND e.type = :placed AND e.actor = :producer) THEN :producer_key
                            ELSE coalesce(o.source, :unknown_key)
                        END AS source
                 FROM customer_order o
                 JOIN sale s ON s.id = o.sale_id
                 WHERE s.shop_id = :shop AND o.status <> :cancelled AND s.delivery_date >= :since
             ) x
             GROUP BY x.source
             ORDER BY count(*) DESC, x.source',
            [
                'placed' => OrderEventType::Placed->value,
                'producer' => Actor::Producer->value,
                'producer_key' => SourceShare::PRODUCER,
                'unknown_key' => SourceShare::UNKNOWN,
                ...$parameters,
            ],
        );

        $total = array_sum(array_column($rows, 'orders'));

        return array_map(static fn (array $row): SourceShare => new SourceShare($row['source'], new Rate($row['orders'], $total)), $rows);
    }
}
