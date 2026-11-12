<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

/**
 * Everything "Statistiques" shows, for one shop on one day.
 */
final readonly class ShopStats
{
    /**
     * @param list<Bar>         $weeklyPickups  orders picked up per week of delivery, oldest first
     * @param list<Bar>         $monthlyRevenue amount picked up per month of delivery (cents), oldest first
     * @param list<ProductFill> $products       fill rate of the limited offers, per product
     * @param list<SourceShare> $sources        where the orders came from, biggest first
     */
    public function __construct(
        public \DateTimeImmutable $since,
        public array $weeklyPickups,
        public array $monthlyRevenue,
        /** Median of the orders per finished sale, in tenths (65 = 6,5); null without a finished sale. */
        public ?int $medianOrdersTenths,
        public int $finishedSales,
        public Rate $fill,
        public array $products,
        /** Customers with at least two orders in the last 60 days, out of the customers of that period. */
        public Rate $regulars,
        /** Orders not picked up, out of the orders picked up or not. */
        public Rate $absences,
        /** Orders cancelled, out of all the orders. */
        public Rate $cancellations,
        public array $sources,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->cancellations->whole && [] === array_filter($this->weeklyPickups, static fn (Bar $bar): bool => $bar->value > 0);
    }
}
