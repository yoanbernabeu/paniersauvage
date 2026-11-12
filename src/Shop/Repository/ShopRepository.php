<?php

declare(strict_types=1);

namespace App\Shop\Repository;

use App\Billing\Entity\ShopSubscription;
use App\Catalog\Entity\Product;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Instance\InstanceSettings;
use App\Instance\SubscriptionPolicy;
use App\Legal\Repository\ShopVerificationRepository;
use App\Shop\Entity\Shop;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Search\DirectoryCommune;
use App\Shop\Search\NearbyShop;
use App\Shop\Search\SearchFilters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shop>
 */
final class ShopRepository extends ServiceEntityRepository
{
    /** Mean radius of the Earth (IUGG), in kilometres. */
    private const float EARTH_RADIUS_KM = 6371.0088;

    /** Kilometres per degree of latitude. */
    private const float KM_PER_DEGREE = 111.32;

    /**
     * Product labels that mean organic farming, case-insensitive, at the start of the label
     * ("Bio", "AB", "Agriculture biologique", "Bio local"; not "Biodynamie").
     */
    public const string ORGANIC_LABEL_PATTERN = '^(bio|ab|agriculture biologique)\M';

    public function __construct(
        ManagerRegistry $registry,
        private readonly SubscriptionPolicy $subscriptionPolicy,
        private readonly InstanceSettings $instance,
    ) {
        parent::__construct($registry, Shop::class);
    }

