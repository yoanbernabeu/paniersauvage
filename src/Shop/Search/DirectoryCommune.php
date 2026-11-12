<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Delivery\Entity\DeliveryPoint;

/**
 * A town of the producer directory: at least one shop of the directory has a pick-up point there.
 * Its page (`/trouver-un-producteur/{slug}`) lists the producers around the centre of those points.
 */
final readonly class DirectoryCommune
{
    public function __construct(
        /** "valence-26" (DeliveryPoint::communeSlugOf()). */
        public string $slug,
        public string $city,
        public string $postcode,
        public float $latitude,
        public float $longitude,
        /** Shops of the directory with a pick-up point in the town. */
        public int $shopCount,
    ) {
    }

    public function department(): string
    {
        return DeliveryPoint::departmentOf($this->postcode);
    }

    /**
     * "Valence (26)".
     */
    public function label(): string
    {
        return \sprintf('%s (%s)', $this->city, $this->department());
    }
}
