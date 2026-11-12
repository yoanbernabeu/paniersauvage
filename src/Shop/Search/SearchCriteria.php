<?php

declare(strict_types=1);

namespace App\Shop\Search;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * What the visitor asked the producer directory, read from the query string of its page:
 * `?ou=Toulouse&rayon=30` (a place they typed) or `?lat=43.604&lng=1.444&rayon=30` (the position
 * of their browser, rounded to about a hundred metres). Nothing of it is ever stored.
 */
final readonly class SearchCriteria
{
    private const int MAX_PLACE_LENGTH = 100;

    /** Three decimals: about 100 m, enough to rank producers, vague enough to stay private. */
    private const int POSITION_PRECISION = 3;

    public function __construct(
        public ?string $place,
        public ?float $latitude,
        public ?float $longitude,
        public int $radiusKm,
        /** Coordinates were sent but cannot be used (not numbers, out of range). */
        public bool $invalidPosition = false,
        public SearchFilters $filters = new SearchFilters(),
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        $place = trim((string) preg_replace('/\s+/u', ' ', self::string($query, 'ou')));
        $place = '' === $place ? null : mb_substr($place, 0, self::MAX_PLACE_LENGTH);

        $radius = (int) self::string($query, 'rayon');
        if (!\in_array($radius, ProducerSearch::RADII_KM, true)) {
            $radius = ProducerSearch::DEFAULT_RADIUS_KM;
        }

        $rawLatitude = self::string($query, 'lat');
        $rawLongitude = self::string($query, 'lng');
        $latitude = $longitude = null;
        $invalidPosition = false;

        if (null === $place && ('' !== $rawLatitude || '' !== $rawLongitude)) {
            if (is_numeric($rawLatitude) && is_numeric($rawLongitude) && abs((float) $rawLatitude) <= 90 && abs((float) $rawLongitude) <= 180) {
                $latitude = round((float) $rawLatitude, self::POSITION_PRECISION);
                $longitude = round((float) $rawLongitude, self::POSITION_PRECISION);
            } else {
                $invalidPosition = true;
            }
        }

        return new self($place, $latitude, $longitude, $radius, $invalidPosition, SearchFilters::fromQuery($query));
    }

    public function isEmpty(): bool
    {
        return null === $this->place && !$this->hasPosition() && !$this->invalidPosition;
    }

    public function hasPosition(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    /**
     * Query string of the same search with another radius (links "élargir à 50 km"), filters kept.
     *
     * @return array<string, string|int|float>
     */
    public function withRadius(int $radiusKm): array
    {
        $parameters = null !== $this->place ? ['ou' => $this->place] : ['lat' => (float) $this->latitude, 'lng' => (float) $this->longitude];

        return [...$parameters, 'rayon' => $radiusKm, ...$this->filters->toQuery()];
    }

    /**
     * Query string of the same search without its filters (link of the empty state).
     *
     * @return array<string, string|int|float>
     */
    public function withoutFilters(): array
    {
        return array_diff_key($this->withRadius($this->radiusKm), $this->filters->toQuery());
    }

    /**
     * @param InputBag<string> $query
     */
    private static function string(InputBag $query, string $key): string
    {
        $value = $query->all()[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
