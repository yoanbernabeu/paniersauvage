<?php

declare(strict_types=1);

namespace App\Legal\EventListener;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Legal\Event\ShopFlaggedForReview;
use App\Legal\Repository\ShopVerificationRepository;
use App\Legal\Verification\ShopVerifier;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Checks the SIRET of a shop whenever it is saved with a new one, whatever saves it (settings,
 * fixtures, commands).
 *
 * `preFlush` runs before Doctrine computes the change sets and before the transaction starts: the
 * verification created or updated here is saved by the same flush, and no transaction waits for
 * the register. A shop without SIRET loses its verification. A shop to review is announced after
 * the flush.
 */
#[AsDoctrineListener(event: Events::preFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ShopVerificationListener
{
    /** @var list<ShopVerification> flagged during the flush in progress */
    private array $flagged = [];

    public function __construct(
        private readonly ShopVerifier $verifier,
        private readonly ShopVerificationRepository $verifications,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($this->shopsToCheck($unitOfWork) as $shop) {
            $siret = $shop->getSiret();
            $verification = $this->verificationOf($shop, $unitOfWork);

            if (null === $siret) {
                if ($verification instanceof ShopVerification) {
                    $entityManager->remove($verification);
                }

                continue;
            }

            if ($verification instanceof ShopVerification && $verification->getSiret() === $siret && VerificationStatus::Pending !== $verification->getStatus()) {
                continue;
            }

            if (!$verification instanceof ShopVerification) {
                $verification = new ShopVerification($shop, $siret);
                $entityManager->persist($verification);
            } elseif ($verification->getSiret() !== $siret) {
                $verification->restartFor($siret);
            }

            if (VerificationStatus::ToReview === $this->verifier->check($verification)) {
                $this->flagged[] = $verification;
            }
        }
    }

    public function postFlush(): void
    {
        $flagged = $this->flagged;
        $this->flagged = [];
        foreach ($flagged as $verification) {
            $this->eventDispatcher->dispatch(new ShopFlaggedForReview($verification));
        }
    }

    /**
     * New shops with a SIRET, and loaded shops whose SIRET changed since they were loaded.
     *
     * @return list<Shop>
     */
    private function shopsToCheck(UnitOfWork $unitOfWork): array
    {
        $shops = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Shop && null !== $entity->getSiret()) {
                $shops[] = $entity;
            }
        }

        foreach ($unitOfWork->getIdentityMap()[Shop::class] ?? [] as $entity) {
            if ($entity instanceof Shop && !$unitOfWork->isScheduledForInsert($entity) && ($unitOfWork->getOriginalEntityData($entity)['siret'] ?? null) !== $entity->getSiret()) {
                $shops[] = $entity;
            }
        }

        return $shops;
    }

    private function verificationOf(Shop $shop, UnitOfWork $unitOfWork): ?ShopVerification
    {
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...($unitOfWork->getIdentityMap()[ShopVerification::class] ?? [])] as $entity) {
            if ($entity instanceof ShopVerification && $entity->getShop() === $shop && !$unitOfWork->isScheduledForDelete($entity)) {
                return $entity;
            }
        }

        return $this->verifications->findOneByShop($shop);
    }
}
