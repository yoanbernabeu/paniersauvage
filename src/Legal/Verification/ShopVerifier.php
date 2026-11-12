<?php

declare(strict_types=1);

namespace App\Legal\Verification;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use Psr\Clock\ClockInterface;

/**
 * Checks the SIRET of a verification against the company register and records the outcome:
 * verified (active, farming or food activity), to review (active, other activity), not found or
 * closed. When the register cannot be reached, the verification stays pending: nothing is blocked,
 * app:shops:verify (and its periodic task) try again later. Flushing is up to the caller.
 */
final readonly class ShopVerifier
{
    public function __construct(
        private CompanyRegistry $registry,
        private ClockInterface $clock,
    ) {
    }

    public function check(ShopVerification $verification): VerificationStatus
    {
        $siret = $verification->getSiret();
        $now = $this->clock->now();

        if (!Siret::isValid($siret)) {
            $verification->record(VerificationStatus::NotFound, null, null, $now);

            return VerificationStatus::NotFound;
        }

        try {
            $record = $this->registry->find($siret);
        } catch (CompanyRegistryUnavailableException) {
            return $verification->getStatus();
        }

        $status = match (true) {
            !$record instanceof CompanyRecord => VerificationStatus::NotFound,
            !$record->active => VerificationStatus::Closed,
            NafClassifier::isFoodOrFarming($record->nafCode) => VerificationStatus::Verified,
            default => VerificationStatus::ToReview,
        };

        // A new check of an active establishment never overrides a decision already made for it
        // (by the operator, or by an earlier check): only a closure or a disappearance does.
        $previous = $verification->getStatus();
        if (VerificationStatus::Rejected === $previous && \in_array($status, [VerificationStatus::Verified, VerificationStatus::ToReview], true)) {
            $status = VerificationStatus::Rejected;
        } elseif (VerificationStatus::Verified === $previous && VerificationStatus::ToReview === $status) {
            $status = VerificationStatus::Verified;
        }

        $verification->record($status, $record?->companyName, $record?->nafCode, $now);

        return $status;
    }
}
