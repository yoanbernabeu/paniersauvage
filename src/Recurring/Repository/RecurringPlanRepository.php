<?php

declare(strict_types=1);

namespace App\Recurring\Repository;

use App\Recurring\Entity\RecurringPlan;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringPlan>
 */
final class RecurringPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringPlan::class);
    }

    public function findOneBySchedule(SaleSchedule $schedule): ?RecurringPlan
    {
        return $this->findOneBy(['schedule' => $schedule]);
    }

    public function findOneInShop(int $id, Shop $shop): ?RecurringPlan
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop]);
    }

    /**
     * Locks the plan row (`SELECT … FOR UPDATE`): two customers taking the last place are served
     * one after the other. Call it inside a transaction.
     */
    public function lock(RecurringPlan $plan): void
    {
        $this->getEntityManager()->refresh($plan, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @return list<RecurringPlan>
     */
    public function findByShop(Shop $shop): array
    {
        /** @var list<RecurringPlan> $plans */
        $plans = $this->createQueryBuilder('p')
            ->addSelect('s')
            ->join('p.schedule', 's')
            ->andWhere('p.shop = :shop')
            ->setParameter('shop', $shop)
            ->orderBy('p.enabled', 'DESC')
            ->addOrderBy('p.title', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $plans;
    }

    /**
     * Plans that take new subscribers, as shown on the shop page.
     *
     * @return list<RecurringPlan>
     */
    public function findOpenByShop(Shop $shop, \DateTimeImmutable $today): array
    {
        /** @var list<RecurringPlan> $plans */
        $plans = $this->createQueryBuilder('p')
            ->addSelect('s', 'i', 'pr')
            ->join('p.schedule', 's')
            ->leftJoin('p.items', 'i')
            ->leftJoin('i.product', 'pr')
            ->andWhere('p.shop = :shop')
            ->andWhere('p.enabled = true')
            ->andWhere('s.active = true')
            ->setParameter('shop', $shop)
            ->orderBy('p.title', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($plans, static fn (RecurringPlan $plan): bool => $plan->acceptsSubscriptions($today)));
    }

    /**
     * Open sales of the recurrence that still take orders: the ones a new subscription joins at once.
     *
     * @return list<Sale>
     */
    public function findSalesTakingOrders(RecurringPlan $plan, \DateTimeImmutable $now): array
    {
        /** @var list<Sale> $sales */
        $sales = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Sale::class, 's')
            ->andWhere('s.schedule = :schedule')
            ->andWhere('s.status = :open')
            ->andWhere('s.deadlineAt > :now')
            ->setParameter('schedule', $plan->getSchedule())
            ->setParameter('open', SaleStatus::Open)
            ->setParameter('now', $now)
            ->orderBy('s.deliveryDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $sales;
    }

    /**
     * Ids of the open sales, still taking orders, of the recurrences that have a plan.
     *
     * @return list<int>
     */
    public function findSaleIdsToGenerate(\DateTimeImmutable $now): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('s.id')
            ->from(Sale::class, 's')
            ->join(RecurringPlan::class, 'p', 'WITH', 'p.schedule = s.schedule')
            ->andWhere('s.status = :open')
            ->andWhere('s.deadlineAt > :now')
            ->setParameter('open', SaleStatus::Open)
            ->setParameter('now', $now)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
