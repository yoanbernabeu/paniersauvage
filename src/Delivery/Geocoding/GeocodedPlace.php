<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

/**
 * A place found by the geocoder: WGS 84 coordinates and how the Base Adresse Nationale names it.
 */
final readonly class GeocodedPlace
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $label,
        public float $score,
        public ?string $city = null,
        public ?string $postcode = null,
        /** INSEE code of the town (`citycode` of the BAN). */
        public ?string $inseeCode = null,
    ) {
    }
}
