<?php

declare(strict_types=1);

namespace App\Legal\Command;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Legal\Repository\ShopVerificationRepository;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator's review of the shops whose activity code is neither farming nor food (there is no
 * administration interface). Without option: lists them. `--approve=<slug>` lists the shop in the
 * directory, `--reject=<slug>` keeps it out (with an optional `--note`).
 */
#[AsCommand(name: 'app:shops:review', description: 'Lists the shops to review, or approves / rejects one of them.')]
final readonly class ReviewShopsCommand
{
    public function __construct(
        private ShopVerificationRepository $verifications,
        private ShopRepository $shops,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Slug of the shop to approve (listed in the directory).')]
        ?string $approve = null,
        #[Option(description: 'Slug of the shop to reject (kept out of the directory).')]
        ?string $reject = null,
        #[Option(description: 'Reason of the decision, kept with the verification.')]
        ?string $note = null,
        #[Option(description: 'List the shops with this status instead (pending, verified, to_review, not_found, closed, rejected).')]
        string $status = VerificationStatus::ToReview->value,
    ): int {
        if (null !== $approve && null !== $reject) {
            $io->error('Choose either --approve or --reject.');

            return Command::INVALID;
        }

        if (null !== $approve || null !== $reject) {
            return $this->decide($io, (string) ($approve ?? $reject), null !== $approve, $note);
        }

        $wanted = VerificationStatus::tryFrom($status);
        if (!$wanted instanceof VerificationStatus) {
            $io->error(\sprintf('Unknown status "%s".', $status));

            return Command::INVALID;
        }

        $rows = array_map(static fn (ShopVerification $verification): array => [
            $verification->getShop()->getSlug(),
            $verification->getShop()->getName(),
            $verification->getSiret(),
            $verification->getCompanyName() ?? '-',
            $verification->getNafCode() ?? '-',
            $verification->getCheckedAt()?->format('Y-m-d H:i') ?? '-',
        ], $this->verifications->findByStatus($wanted));

        if ([] === $rows) {
            $io->success(\sprintf('No shop with the status "%s".', $wanted->value));

            return Command::SUCCESS;
        }

        $io->table(['Slug', 'Shop', 'SIRET', 'Company (register)', 'NAF', 'Checked at'], $rows);
        $io->note('Check the company on https://annuaire-entreprises.data.gouv.fr, then run app:shops:review --approve=<slug> or --reject=<slug> [--note="..."].');

        return Command::SUCCESS;
    }

    private function decide(SymfonyStyle $io, string $slug, bool $approved, ?string $note): int
    {
        $shop = $this->shops->findOneBySlug($slug);
        $verification = $shop instanceof Shop ? $this->verifications->findOneByShop($shop) : null;

        if (!$verification instanceof ShopVerification || $verification->getSiret() !== $verification->getShop()->getSiret()) {
            $io->error(\sprintf('No verification for the current SIRET of the shop "%s".', $slug));

            return Command::FAILURE;
        }

        $verification->decide($approved, $note, $this->clock->now());
        $this->entityManager->flush();

        $io->success(\sprintf('%s is now "%s".', $verification->getShop()->getName(), $verification->getStatus()->label()));

        return Command::SUCCESS;
    }
}
