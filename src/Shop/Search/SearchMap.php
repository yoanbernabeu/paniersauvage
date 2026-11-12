<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Ordering\Enum\OrderSource;
use App\Sale\Entity\Sale;
use App\Shared\Map\MapTiles;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Twig\ImageExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Data of the map next to the results of the producer search, rendered in the page for the
 * Stimulus controller `producer-map` (no extra request): the searched point, the radius, and the
 * nearest pick-up point of every shop with what its card in the list says.
 *
 * The map only completes the list: no map without results or without a tile server (MapTiles).
 * Shops found through a home delivery zone have no point to show: they stay in the list only.
 */
final readonly class SearchMap
{
    public function __construct(
        private MapTiles $tiles,
        private FrenchFormatter $formatter,
        private UrlGeneratorInterface $urlGenerator,
        private ImageExtension $images,
    ) {
    }

    /**
     * @param list<NearbyShop> $results
     *
     * @return array{
     *     tiles: array{url: string, attribution: string, attributionUrl: ?string},
     *     center: array{lat: float, lng: float, label: string},
     *     radiusKm: int,
     *     results: list<array{slug: string, name: string, lat: float, lng: float, distance: string, point: string, nextSale: ?string, url: string, logo: ?string}>,
     * }|null
     */
    public function build(float $latitude, float $longitude, ?string $placeLabel, int $radiusKm, array $results): ?array
    {
        $tiles = $this->tiles->config();
        $located = array_values(array_filter($results, static fn (NearbyShop $result): bool => !$result->homeDelivery));
        if (null === $tiles || [] === $located) {
            return null;
        }

        return [
            'tiles' => $tiles,
            'center' => [
                'lat' => $latitude,
                'lng' => $longitude,
                'label' => null !== $placeLabel ? 'Ta recherche : '.$placeLabel : 'Toi',
            ],
            'radiusKm' => $radiusKm,
            'results' => array_map($this->result(...), $located),
        ];
    }

    /**
     * @return array{slug: string, name: string, lat: float, lng: float, distance: string, point: string, nextSale: ?string, url: string, logo: ?string}
     */
    private function result(NearbyShop $result): array
    {
        $shop = $result->shop;
        $slug = $shop->getSlug();

        return [
            'slug' => $slug,
            'name' => $shop->getName(),
            'lat' => $result->pointLatitude,
            'lng' => $result->pointLongitude,
            'distance' => $result->distanceLabel(),
            'point' => $result->pointLabel(),
            'nextSale' => $this->nextSale($result),
            'url' => $this->urlGenerator->generate('shop_show', ['slug' => $slug, 'via' => OrderSource::VIA_DIRECTORY]),
            'logo' => $this->images->imageUrl($shop->getLogoPath()),
        ];
    }

    private function nextSale(NearbyShop $result): ?string
    {
        $sale = $result->nextSale;

        return match (true) {
            !$sale instanceof Sale => null,
            $sale->isOpen() => 'Commandes ouvertes jusqu\'au '.$this->formatter->dateTimeLong($sale->getDeadlineAt()),
            default => 'Prochaine vente le '.$this->formatter->dateLong($sale->getOpensAt()),
        };
    }
}
