<?php

declare(strict_types=1);

namespace App\Sale\Repository;

use App\Sale\Entity\SaleDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaleDelivery>
 */
final class SaleDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleDelivery::class);
    }
}
