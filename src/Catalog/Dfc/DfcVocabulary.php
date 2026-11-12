<?php

declare(strict_types=1);

namespace App\Catalog\Dfc;

use App\Catalog\Enum\ProductUnit;

/**
 * Data Food Consortium identifiers used by the export (ontology and taxonomies 1.16, see
 * docs/SPEC.md, "Export Data Food Consortium", for the sources and the choice of version).
 */
final class DfcVocabulary
{
    /** JSON-LD context of the DFC ontology 1.16.0, the version the official connectors emit. */
    public const string CONTEXT = 'https://w3id.org/dfc/ontology/context/context_1.16.0.json';

    public const string EURO = 'dfc-m:Euro';

    /** Country of an address: the EU Publications Office authority table (DFC 1.16, `hasCountry`). */
    public const string FRANCE = 'http://publications.europa.eu/resource/authority/country/FRA';

    /**
     * Free-text product labels → DFC certifications (facets taxonomy). Keys are normalised labels
     * (lower case, no accents, single spaces); the first matching prefix wins.
     */
    private const array CERTIFICATIONS = [
        'agriculture biologique' => 'dfc-f:Organic-AB',
        'ab' => 'dfc-f:Organic-AB',
        'bio coherence' => 'dfc-f:Organic-BioCoherence',
        'bio' => 'dfc-f:Organic-EU',
        'eurofeuille' => 'dfc-f:Organic-EU',
        'demeter' => 'dfc-f:Demeter',
        'nature et progres' => 'dfc-f:NatureEtProgres',
        'nature & progres' => 'dfc-f:NatureEtProgres',
        'label rouge' => 'dfc-f:RedLabel_FR',
        'aop' => 'dfc-f:AOP_EU',
        'aoc' => 'dfc-f:AOC_FR',
        'igp' => 'dfc-f:IGP_EU',
    ];

    public static function unit(ProductUnit $unit): string
    {
        return match ($unit) {
            ProductUnit::Piece => 'dfc-m:Piece',
            ProductUnit::Kg => 'dfc-m:Kilogram',
            ProductUnit::Bunch => 'dfc-m:Bunch',
        };
    }

    /**
     * "Bio", "AB – Agriculture biologique", "Label Rouge" → the DFC certification, or null when the
     * label is not in the taxonomy ("Fait maison", "Sans gluten"…).
     */
    public static function certification(string $label): ?string
    {
        $normalised = self::normalise($label);

        foreach (self::CERTIFICATIONS as $prefix => $certification) {
            if ($normalised === $prefix || str_starts_with($normalised, $prefix.' ')) {
                return $certification;
            }
        }

        return null;
    }

    private static function normalise(string $label): string
    {
        $ascii = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label);
        $words = preg_replace('/[^a-z0-9&]+/', ' ', $ascii);

        return trim((string) $words);
    }
}
