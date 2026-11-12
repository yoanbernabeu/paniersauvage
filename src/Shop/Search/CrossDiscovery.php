<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Delivery\Entity\DeliveryPoint;

/**
 * "D'autres producteurs près de ce point de retrait": a few shops of the directory around a
 * pick-up point, on the order confirmation, in its e-mail and on the shop page. A neighbourly
 * hint, not a marketplace: no shared basket, no ranking but the distance, nothing paid; only shops
 * that chose to appear in the directory.
 */
final readonly class CrossDiscovery
{
    public const int RADIUS_KM = 10;
    public const int MAX_SHOPS = 3;

    public function __construct(
        private ProducerSearch $producerSearch,
    ) {
    }

    /**
     * Other shops near the point, the nearest first; none for a point without coordinates (a home
     * delivery: the customer's address is never located), nor when the point's shop turned the
     * block off (`Shop::isCrossDiscoveryEnabled()`; it still shows in the other shops' blocks).
     *
     * @return list<NearbyShop>
     */
    public function around(DeliveryPoint $point): array
    {
        if (!$point->getShop()->isCrossDiscoveryEnabled() || $point->isHome() || !$point->hasCoordinates()) {
            return [];
        }

        return $this->producerSearch->around((float) $point->getLatitude(), (float) $point->getLongitude(), self::RADIUS_KM, excluded: $point->getShop(), limit: self::MAX_SHOPS);
    }

    /**
     * Around the first located fixed point of a list (the pick-up points of a shop page).
     *
     * @param iterable<DeliveryPoint> $points
     *
     * @return list<NearbyShop>
     */
    public function aroundFirstOf(iterable $points): array
    {
        foreach ($points as $point) {
            if (!$point->isHome() && $point->hasCoordinates()) {
                return $this->around($point);
            }
        }

        return [];
    }
}
