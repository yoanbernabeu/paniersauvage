<?php

declare(strict_types=1);

namespace App\Recurring\Repository;

use App\Ordering\Entity\Order;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Sale\Entity\Sale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringOccurrence>
 */
final class RecurringOccurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringOccurrence::class);
    }

    public function findOneFor(RecurringOrder $recurringOrder, Sale $sale): ?RecurringOccurrence
    {
        return $this->findOneBy(['recurringOrder' => $recurringOrder, 'sale' => $sale]);
    }

    public function findOneByOrder(Order $order): ?RecurringOccurrence
    {
        return $this->findOneBy(['order' => $order]);
    }

    /**
     * @return list<RecurringOccurrence>
     */
    public function findBySale(Sale $sale): array
    {
        return $this->findBy(['sale' => $sale], ['id' => 'ASC']);
    }
}
