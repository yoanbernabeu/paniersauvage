<?php

declare(strict_types=1);

namespace App\Sale\Repository;

use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Enum\SaleStatus;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sale>
 */
final class SaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    public function findOneByCode(string $code): ?Sale
    {
        return $this->findOneBy(['code' => mb_strtoupper($code)]);
    }

    public function findOneByShopAndCode(Shop $shop, string $code): ?Sale
    {
        return $this->findOneBy(['shop' => $shop, 'code' => mb_strtoupper($code)]);
    }

    public function findOneInShop(int $id, Shop $shop): ?Sale
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop]);
    }

    /**
     * Loads the offers (with their product) and the deliveries (with their point) of the sale in two
     * queries, instead of one query per offer or delivery when a page goes through them (order form).
     */
    public function loadOffersAndDeliveries(Sale $sale): void
    {
        if (null === $sale->getId()) {
            return;
        }

        $this->createQueryBuilder('s')
            ->addSelect('o', 'p')
            ->leftJoin('s.offers', 'o')
            ->leftJoin('o.product', 'p')
            ->andWhere('s = :sale')
            ->setParameter('sale', $sale)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('s')
            ->addSelect('d', 'dp')
            ->leftJoin('s.deliveries', 'd')
            ->leftJoin('d.deliveryPoint', 'dp')
            ->andWhere('s = :sale')
            ->setParameter('sale', $sale)
            ->getQuery()
            ->getResult();
    }

    public function codeExists(string $code): bool
    {
        return $this->count(['code' => $code]) > 0;
    }

    /**
     * Open sales, and closed ones with a last-minute sale (it may be over: filter with
     * `Sale::acceptsOrders()`).
     *
     * @return list<Sale>
     */
    public function findOpenByShop(Shop $shop): array
    {
        /** @var list<Sale> $sales */
        $sales = $this->createQueryBuilder('s')
            ->andWhere('s.shop = :shop')
            ->andWhere('s.status = :open OR (s.status = :closed AND s.lastMinuteUntil IS NOT NULL)')
            ->setParameter('shop', $shop)
            ->setParameter('open', SaleStatus::Open)
            ->setParameter('closed', SaleStatus::Closed)
            ->orderBy('s.deliveryDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $sales;
    }

    /**
     * For each shop, its sale that takes orders now (the earliest deadline first), or else its next
     * scheduled sale: what the producer search shows. One query for all the shops.
     *
     * @param list<Shop> $shops
     *
     * @return array<int, Sale> indexed by shop id; shops without such a sale are left out
     */
    public function findNextByShops(array $shops, \DateTimeImmutable $now): array
    {
        if ([] === $shops) {
            return [];
        }

        /** @var list<Sale> $sales */
        $sales = $this->createQueryBuilder('s')
            ->andWhere('s.shop IN (:shops)')
            ->andWhere('(s.status = :open AND s.deadlineAt > :now) OR s.status = :scheduled')
            ->setParameter('shops', $shops)
            ->setParameter('open', SaleStatus::Open)
            ->setParameter('scheduled', SaleStatus::Scheduled)
            ->setParameter('now', $now)
            ->orderBy('s.deadlineAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        $next = [];
        foreach ($sales as $sale) {
            $shopId = (int) $sale->getShop()->getId();
            $current = $next[$shopId] ?? null;

            // An open sale always beats a scheduled one; within a status, the earliest deadline wins.
            if (null === $current || ($sale->isOpen() && !$current->isOpen())) {
                $next[$shopId] = $sale;
            }
        }

        return $next;
    }

    /**
     * @param list<SaleStatus> $statuses
     *
     * @return list<Sale>
     */
    public function findByShopAndStatuses(Shop $shop, array $statuses, bool $newestFirst = false, ?int $limit = null): array
    {
        return $this->findBy(
            ['shop' => $shop, 'status' => $statuses],
            ['deliveryDate' => $newestFirst ? 'DESC' : 'ASC', 'id' => 'ASC'],
            $limit,
        );
    }

    /**
     * Open or closed sales still to be delivered, the nearest first.
     *
     * @return list<Sale>
     */
    public function findUpcomingDeliveries(Shop $shop, \DateTimeImmutable $today, int $limit = 5): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('shop', $shop))
            ->andWhere(Criteria::expr()->in('status', [SaleStatus::Open, SaleStatus::Closed]))
            ->andWhere(Criteria::expr()->gte('deliveryDate', $today->setTime(0, 0)))
            ->orderBy(['deliveryDate' => Order::Ascending, 'id' => Order::Ascending])
            ->setMaxResults($limit);

        return array_values($this->matching($criteria)->toArray());
    }

    public function countByShop(Shop $shop): int
    {
        return $this->count(['shop' => $shop]);
    }

    /**
     * Every sale already generated by a recurrence, whatever its status.
     *
     * @return list<Sale>
     */
    public function findBySchedule(SaleSchedule $schedule): array
    {
        return $this->findBy(['schedule' => $schedule], ['deliveryDate' => 'ASC']);
    }

    /**
     * Scheduled sales whose opening time has come and whose deadline is still ahead.
     *
     * @return list<Sale>
     */
    public function findDueToOpen(\DateTimeImmutable $now): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', SaleStatus::Scheduled))
            ->andWhere(Criteria::expr()->lte('opensAt', $now))
            ->andWhere(Criteria::expr()->gt('deadlineAt', $now))
            ->orderBy(['opensAt' => Order::Ascending, 'id' => Order::Ascending]);

        return array_values($this->matching($criteria)->toArray());
    }

    /**
     * Open sales whose deadline has passed.
     *
     * @return list<Sale>
     */
    public function findDueToClose(\DateTimeImmutable $now): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', SaleStatus::Open))
            ->andWhere(Criteria::expr()->lte('deadlineAt', $now))
            ->orderBy(['deadlineAt' => Order::Ascending, 'id' => Order::Ascending]);

        return array_values($this->matching($criteria)->toArray());
    }

    /**
     * Closed sales delivered before today.
     *
     * @return list<Sale>
     */
    public function findDueToFinish(\DateTimeImmutable $now): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', SaleStatus::Closed))
            ->andWhere(Criteria::expr()->lt('deliveryDate', $now->setTime(0, 0)))
            ->orderBy(['deliveryDate' => Order::Ascending, 'id' => Order::Ascending]);

        return array_values($this->matching($criteria)->toArray());
    }

    /**
     * Sales that never opened (inactive subscription) and whose deadline has passed.
     *
     * @return list<Sale>
     */
    public function findMissed(\DateTimeImmutable $now): array
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', SaleStatus::Scheduled))
            ->andWhere(Criteria::expr()->lte('deadlineAt', $now))
            ->orderBy(['deadlineAt' => Order::Ascending, 'id' => Order::Ascending]);

        return array_values($this->matching($criteria)->toArray());
    }
}
