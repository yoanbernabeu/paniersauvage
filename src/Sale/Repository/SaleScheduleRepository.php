<?php

declare(strict_types=1);

namespace App\Sale\Repository;

use App\Sale\Entity\SaleSchedule;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaleSchedule>
 */
final class SaleScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleSchedule::class);
    }

    /**
     * @return list<SaleSchedule>
     */
    public function findByShop(Shop $shop): array
    {
        return $this->findBy(['shop' => $shop], ['active' => 'DESC', 'weekday' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneInShop(int $id, Shop $shop): ?SaleSchedule
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop]);
    }

    /**
     * @return list<SaleSchedule>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['id' => 'ASC']);
    }
}
