<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Catalog\Repository\ProductRepository;
use App\Sale\Repository\SaleRepository;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use Psr\Clock\ClockInterface;

/**
 * Finds the producers of the directory around a point: three queries whatever the number of
 * results, one for the shops and their distance, one for their next sales, one for their labels.
 *
 * A shop is listed when it chose to appear in the directory (`Shop::listedInSearch`, off by
 * default) with at least one category, its subscription allows new sales
 * (`ShopSubscription::allowsNewSales()`), and either one of its active fixed pick-up points has
 * coordinates within the radius, or the point is inside one of its home delivery zones.
 * Results are ranked by distance only: nothing else, and nothing paid, changes the order.
 */
final readonly class ProducerSearch
{
    public const array RADII_KM = [10, 30, 50];
    public const int DEFAULT_RADIUS_KM = 30;
    public const int MAX_RESULTS = 50;

    public function __construct(
        private ShopRepository $shops,
        private SaleRepository $sales,
        private ProductRepository $products,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<NearbyShop> the nearest first
     */
    public function around(
        float $latitude,
        float $longitude,
        int $radiusKm = self::DEFAULT_RADIUS_KM,
        SearchFilters $filters = new SearchFilters(),
        ?Shop $excluded = null,
        int $limit = self::MAX_RESULTS,
    ): array {
        $now = $this->clock->now();
        $results = $this->shops->findNearby($latitude, $longitude, $radiusKm, $now, $limit, $filters, $excluded?->getId());

        if ([] === $results) {
            return [];
        }

        $shops = array_map(static fn (NearbyShop $result): Shop => $result->shop, $results);
        $nextSales = $this->sales->findNextByShops($shops, $now);
        $labels = $this->products->findLabelsByShops($shops);

        return array_map(
            static fn (NearbyShop $result): NearbyShop => $result->with($nextSales[(int) $result->shop->getId()] ?? null, $labels[(int) $result->shop->getId()] ?? []),
            $results,
        );
    }
}