    public function findOneBySlug(string $slug): ?Shop
    {
        return $this->findOneBy(['slug' => mb_strtolower($slug)]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->count(['slug' => mb_strtolower($slug)]) > 0;
    }

    /**
     * Shops of the directory around a point, the nearest first (SPEC §11), in one query: shops that
     * opted in with at least one category, whose subscription allows new sales and whose SIRET is
     * verified (SPEC §12), with either their
     * active fixed pick-up point nearest to the point (within $radiusKm), or a home delivery zone
     * that contains the point (distance 0). Great-circle distance (haversine formula, plain SQL, no
     * PostGIS), computed only for the points inside a bounding box, which the (latitude, longitude)
     * index narrows down.
     *
     * @return list<NearbyShop> without their next sale nor labels (see ProducerSearch)
     */
    public function findNearby(float $latitude, float $longitude, int $radiusKm, \DateTimeImmutable $now, int $limit, SearchFilters $filters = new SearchFilters(), ?int $excludedShopId = null): array
    {
        $em = $this->getEntityManager();
        [$visible, $visibleParameters] = $this->visibilitySql('s', $now);
        [$filtered, $filterParameters] = $this->filtersSql('s', $filters);

        $rsm = new ResultSetMappingBuilder($em, ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);
        $rsm->addRootEntityFromClassMetadata(Shop::class, 's');
        $rsm->addScalarResult('distance_km', 'distance_km', 'float');
        $rsm->addScalarResult('point_name', 'point_name', 'string');
        $rsm->addScalarResult('point_address', 'point_address', 'string');
        $rsm->addScalarResult('point_latitude', 'point_latitude', 'float');
        $rsm->addScalarResult('point_longitude', 'point_longitude', 'float');
        $rsm->addScalarResult('home_delivery', 'home_delivery', 'boolean');

        $distance = \sprintf(
            <<<'SQL'
                2 * %F * ASIN(LEAST(1.0, SQRT(
                    POWER(SIN(RADIANS(p.latitude - CAST(:lat AS DOUBLE PRECISION)) / 2), 2)
                    + COS(RADIANS(CAST(:lat AS DOUBLE PRECISION))) * COS(RADIANS(p.latitude))
                    * POWER(SIN(RADIANS(p.longitude - CAST(:lng AS DOUBLE PRECISION)) / 2), 2)
                )))
                SQL,
            self::EARTH_RADIUS_KM,
        );

        $sql = \sprintf(
            <<<'SQL'
                SELECT %1$s, nearest.distance_km, nearest.point_name, nearest.point_address, nearest.point_latitude,
                    nearest.point_longitude, nearest.home_delivery
                FROM (
                    SELECT DISTINCT ON (candidate.shop_id) candidate.*
                    FROM (
                        SELECT p.shop_id, p.name AS point_name, p.address AS point_address, p.latitude AS point_latitude,
                            p.longitude AS point_longitude, %2$s AS distance_km, FALSE AS home_delivery
                        FROM %3$s p
                        WHERE p.archived = FALSE AND p.type <> :home
                            AND p.latitude BETWEEN :min_lat AND :max_lat
                            AND p.longitude BETWEEN :min_lng AND :max_lng
                            AND %9$s
                        UNION ALL
                        SELECT p.shop_id, p.name, p.zone_town, p.latitude, p.longitude, 0.0, TRUE
                        FROM %3$s p
                        WHERE p.archived = FALSE AND p.type = :home AND p.zone_radius_km IS NOT NULL
                            AND p.latitude BETWEEN :zone_min_lat AND :zone_max_lat
                            AND p.longitude BETWEEN :zone_min_lng AND :zone_max_lng
                            AND %2$s <= p.zone_radius_km
                            AND %9$s
                    ) candidate
                    ORDER BY candidate.shop_id, candidate.distance_km, candidate.home_delivery DESC
                ) nearest
                INNER JOIN %4$s s ON s.id = nearest.shop_id
                WHERE nearest.distance_km <= :radius
                    AND %5$s
                    AND %6$s
                    %8$s
                ORDER BY nearest.distance_km ASC, s.name ASC, s.id ASC
                LIMIT %7$d
                SQL,
            $rsm->generateSelectClause(['s' => 's']),
            $distance,
            $em->getClassMetadata(DeliveryPoint::class)->getTableName(),
            $em->getClassMetadata(Shop::class)->getTableName(),
            $visible,
            $filtered,
            $limit,
            null === $excludedShopId ? '' : 'AND s.id <> '.$excludedShopId,
            $this->instance->territory->pointSql('p'),
        );

        $query = $em->createNativeQuery($sql, $rsm)->setParameters([
            'lat' => $latitude,
            'lng' => $longitude,
            ...$this->boundingBox($latitude, $longitude, $radiusKm, ''),
            ...$this->boundingBox($latitude, $longitude, DeliveryPoint::MAX_ZONE_RADIUS_KM, 'zone_'),
            'radius' => $radiusKm,
            'home' => DeliveryPointType::Home->value,
            ...$visibleParameters,
            ...$filterParameters,
        ]);

        $results = [];
        /** @var list<array{0: Shop, distance_km: float, point_name: string, point_address: ?string, point_latitude: float, point_longitude: float, home_delivery: bool}> $rows */
        $rows = $query->getResult();
        foreach ($rows as $row) {
            $results[] = new NearbyShop(
                $row[0],
                (float) $row['distance_km'],
                $row['point_name'],
                $row['point_address'],
                (float) $row['point_latitude'],
                (float) $row['point_longitude'],
                homeDelivery: $row['home_delivery'],
            );
        }

        return $results;
    }

    /**
     * Towns with at least one located fixed pick-up point of a shop of the directory, in
     * alphabetical order: the pages `/trouver-un-producteur/{commune}` and the sitemap.
     *
     * @return list<DirectoryCommune>
     */
    public function findDirectoryCommunes(\DateTimeImmutable $now): array
    {
        return $this->directoryCommunes($now, null);
    }

    public function findDirectoryCommune(string $slug, \DateTimeImmutable $now): ?DirectoryCommune
    {
        return $this->directoryCommunes($now, $slug)[0] ?? null;
    }

    /**
     * Shops of the directory, whatever their place: the shop pages of the sitemap.
     *
     * @return list<Shop>
     */
    public function findInDirectory(\DateTimeImmutable $now): array
    {
        [$visible, $parameters] = $this->visibilitySql('s', $now);
        $em = $this->getEntityManager();
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata(Shop::class, 's');

        /** @var list<Shop> $shops */
        $shops = $em->createNativeQuery(\sprintf('SELECT %s FROM %s s WHERE %s ORDER BY s.slug ASC', $rsm->generateSelectClause(), $em->getClassMetadata(Shop::class)->getTableName(), $visible), $rsm)
            ->setParameters($parameters)
            ->getResult();

        return $shops;
    }

    /**
     * @return list<DirectoryCommune>
     */
    private function directoryCommunes(\DateTimeImmutable $now, ?string $slug): array
    {
        $em = $this->getEntityManager();
        [$visible, $parameters] = $this->visibilitySql('s', $now);

        $sql = \sprintf(
            <<<'SQL'
                SELECT p.commune_slug AS slug, MIN(p.city) AS city, MIN(p.postcode) AS postcode,
                    AVG(p.latitude) AS latitude, AVG(p.longitude) AS longitude, COUNT(DISTINCT s.id) AS shops
                FROM %1$s p
                INNER JOIN %2$s s ON s.id = p.shop_id
                WHERE p.archived = FALSE AND p.type <> :home AND p.commune_slug IS NOT NULL
                    AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
                    AND %3$s
                    AND %5$s
                    %4$s
                GROUP BY p.commune_slug
                ORDER BY MIN(p.city) ASC, p.commune_slug ASC
                SQL,
            $em->getClassMetadata(DeliveryPoint::class)->getTableName(),
            $em->getClassMetadata(Shop::class)->getTableName(),
            $visible,
            null === $slug ? '' : 'AND p.commune_slug = :slug',
            $this->instance->territory->pointSql('p'),
        );

        $parameters['home'] = DeliveryPointType::Home->value;
        if (null !== $slug) {
            $parameters['slug'] = $slug;
        }

        /** @var list<array{slug: string, city: string, postcode: string, latitude: float|string, longitude: float|string, shops: int|string}> $rows */
        $rows = $em->getConnection()->fetchAllAssociative($sql, $parameters);

        return array_map(static fn (array $row): DirectoryCommune => new DirectoryCommune(
            $row['slug'],
            $row['city'],
            $row['postcode'],
            (float) $row['latitude'],
            (float) $row['longitude'],
            (int) $row['shops'],
        ), $rows);
    }

    /**
     * SQL condition of a shop of the directory: opted in, at least one category, allowed to open new
     * sales (`SubscriptionPolicy::allowsNewSales()`: its subscription, or the sponsored instance),
     * SIRET verified, and on a territorial instance at least one active point in the territory.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function visibilitySql(string $alias, \DateTimeImmutable $now): array
    {
        $em = $this->getEntityManager();
        [$allowsNewSales, $parameters] = $this->subscriptionPolicy->shopAllowsNewSalesSql($alias, $em->getClassMetadata(ShopSubscription::class)->getTableName(), $now);
        $condition = \sprintf(
            '%1$s.listed_in_search = TRUE AND jsonb_array_length(%1$s.categories) > 0 AND %2$s AND %3$s',
            $alias,
            $allowsNewSales,
            ShopVerificationRepository::verifiedSql($alias),
        );

        if ($this->instance->territory->isRestricted()) {
            $condition .= \sprintf(
                ' AND EXISTS (SELECT 1 FROM %s tp WHERE tp.shop_id = %s.id AND tp.archived = FALSE AND %s)',
                $em->getClassMetadata(DeliveryPoint::class)->getTableName(),
                $alias,
                $this->instance->territory->pointSql('tp'),
            );
        }

        return [$condition, $parameters];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function filtersSql(string $alias, SearchFilters $filters): array
    {
        $conditions = ['TRUE'];
        $parameters = [];

        if ($filters->category instanceof ShopCategory) {
            $conditions[] = \sprintf('jsonb_exists(%s.categories, :filter_category)', $alias);
            $parameters['filter_category'] = $filters->category->value;
        }

        if ($filters->foodVouchers) {
            $conditions[] = \sprintf('jsonb_exists(%s.payment_methods, :filter_food_voucher)', $alias);
            $parameters['filter_food_voucher'] = PaymentMethod::FoodVoucher->value;
        }

        if ($filters->localCurrency) {
            $conditions[] = \sprintf('jsonb_exists(%s.payment_methods, :filter_local_currency)', $alias);
            $parameters['filter_local_currency'] = PaymentMethod::LocalCurrency->value;
        }

        if ($filters->organic) {
            $conditions[] = \sprintf(
                'EXISTS (SELECT 1 FROM %s pr CROSS JOIN LATERAL json_array_elements_text(pr.labels) AS label(value) WHERE pr.shop_id = %s.id AND pr.archived = FALSE AND label.value ~* :filter_organic)',
                $this->getEntityManager()->getClassMetadata(Product::class)->getTableName(),
                $alias,
            );
            $parameters['filter_organic'] = self::ORGANIC_LABEL_PATTERN;
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    /**
     * @return array<string, float>
     */
    private function boundingBox(float $latitude, float $longitude, int $radiusKm, string $prefix): array
    {
        $latitudeDelta = $radiusKm / self::KM_PER_DEGREE;
        $longitudeDelta = $radiusKm / (self::KM_PER_DEGREE * max(0.01, cos(deg2rad($latitude))));

        return [
            $prefix.'min_lat' => $latitude - $latitudeDelta,
            $prefix.'max_lat' => $latitude + $latitudeDelta,
            $prefix.'min_lng' => $longitude - $longitudeDelta,
            $prefix.'max_lng' => $longitude + $longitudeDelta,
        ];
    }
}
