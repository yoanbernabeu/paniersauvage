<?php

declare(strict_types=1);

namespace App\Shop\Repository;

use App\Shop\Entity\Shop;
use App\Shop\Entity\ShopInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShopInvitation>
 */
final class ShopInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopInvitation::class);
    }

    /**
     * The invitations of the shop still waiting for an answer, the latest first.
     *
     * @return list<ShopInvitation>
     */
    public function findPending(Shop $shop, \DateTimeImmutable $now): array
    {
        /** @var list<ShopInvitation> $invitations */
        $invitations = $this->createQueryBuilder('i')
            ->andWhere('i.shop = :shop')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('shop', $shop)
            ->setParameter('now', $now)
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $invitations;
    }

    /**
     * A pending invitation of this shop (producer isolation starts from the shop).
     */
    public function findPendingInShop(int $id, Shop $shop): ?ShopInvitation
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop, 'acceptedAt' => null]);
    }

    /**
     * Drops the unanswered invitations of the shop to this address (as normalised by
     * ShopInvitation): only the latest link works.
     */
    public function deletePendingFor(Shop $shop, string $email): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->where('i.shop = :shop')
            ->andWhere('i.email = :email')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('shop', $shop)
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
    }

    /**
     * Housekeeping: drops the invitations that expired before the given date without an answer.
     */
    public function deleteExpired(\DateTimeImmutable $before): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->where('i.expiresAt < :before')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
