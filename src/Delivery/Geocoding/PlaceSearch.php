<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Turns what a visitor typed (a town, a postcode) into a place, with a cache: the same few towns
 * are searched again and again, and the API Adresse is a shared public service. Places found are
 * kept a month, unknown queries a day; an unavailable API is never cached.
 */
final readonly class PlaceSearch
{
    private const int FOUND_TTL = 30 * 86400;
    private const int NOT_FOUND_TTL = 86400;

    public function __construct(
        private BanGeocoder $geocoder,
        private CacheInterface $geocodingCache,
    ) {
    }

    /**
     * @throws GeocoderUnavailableException
     */
    public function find(string $query): ?GeocodedPlace
    {
        $query = BanGeocoder::normalize($query);
        if ('' === $query) {
            return null;
        }

        $cached = $this->geocodingCache->get('place_'.hash('xxh128', mb_strtolower($query)), function (ItemInterface $item) use ($query): array|false {
            $place = $this->geocoder->geocode($query);

            if (!$place instanceof GeocodedPlace) {
                $item->expiresAfter(self::NOT_FOUND_TTL);

                return false;
            }

            $item->expiresAfter(self::FOUND_TTL);

            return [$place->latitude, $place->longitude, $place->label, $place->score, $place->city, $place->postcode];
        });

        if (!\is_array($cached)) {
            return null;
        }

        [$latitude, $longitude, $label, $score, $city, $postcode] = $cached;

        return new GeocodedPlace($latitude, $longitude, $label, $score, $city, $postcode);
    }
}
