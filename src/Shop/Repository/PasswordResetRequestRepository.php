<?php

declare(strict_types=1);

namespace App\Shop\Repository;

use App\Shop\Entity\PasswordResetRequest;
use App\Shop\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
final class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Burns every pending request of the producer (a newer one was made, or the password changed).
     */
    public function invalidatePending(User $user, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.usedAt', ':now')
            ->where('r.user = :user')
            ->andWhere('r.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Housekeeping: drops requests that expired before the given date.
     */
    public function deleteExpired(\DateTimeImmutable $before): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.expiresAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
