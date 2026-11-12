<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Delivery\Entity\DeliveryPoint;
use App\Shared\Map\MapTiles;

/**
 * Data of the small map of the pick-up points on a shop page, rendered in the page for the
 * Stimulus controller `points-map` (no extra request). Like the directory map, it only completes
 * the list of the points: no map without a located fixed point or without a tile server.
 */
final readonly class PointsMap
{
    public function __construct(
        private MapTiles $tiles,
    ) {
    }

    /**
     * @param iterable<DeliveryPoint> $points
     *
     * @return array{tiles: array{url: string, attribution: string, attributionUrl: ?string}, points: list<array{id: ?int, name: string, address: string, lat: float, lng: float}>}|null
     */
    public function build(iterable $points): ?array
    {
        $tiles = $this->tiles->config();
        $located = [];

        foreach ($points as $point) {
            $latitude = $point->getLatitude();
            $longitude = $point->getLongitude();
            if (!$point->isHome() && null !== $latitude && null !== $longitude) {
                $located[] = [
                    'id' => $point->getId(),
                    'name' => $point->getName(),
                    'address' => trim((string) preg_replace('/\s*[\r\n]+\s*/', ', ', (string) $point->getAddress())),
                    'lat' => $latitude,
                    'lng' => $longitude,
                ];
            }
        }

        return null === $tiles || [] === $located ? null : ['tiles' => $tiles, 'points' => $located];
    }
}
