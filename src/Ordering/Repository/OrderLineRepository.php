<?php

declare(strict_types=1);

namespace App\Ordering\Repository;

use App\Ordering\Entity\OrderLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderLine>
 */
final class OrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderLine::class);
    }
}
