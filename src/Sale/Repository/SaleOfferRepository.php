<?php

declare(strict_types=1);

namespace App\Sale\Repository;

use App\Sale\Entity\SaleOffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaleOffer>
 */
final class SaleOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleOffer::class);
    }

    /**
     * Loads the offer with `SELECT … FOR UPDATE` and refreshes its counters.
     * Must be called inside a transaction (see App\Ordering\Service\ReservationService).
     */
    public function findForUpdate(int $id): ?SaleOffer
    {
        return $this->find($id, LockMode::PESSIMISTIC_WRITE);
    }
}
