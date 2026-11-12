<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Entity\Sale;
use App\Shop\Entity\Shop;

/**
 * One result of the producer directory: a shop, its pick-up point nearest to the visitor (with its
 * coordinates, for the map) or the home delivery zone the visitor is in, its next sale that takes
 * orders or will soon, and the labels of its products.
 */
final readonly class NearbyShop
{
    public function __construct(
        public Shop $shop,
        public float $distanceKm,
        public string $pointName,
        public ?string $pointAddress,
        public float $pointLatitude,
        public float $pointLongitude,
        public ?Sale $nextSale = null,
        /** The visitor is inside a home delivery zone of the shop (distance 0): no point on the map. */
        public bool $homeDelivery = false,
        /** @var list<string> labels of its products, without duplicates ("Bio", "AOP") */
        public array $labels = [],
    ) {
    }

    /**
     * @param list<string> $labels
     */
    public function with(?Sale $nextSale, array $labels): self
    {
        return new self($this->shop, $this->distanceKm, $this->pointName, $this->pointAddress, $this->pointLatitude, $this->pointLongitude, $nextSale, $this->homeDelivery, $labels);
    }

    /**
     * "livre chez toi", "à moins d'1 km", "à 4 km".
     */
    public function distanceLabel(): string
    {
        return match (true) {
            $this->homeDelivery => 'livre chez toi',
            $this->distanceKm < 1 => 'à moins d’1 km',
            default => \sprintf('à %d km', (int) round($this->distanceKm)),
        };
    }

    /**
     * "Marché couvert, Blagnac": the name of the nearest point, and its town when the name lacks it.
     * For a home delivery: "Livraison à domicile autour de Crest".
     */
    public function pointLabel(): string
    {
        if ($this->homeDelivery) {
            return null === $this->pointAddress ? $this->pointName : \sprintf('%s autour de %s', $this->pointName, $this->pointAddress);
        }

        $town = $this->pointTown();

        return null !== $town && !str_contains($this->pointName, $town) ? $this->pointName.', '.$town : $this->pointName;
    }

    /**
     * The town of the nearest point, read from its address ("…\n26400 Saoû" gives "Saoû").
     */
    private function pointTown(): ?string
    {
        return DeliveryPoint::townOf($this->pointAddress)[1];
    }
}
