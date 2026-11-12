<?php

declare(strict_types=1);

namespace App\Legal\Repository;

use App\Legal\Entity\ShopVerification;
use App\Legal\Enum\VerificationStatus;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShopVerification>
 */
final class ShopVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShopVerification::class);
    }

    /**
     * SQL condition "this shop has a valid verification" for native queries on the shop table
     * (ShopRepository::findNearby()): verified, for the SIRET the shop has now.
     *
     * @param string $shopAlias alias of the shop table in the query
     */
    public static function verifiedSql(string $shopAlias): string
    {
        return \sprintf(
            "EXISTS (SELECT 1 FROM shop_verification verification WHERE verification.shop_id = %1\$s.id AND verification.status = '%2\$s' AND verification.siret = %1\$s.siret)",
            $shopAlias,
            VerificationStatus::Verified->value,
        );
    }

    public function findOneByShop(Shop $shop): ?ShopVerification
    {
        return null === $shop->getId() ? null : $this->findOneBy(['shop' => $shop]);
    }

    public function isVerified(Shop $shop): bool
    {
        return true === $this->findOneByShop($shop)?->isValid();
    }

    /**
     * @return list<ShopVerification>
     */
    public function findByStatus(VerificationStatus $status): array
    {
        /** @var list<ShopVerification> $verifications */
        $verifications = $this->createQueryBuilder('v')
            ->addSelect('s')
            ->innerJoin('v.shop', 's')
            ->andWhere('v.status = :status')
            ->setParameter('status', $status)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $verifications;
    }

    /**
     * Shops with a SIRET and no verification yet (created before the check existed, or by hand).
     *
     * @return list<Shop>
     */
    public function findUncheckedShops(): array
    {
        /** @var list<Shop> $shops */
        $shops = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Shop::class, 's')
            ->leftJoin(ShopVerification::class, 'v', 'WITH', 'v.shop = s')
            ->andWhere('s.siret IS NOT NULL')
            ->andWhere('v.id IS NULL')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $shops;
    }
}
