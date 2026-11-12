<?php

declare(strict_types=1);

namespace App\Billing\Enum;

/**
 * Eligibility criteria of the solidarity plan (docs/SPEC.md). One is
 * enough, on a declaration on honour: no document is ever asked for, nothing is checked beforehand.
 */
enum SolidarityCriterion: string
{
    case RecentInstallation = 'recent_installation';
    case SmallVolume = 'small_volume';
    case FirstSeason = 'first_season';
    case FoodAid = 'food_aid';

    public function label(): string
    {
        return match ($this) {
            self::RecentInstallation => 'Je me suis installé·e il y a moins de 3 ans',
            self::SmallVolume => 'Je fais moins de 300 € de précommandes retirées par mois',
            self::FirstSeason => 'C\'est ma première saison',
            self::FoodAid => 'Ma structure fait de l\'aide alimentaire',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RecentInstallation => 'Activité agricole ou artisanale démarrée il y a moins de 3 ans (date d\'immatriculation de ton SIRET).',
            self::SmallVolume => 'En moyenne sur les 3 derniers mois.',
            self::FirstSeason => 'Tu es producteur saisonnier (maraîchage, miel…) et tu n\'as pas encore de recul sur tes ventes.',
            self::FoodAid => 'Épicerie solidaire ou structure d\'aide alimentaire qui précommande des produits frais.',
        };
    }
}
