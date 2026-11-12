<?php

declare(strict_types=1);

namespace App\Delivery\Repository;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryPoint>
 */
final class DeliveryPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryPoint::class);
    }

    /**
     * @return list<DeliveryPoint>
     */
    public function findByShop(Shop $shop, bool $includeArchived = false): array
    {
        $criteria = ['shop' => $shop];
        if (!$includeArchived) {
            $criteria['archived'] = false;
        }

        return $this->findBy($criteria, ['name' => 'ASC']);
    }

    public function findOneInShop(int $id, Shop $shop): ?DeliveryPoint
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop]);
    }

    /**
     * Fixed points (meeting points, tour stops) with an address and home deliveries with a zone
     * town, still without coordinates, or all of them with $all: what `app:delivery-points:geocode`
     * works through.
     *
     * @return list<DeliveryPoint>
     */
    public function findToGeocode(bool $all = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('(p.type <> :home AND p.address IS NOT NULL) OR (p.type = :home AND p.zoneTown IS NOT NULL)')
            ->setParameter('home', DeliveryPointType::Home)
            ->orderBy('p.id', 'ASC');

        if (!$all) {
            $qb->andWhere('p.latitude IS NULL');
        }

        /** @var list<DeliveryPoint> $points */
        $points = $qb->getQuery()->getResult();

        return $points;
    }
}
