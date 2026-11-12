<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\CatalogPublication;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogPublication>
 */
final class CatalogPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogPublication::class);
    }

    /**
     * The publication settings of the shop, or the default (private, not persisted).
     */
    public function forShop(Shop $shop): CatalogPublication
    {
        return $this->findOneBy(['shop' => $shop]) ?? new CatalogPublication($shop);
    }

    public function isDfcPublic(Shop $shop): bool
    {
        return true === $this->findOneBy(['shop' => $shop])?->isDfcPublic();
    }
}
