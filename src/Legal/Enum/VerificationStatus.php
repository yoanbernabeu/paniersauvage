<?php

declare(strict_types=1);

namespace App\Legal\Enum;

/**
 * Outcome of the check of a shop's SIRET against the public company register (SPEC §12).
 * Only `verified` puts the shop in the producer directory.
 */
enum VerificationStatus: string
{
    /** Not checked yet, or the register was unreachable: checked again later (app:shops:verify). */
    case Pending = 'pending';
    /** Active establishment with a farming or food activity, or approved by the operator. */
    case Verified = 'verified';
    /** Active establishment, but its activity code is neither farming nor food: the operator decides. */
    case ToReview = 'to_review';
    /** Not a SIRET (checksum), or unknown to the register. */
    case NotFound = 'not_found';
    /** The establishment is closed. */
    case Closed = 'closed';
    /** Refused by the operator (app:shops:review --reject). */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Vérification en attente',
            self::Verified => 'SIRET vérifié',
            self::ToReview => 'En cours de vérification par l\'équipe',
            self::NotFound => 'SIRET introuvable',
            self::Closed => 'Établissement fermé',
            self::Rejected => 'Vérification refusée',
        };
    }

    /**
     * What the producer reads next to the SIRET field.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Pending => 'Le répertoire des entreprises ne répond pas pour l\'instant. On réessaie automatiquement dans quelques heures.',
            self::Verified => 'Ton SIRET correspond à un établissement actif. Ta boutique peut apparaître dans l\'annuaire des producteurs.',
            self::ToReview => 'Ton code d\'activité (NAF) n\'est ni agricole ni alimentaire : l\'équipe vérifie ta boutique à la main avant de l\'afficher dans l\'annuaire.',
            self::NotFound => 'Ce numéro ne correspond à aucun établissement du répertoire des entreprises. Vérifie les 14 chiffres.',
            self::Closed => 'Le répertoire des entreprises indique que cet établissement est fermé. Indique le SIRET de ton établissement actuel.',
            self::Rejected => 'L\'équipe n\'a pas pu confirmer que tu vends ta propre production alimentaire. Écris-nous si c\'est une erreur.',
        };
    }
}
