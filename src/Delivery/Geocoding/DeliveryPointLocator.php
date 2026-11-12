<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

use App\Delivery\Entity\DeliveryPoint;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks up the coordinates of a pick-up point from its address. Never throws: a point whose
 * address cannot be located, or that is saved while the API is down, simply has no coordinates
 * (it then stays out of the producer search until `app:delivery-points:geocode` finds it).
 */
final readonly class DeliveryPointLocator
{
    public function __construct(
        private BanGeocoder $geocoder,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function locate(DeliveryPoint $point): GeocodingOutcome
    {
        $query = $point->geocodingQuery();

        if (null === $query) {
            $point->setCoordinates(null, null);

            return GeocodingOutcome::NotGeocodable;
        }

        try {
            $place = $this->geocoder->geocode($query);
        } catch (GeocoderUnavailableException) {
            $point->setCoordinates(null, null);

            return GeocodingOutcome::Unavailable;
        }

        if (!$place instanceof GeocodedPlace) {
            $this->logger->info('Delivery point {id}: address not found by the geocoder.', ['id' => $point->getId()]);
            $point->setCoordinates(null, null);

            return GeocodingOutcome::NotFound;
        }

        $point->setCoordinates($place->latitude, $place->longitude, $this->clock->now());
        if (null !== $place->city && null !== $place->postcode) {
            // The geocoder's spelling of the town wins over the one typed in the address.
            $point->setTown($place->city, $place->postcode, $place->inseeCode);
        }

        return GeocodingOutcome::Located;
    }
}
