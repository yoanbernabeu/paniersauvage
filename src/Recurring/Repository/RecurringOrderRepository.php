<?php

declare(strict_types=1);

namespace App\Recurring\Repository;

use App\Customer\Entity\Customer;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\RecurringOrderStatus;
use App\Sale\Entity\Sale;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringOrder>
 */
final class RecurringOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringOrder::class);
    }

    /**
     * A subscription to a plan of this shop; null otherwise (404 for any other producer).
     */
    public function findOneInShop(int $id, Shop $shop): ?RecurringOrder
    {
        /** @var RecurringOrder|null $recurringOrder */
        $recurringOrder = $this->withPlan()
            ->andWhere('ro.id = :id')
            ->andWhere('p.shop = :shop')
            ->setParameter('id', $id)
            ->setParameter('shop', $shop)
            ->getQuery()
            ->getOneOrNullResult();

        return $recurringOrder;
    }

    public function findOneOfCustomer(int $id, Customer $customer): ?RecurringOrder
    {
        /** @var RecurringOrder|null $recurringOrder */
        $recurringOrder = $this->withPlan()
            ->andWhere('ro.id = :id')
            ->andWhere('ro.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();

        return $recurringOrder;
    }

    /**
     * The customer's subscription in progress (active or suspended) to this plan.
     */
    public function findInProgress(RecurringPlan $plan, Customer $customer): ?RecurringOrder
    {
        /** @var RecurringOrder|null $recurringOrder */
        $recurringOrder = $this->createQueryBuilder('ro')
            ->andWhere('ro.plan = :plan')
            ->andWhere('ro.customer = :customer')
            ->andWhere('ro.status <> :ended')
            ->setParameter('plan', $plan)
            ->setParameter('customer', $customer)
            ->setParameter('ended', RecurringOrderStatus::Ended)
            ->getQuery()
            ->getOneOrNullResult();

        return $recurringOrder;
    }

    /**
     * Places taken: subscriptions in progress (active or suspended).
     */
    public function countInProgress(RecurringPlan $plan): int
    {
        return (int) $this->createQueryBuilder('ro')
            ->select('COUNT(ro.id)')
            ->andWhere('ro.plan = :plan')
            ->andWhere('ro.status <> :ended')
            ->setParameter('plan', $plan)
            ->setParameter('ended', RecurringOrderStatus::Ended)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Subscriptions of the plans of a shop: in progress first, then by name.
     *
     * @return list<RecurringOrder>
     */
    public function findByShop(Shop $shop): array
    {
        /** @var list<RecurringOrder> $recurringOrders */
        $recurringOrders = $this->withPlan()
            ->addSelect('c', 'dp')
            ->join('ro.customer', 'c')
            ->join('ro.deliveryPoint', 'dp')
            ->andWhere('p.shop = :shop')
            ->setParameter('shop', $shop)
            ->addSelect('CASE WHEN ro.status = :ended THEN 1 ELSE 0 END AS HIDDEN ended_rank')
            ->addOrderBy('ended_rank', 'ASC')
            ->addOrderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->addOrderBy('ro.id', 'ASC')
            ->setParameter('ended', RecurringOrderStatus::Ended)
            ->getQuery()
            ->getResult();

        return $recurringOrders;
    }

    /**
     * The customer's subscriptions, in progress first, newest first.
     *
     * @return list<RecurringOrder>
     */
    public function findByCustomer(Customer $customer): array
    {
        /** @var list<RecurringOrder> $recurringOrders */
        $recurringOrders = $this->withPlan()
            ->addSelect('sh')
            ->join('p.shop', 'sh')
            ->andWhere('ro.customer = :customer')
            ->setParameter('customer', $customer)
            ->addSelect('CASE WHEN ro.status = :ended THEN 1 ELSE 0 END AS HIDDEN ended_rank')
            ->addOrderBy('ended_rank', 'ASC')
            ->addOrderBy('ro.createdAt', 'DESC')
            ->addOrderBy('ro.id', 'DESC')
            ->setParameter('ended', RecurringOrderStatus::Ended)
            ->getQuery()
            ->getResult();

        return $recurringOrders;
    }

    /**
     * Ids of the active subscriptions of the plan covering the delivery day of the sale, without an
     * occurrence for that sale yet.
     *
     * @return list<int>
     */
    public function findIdsToGenerate(RecurringPlan $plan, Sale $sale): array
    {
        $day = $sale->getDeliveryDate();

        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('ro')
            ->select('ro.id')
            ->andWhere('ro.plan = :plan')
            ->andWhere('ro.status = :active')
            ->andWhere('ro.startsOn <= :day')
            ->andWhere('ro.endsOn IS NULL OR ro.endsOn >= :day')
            ->andWhere(\sprintf('NOT EXISTS (SELECT 1 FROM %s o WHERE o.recurringOrder = ro AND o.sale = :sale)', RecurringOccurrence::class))
            ->setParameter('plan', $plan)
            ->setParameter('active', RecurringOrderStatus::Active)
            ->setParameter('day', $day, 'date_immutable')
            ->setParameter('sale', $sale)
            ->orderBy('ro.createdAt', 'ASC')
            ->addOrderBy('ro.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * Subscriptions in progress whose last delivery day has passed.
     *
     * @return list<RecurringOrder>
     */
    public function findDueToEnd(\DateTimeImmutable $today): array
    {
        /** @var list<RecurringOrder> $recurringOrders */
        $recurringOrders = $this->withPlan()
            ->andWhere('ro.status <> :ended')
            ->andWhere('ro.endsOn < :today')
            ->setParameter('ended', RecurringOrderStatus::Ended)
            ->setParameter('today', $today, 'date_immutable')
            ->orderBy('ro.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $recurringOrders;
    }

    private function withPlan(): QueryBuilder
    {
        return $this->createQueryBuilder('ro')
            ->addSelect('p')
            ->join('ro.plan', 'p');
    }
}
