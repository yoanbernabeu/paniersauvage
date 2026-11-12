<?php

declare(strict_types=1);

namespace App\Legal\Command;

use App\Legal\Enum\VerificationStatus;
use App\Legal\Verification\PendingVerifications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks again the SIRETs that could not be checked (register unreachable), and those never
 * checked. Safe to run at any time; RetryShopVerificationsTask runs it every 6 hours.
 */
#[AsCommand(name: 'app:shops:verify', description: 'Checks the pending SIRETs of the shops against the public company register.')]
final readonly class VerifyShopsCommand
{
    public function __construct(
        private PendingVerifications $pendingVerifications,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Check every shop again, even the verified ones (to spot closed establishments).')]
        bool $all = false,
    ): int {
        $counts = $this->pendingVerifications->process($all);

        $io->success(\sprintf(
            '%d verified, %d to review, %d not found, %d closed, %d rejected, %d still pending (register unavailable).',
            $counts[VerificationStatus::Verified->value],
            $counts[VerificationStatus::ToReview->value],
            $counts[VerificationStatus::NotFound->value],
            $counts[VerificationStatus::Closed->value],
            $counts[VerificationStatus::Rejected->value],
            $counts[VerificationStatus::Pending->value],
        ));

        return $counts[VerificationStatus::Pending->value] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
