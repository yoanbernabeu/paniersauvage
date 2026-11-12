<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\Product;
use App\Shop\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return list<Product>
     */
    public function findByShop(Shop $shop, bool $includeArchived = false): array
    {
        $criteria = ['shop' => $shop];
        if (!$includeArchived) {
            $criteria['archived'] = false;
        }

        return $this->findBy($criteria, ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Products offered by default in a sale delivered on the given date: the permanent range
     * plus seasonal products whose period covers the date.
     *
     * @return list<Product>
     */
    public function findAvailableOn(Shop $shop, \DateTimeInterface $deliveryDate): array
    {
        return array_values(array_filter(
            $this->findByShop($shop),
            static fn (Product $product): bool => $product->isAvailableOn($deliveryDate),
        ));
    }

    /**
     * Labels of the active products of each shop, without duplicates: one query for all the shops.
     *
     * @param list<Shop> $shops
     *
     * @return array<int, list<string>> indexed by shop id; shops without active product are left out
     */
    public function findLabelsByShops(array $shops): array
    {
        if ([] === $shops) {
            return [];
        }

        /** @var list<array{shopId: int, labels: list<string>}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.shop) AS shopId', 'p.labels')
            ->andWhere('p.shop IN (:shops)')
            ->andWhere('p.archived = false')
            ->setParameter('shops', $shops)
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byShop = [];
        foreach ($rows as $row) {
            $byShop[$row['shopId']][] = $row['labels'];
        }

        return array_map(Product::uniqueLabels(...), $byShop);
    }

    public function findOneInShop(int $id, Shop $shop): ?Product
    {
        return $this->findOneBy(['id' => $id, 'shop' => $shop]);
    }
}
