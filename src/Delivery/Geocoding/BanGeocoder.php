<?php

declare(strict_types=1);

namespace App\Delivery\Geocoding;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocoding with the API Adresse of the French State (Base Adresse Nationale), free and keyless.
 *
 * Only public text is ever sent: the address of a pick-up point, or the place a visitor typed. The
 * `ban.client` scoped client (config/packages/http_client.yaml) carries the base URI, a generic
 * User-Agent and short timeouts: a slow answer must never hold a page for long.
 */
final readonly class BanGeocoder
{
    /** Below this relevance score (0 to 1), the best match is too uncertain to be used. */
    public const float MIN_SCORE = 0.5;

    private const int MIN_QUERY_LENGTH = 3;
    private const int MAX_QUERY_LENGTH = 200;

    public function __construct(
        private HttpClientInterface $banClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the best match, or null when nothing relevant matches the query.
     *
     * @throws GeocoderUnavailableException when the API cannot be reached or answers with an error
     */
    public function geocode(string $query): ?GeocodedPlace
    {
        $query = self::normalize($query);

        // The API rejects shorter queries, and those that do not start with a letter or a digit.
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH || 1 !== preg_match('/^[\p{L}\p{N}]/u', $query)) {
            return null;
        }

        try {
            $response = $this->banClient->request('GET', '/search/', ['query' => ['q' => $query, 'limit' => 1]]);
            $status = $response->getStatusCode();

            if (400 === $status) {
                return null;
            }

            if (200 !== $status) {
                $this->logger->warning('Geocoding API answered with HTTP {status}.', ['status' => $status]);

                throw new GeocoderUnavailableException(\sprintf('The geocoding API answered with HTTP %d.', $status));
            }

            $data = $response->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Geocoding API unreachable: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            throw new GeocoderUnavailableException('The geocoding API is unreachable.', 0, $exception);
        }

        return $this->bestMatch($data);
    }

    /**
     * One line, single spaces, at most 200 characters.
     */
    public static function normalize(string $query): string
    {
        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * @param array<mixed> $data a GeoJSON FeatureCollection
     */
    private function bestMatch(array $data): ?GeocodedPlace
    {
        $feature = \is_array($data['features'] ?? null) ? ($data['features'][0] ?? null) : null;
        if (!\is_array($feature) || !\is_array($feature['geometry'] ?? null) || !\is_array($feature['properties'] ?? null)) {
            return null;
        }

        $coordinates = $feature['geometry']['coordinates'] ?? null;
        $properties = $feature['properties'];
        $score = $properties['score'] ?? null;

        // GeoJSON order: [longitude, latitude].
        if (!\is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null) || !is_numeric($score)) {
            return null;
        }

        $longitude = (float) $coordinates[0];
        $latitude = (float) $coordinates[1];

        if ((float) $score < self::MIN_SCORE || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return new GeocodedPlace(
            $latitude,
            $longitude,
            \is_string($properties['label'] ?? null) ? $properties['label'] : '',
            (float) $score,
            \is_string($properties['city'] ?? null) ? $properties['city'] : null,
            \is_string($properties['postcode'] ?? null) ? $properties['postcode'] : null,
            \is_string($properties['citycode'] ?? null) ? $properties['citycode'] : null,
        );
    }
}
