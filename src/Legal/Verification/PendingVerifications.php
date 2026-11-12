<?php

declare(strict_types=1);

namespace App\Legal\Verification;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Legal\Event\ShopFlaggedForReview;
use App\Legal\Repository\ShopVerificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Catch-up of the verifications: SIRETs saved while the register was unreachable, shops whose
 * SIRET was never checked, and — with `$all` — every verification again (closures).
 * Used by app:shops:verify and RetryShopVerificationsTask.
 */
final readonly class PendingVerifications
{
    public function __construct(
        private ShopVerificationRepository $verifications,
        private ShopVerifier $verifier,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array<string, int> number of verifications per resulting status value
     */
    public function process(bool $all = false): array
    {
        $counts = array_fill_keys(array_map(static fn (VerificationStatus $status): string => $status->value, VerificationStatus::cases()), 0);
        $flagged = [];

        $toCheck = $all ? $this->verifications->findAll() : $this->verifications->findByStatus(VerificationStatus::Pending);
        foreach ($this->verifications->findUncheckedShops() as $shop) {
            $verification = new ShopVerification($shop, (string) $shop->getSiret());
            $this->entityManager->persist($verification);
            $toCheck[] = $verification;
        }

        foreach ($toCheck as $verification) {
            $siret = $verification->getShop()->getSiret();
            if (null !== $siret && $siret !== $verification->getSiret()) {
                $verification->restartFor($siret);
            }

            $before = $verification->getStatus();
            $after = $this->verifier->check($verification);
            ++$counts[$after->value];

            if (VerificationStatus::ToReview === $after && VerificationStatus::ToReview !== $before) {
                $flagged[] = $verification;
            }
        }

        $this->entityManager->flush();

        foreach ($flagged as $verification) {
            $this->eventDispatcher->dispatch(new ShopFlaggedForReview($verification));
        }

        return $counts;
    }
}
