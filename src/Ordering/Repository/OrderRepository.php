<?php

declare(strict_types=1);

namespace App\Ordering\Repository;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderEvent;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByReference(string $reference): ?Order
    {
        return $this->findOneBy(['reference' => mb_strtoupper($reference)]);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->count(['reference' => $reference]) > 0;
    }

    /**
     * @return list<Order>
     */
    public function findBySale(Sale $sale, bool $includeCancelled = false): array
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('c', 'l')
            ->join('o.customer', 'c')
            ->leftJoin('o.lines', 'l')
            ->andWhere('o.sale = :sale')
            ->setParameter('sale', $sale)
            ->orderBy('o.customerLastName', 'ASC')
            ->addOrderBy('o.customerFirstName', 'ASC')
            ->addOrderBy('o.id', 'ASC');

        if (!$includeCancelled) {
            $qb->andWhere('o.status != :cancelled')->setParameter('cancelled', OrderStatus::Cancelled);
        }

        /** @var list<Order> $orders */
        $orders = $qb->getQuery()->getResult();

        return $orders;
    }

    /**
     * Orders whose customer must hear about the cancellation of the sale: the ones still active and
     * the ones the system cancelled along with the sale (not the ones the customer or the producer
     * had cancelled before).
     *
     * @return list<Order>
     */
    public function findToWarnOfSaleCancellation(Sale $sale): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.customer', 'c')
            ->andWhere('o.sale = :sale')
            ->andWhere(\sprintf(
                'o.status != :cancelled OR EXISTS (SELECT e.id FROM %s e WHERE e.order = o AND e.type = :cancelledEvent AND e.actor = :system)',
                OrderEvent::class,
            ))
            ->setParameter('sale', $sale)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->setParameter('cancelledEvent', OrderEventType::Cancelled)
            ->setParameter('system', Actor::System)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * Cancelled sales that still have an active order (see CancelledSaleReconciler).
     *
     * @return list<Sale>
     */
    public function findCancelledSalesWithActiveOrders(): array
    {
        /** @var list<Sale> $sales */
        $sales = $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Sale::class, 's')
            ->andWhere('s.status = :cancelledSale')
            ->andWhere(\sprintf('EXISTS (SELECT o.id FROM %s o WHERE o.sale = s AND o.status != :cancelledOrder)', Order::class))
            ->setParameter('cancelledSale', SaleStatus::Cancelled)
            ->setParameter('cancelledOrder', OrderStatus::Cancelled)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $sales;
    }

    /**
     * Number of active (not cancelled) orders per sale id; sales without order are absent.
     *
     * @param list<Sale> $sales
     *
     * @return array<int, int>
     */
    public function countActiveBySale(array $sales): array
    {
        /** @var list<array{sale: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.sale) AS sale', 'COUNT(o.id) AS total')
            ->andWhere('o.sale IN (:sales)')
            ->andWhere('o.status != :cancelled')
            ->setParameter('sales', $sales)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->groupBy('o.sale')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['sale']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Orders per delivery of the sale, cancelled ones included (`all`) or not (`active`).
     *
     * @return array<int, array{all: int, active: int}>
     */
    public function countByDelivery(Sale $sale): array
    {
        /** @var list<array{delivery: int|string, total: int|string, active: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.saleDelivery) AS delivery', 'COUNT(o.id) AS total', 'SUM(CASE WHEN o.status != :cancelled THEN 1 ELSE 0 END) AS active')
            ->andWhere('o.sale = :sale')
            ->setParameter('sale', $sale)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->groupBy('o.saleDelivery')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['delivery']] = ['all' => (int) $row['total'], 'active' => (int) $row['active']];
        }

        return $counts;
    }

    /**
     * Ids of the offers of the sale referenced by an order line, cancelled orders included.
     *
     * @return list<int>
     */
    public function findOrderedOfferIds(Sale $sale): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(l.saleOffer)')
            ->from(OrderLine::class, 'l')
            ->join('l.order', 'o')
            ->andWhere('o.sale = :sale')
            ->setParameter('sale', $sale)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * The order of this sale with that id, or null (producer isolation starts from the sale).
     */
    public function findOneInSale(int $id, Sale $sale): ?Order
    {
        return $this->findOneBy(['id' => $id, 'sale' => $sale]);
    }

    /**
     * The customer's order still running (not cancelled) in this sale, if any.
     */
    public function findActiveForCustomerInSale(Customer $customer, Sale $sale): ?Order
    {
        $order = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->andWhere('o.sale = :sale')
            ->andWhere('o.status != :cancelled')
            ->setParameter('customer', $customer)
            ->setParameter('sale', $sale)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }

    /**
     * The customer's latest non-cancelled order at this producer, outside the given sale:
     * the source of "Reprendre ma commande précédente".
     */
    public function findPreviousForCustomerInShop(Customer $customer, Shop $shop, Sale $except): ?Order
    {
        $order = $this->createQueryBuilder('o')
            ->join('o.sale', 's')
            ->andWhere('o.customer = :customer')
            ->andWhere('s.shop = :shop')
            ->andWhere('o.sale != :except')
            ->andWhere('o.status != :cancelled')
            ->setParameter('customer', $customer)
            ->setParameter('shop', $shop)
            ->setParameter('except', $except)
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }

    /**
     * Whether the customer already picked up an order at this producer (no longer a new customer).
     */
    public function hasDeliveredInShop(Customer $customer, Shop $shop): bool
    {
        if (null === $customer->getId()) {
            return false;
        }

        $found = $this->createQueryBuilder('o')
            ->select('1')
            ->join('o.sale', 's')
            ->andWhere('o.customer = :customer')
            ->andWhere('s.shop = :shop')
            ->andWhere('o.status = :delivered')
            ->setParameter('customer', $customer)
            ->setParameter('shop', $shop)
            ->setParameter('delivered', OrderStatus::Delivered)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $found;
    }

    /**
     * The customer's orders at this producer only (never another shop's), the latest first.
     *
     * @return list<Order>
     */
    public function findByCustomerInShop(Customer $customer, Shop $shop): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->addSelect('s', 'l')
            ->join('o.sale', 's')
            ->leftJoin('o.lines', 'l')
            ->andWhere('o.customer = :customer')
            ->andWhere('s.shop = :shop')
            ->setParameter('customer', $customer)
            ->setParameter('shop', $shop)
            ->orderBy('s.deliveryDate', 'DESC')
            ->addOrderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * @return list<Order>
     */
    public function findByCustomer(Customer $customer): array
    {
        // Sale, shop, delivery point and lines fetched along: "Mon compte" shows them for every order
        // (the lines tell whether the amount is an estimate, see Order::isEstimated()).
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->addSelect('s', 'shop', 'd', 'dp', 'l')
            ->join('o.sale', 's')
            ->join('s.shop', 'shop')
            ->join('o.saleDelivery', 'd')
            ->join('d.deliveryPoint', 'dp')
            ->leftJoin('o.lines', 'l')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $orders;
    }
}
