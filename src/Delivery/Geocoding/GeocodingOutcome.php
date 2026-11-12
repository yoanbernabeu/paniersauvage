<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

enum GeocodingOutcome: string
{
    case Located = 'located';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    /** A home delivery, or a point without an address: nothing to look up. */
    case NotGeocodable = 'not_geocodable';
}
