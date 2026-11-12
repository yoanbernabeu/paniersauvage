<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

/**
 * The geocoding service did not answer in time or answered with an error: try again later.
 */
final class GeocoderUnavailableException extends \RuntimeException
{
}
