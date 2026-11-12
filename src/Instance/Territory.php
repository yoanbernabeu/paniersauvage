<?php

declare(strict_types=1);

namespace App\Instance;

use App\Delivery\Entity\DeliveryPoint;
use App\Shop\Search\ProducerSearch;

/**
 * Territory of an instance (INSTANCE_TERRITORY): departments ("26", "2A", "974") and/or towns by
 * INSEE code ("26362"). Empty: the whole of France, as Panier Sauvage itself.
 *
 * A restricted instance shows in its directory, town pages, cross discovery and sitemap only the
 * pick-up points (and home delivery towns) inside the territory. A point is inside when its INSEE
 * code (given by the geocoder) is one of the towns or belongs to one of the departments; a point
 * without INSEE code (not geocoded yet) is matched by the department of its postcode only.
 *
 * `contains()` is the rule, `pointSql()` its SQL translation: TerritoryTest compares them.
 */
final readonly class Territory
{
    private const string DEPARTMENT_PATTERN = '/^(\d{2}|2[AB]|9[78]\d)$/';
    private const string COMMUNE_PATTERN = '/^(\d{5}|2[AB]\d{3})$/';

    /**
     * @param list<string> $departments
     * @param list<string> $communes    INSEE codes
     */
    private function __construct(
        public array $departments,
        public array $communes,
        /** "Valence Romans Agglo": how the pages name the territory; empty without territory. */
        public string $name,
        public ?float $centerLatitude,
        public ?float $centerLongitude,
        public int $radiusKm,
    ) {
    }

    public static function everywhere(): self
    {
        return new self([], [], '', null, null, ProducerSearch::DEFAULT_RADIUS_KM);
    }

    /**
     * @param string $codes  "26, 07" or "26362 26281" (commas, semicolons or spaces)
     * @param string $center "44.9334,4.8924" (latitude, longitude) or empty
     * @param string $radius 10, 30 or 50 (km around the centre), empty for 30
     *
     * @throws InvalidInstanceSettingException
     */
    public static function fromSettings(string $codes, string $name = '', string $center = '', string $radius = ''): self
    {
        $departments = $communes = [];
        foreach (preg_split('/[\s,;]+/', mb_strtoupper(trim($codes)), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            if (1 === preg_match(self::DEPARTMENT_PATTERN, $code)) {
                $departments[] = $code;
            } elseif (1 === preg_match(self::COMMUNE_PATTERN, $code)) {
                $communes[] = $code;
            } else {
                throw InvalidInstanceSettingException::for('INSTANCE_TERRITORY', 'a list of department codes (26, 2A, 974) or INSEE town codes (26362)', $code);
            }
        }

        [$latitude, $longitude] = self::parseCenter($center);

        $radius = trim($radius);
        $radiusKm = match (true) {
            '' === $radius => ProducerSearch::DEFAULT_RADIUS_KM,
            ctype_digit($radius) => (int) $radius,
            default => null,
        };
        if (!\in_array($radiusKm, ProducerSearch::RADII_KM, true)) {
            throw InvalidInstanceSettingException::for('INSTANCE_TERRITORY_RADIUS_KM', 'one of '.implode(', ', ProducerSearch::RADII_KM), $radius);
        }

        return new self(array_values(array_unique($departments)), array_values(array_unique($communes)), trim($name), $latitude, $longitude, $radiusKm);
    }

    /**
     * Does the territory restrict anything?
     */
    public function isRestricted(): bool
    {
        return [] !== $this->departments || [] !== $this->communes;
    }

    /**
     * Is there a default centre for the map of the directory?
     */
    public function hasCenter(): bool
    {
        return null !== $this->center();
    }

    /**
     * @return array{0: float, 1: float}|null latitude and longitude of the default centre of the map
     */
    public function center(): ?array
    {
        return null === $this->centerLatitude || null === $this->centerLongitude ? null : [$this->centerLatitude, $this->centerLongitude];
    }

    public function containsPoint(DeliveryPoint $point): bool
    {
        return $this->contains($point->getInseeCode(), $point->getPostcode());
    }

    /**
     * Is a town, known by its INSEE code or else by its postcode, inside the territory?
     */
    public function contains(?string $inseeCode, ?string $postcode): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }

        if (null !== $inseeCode) {
            return \in_array($inseeCode, $this->communes, true) || \in_array(self::departmentOf($inseeCode), $this->departments, true);
        }

        return null !== $postcode && \in_array(self::departmentOf($postcode), $this->postcodeDepartments(), true);
    }

    /**
     * SQL condition on the `delivery_point` row aliased $alias. The codes are inlined: each one
     * matched DEPARTMENT_PATTERN or COMMUNE_PATTERN (digits, 2A or 2B only).
     */
    public function pointSql(string $alias): string
    {
        if (!$this->isRestricted()) {
            return 'TRUE';
        }

        $department = static fn (string $column): string => \sprintf("(CASE WHEN %1\$s ~ '^9[78]' THEN LEFT(%1\$s, 3) ELSE LEFT(%1\$s, 2) END)", $column);
        $insee = $alias.'.insee_code';
        $conditions = [];

        if ([] !== $this->communes) {
            $conditions[] = \sprintf('%s IN (%s)', $insee, $this->quoted($this->communes));
        }
        if ([] !== $this->departments) {
            $conditions[] = \sprintf('%s IN (%s)', $department($insee), $this->quoted($this->departments));
            $conditions[] = \sprintf('(%s IS NULL AND %s IN (%s))', $insee, $department($alias.'.postcode'), $this->quoted($this->postcodeDepartments()));
        }

        return '('.implode(' OR ', $conditions).')';
    }

    /**
     * Department of an INSEE code or a postcode: three characters overseas (97x, 98x), two otherwise
     * ("2A" for an INSEE code of Southern Corsica, "20" for its postcodes).
     */
    public static function departmentOf(string $code): string
    {
        return substr($code, 0, str_starts_with($code, '97') || str_starts_with($code, '98') ? 3 : 2);
    }

    /**
     * @param list<string> $codes
     */
    private function quoted(array $codes): string
    {
        return "'".implode("', '", $codes)."'";
    }

    /**
     * Departments as a postcode spells them: Corsican postcodes all start with 20.
     *
     * @return list<string>
     */
    private function postcodeDepartments(): array
    {
        return array_map(static fn (string $department): string => \in_array($department, ['2A', '2B'], true) ? '20' : $department, $this->departments);
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private static function parseCenter(string $center): array
    {
        $center = trim($center);
        if ('' === $center) {
            return [null, null];
        }

        if (1 !== preg_match('/^(?<lat>-?\d{1,2}(?:\.\d+)?)\s*,\s*(?<lng>-?\d{1,3}(?:\.\d+)?)$/', $center, $matches) || abs((float) $matches['lat']) > 90 || abs((float) $matches['lng']) > 180) {
            throw InvalidInstanceSettingException::for('INSTANCE_TERRITORY_CENTER', 'latitude and longitude in decimal degrees, "44.9334,4.8924"', $center);
        }

        return [(float) $matches['lat'], (float) $matches['lng']];
    }
}
