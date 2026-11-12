<?php

declare(strict_types=1);

namespace App\Ordering\Repository;

use App\Ordering\Entity\OrderEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderEvent>
 */
final class OrderEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderEvent::class);
    }
}
