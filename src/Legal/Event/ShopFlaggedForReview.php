<?php

declare(strict_types=1);

namespace App\Legal\Event;

use App\Legal\Entity\ShopVerification;

/**
 * The SIRET of a shop belongs to an active establishment whose activity is neither farming nor
 * food: the operator is told (LEGAL_CONTACT_EMAIL) and decides with app:shops:review.
 */
final readonly class ShopFlaggedForReview
{
    public function __construct(
        public ShopVerification $verification,
    ) {
    }
}
