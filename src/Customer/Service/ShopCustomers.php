<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Customer\Enum\CustomerSort;
use App\Customer\Model\ShopCustomer;
use App\Ordering\Enum\OrderStatus;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Connection;

/**
 * The customers of a shop ("Mes clients", SPEC §2): whoever ordered there, follows it, or was
 * entered without e-mail by the producer; nobody else. Every figure is computed in SQL on this
 * shop's orders only: a producer never learns anything of the customer's orders elsewhere.
 *
 * @phpstan-import-type ShopCustomerRow from ShopCustomer
 */
final readonly class ShopCustomers
{
    /**
     * The names and phone come from the customer's latest order here (what they typed for this
     * producer), else from their profile.
     */
    private const string SQL = <<<'SQL'
        WITH shop_orders AS (
            SELECT o.id, o.customer_id, o.status, o.total_cents, o.created_at, s.delivery_date,
                   o.customer_first_name, o.customer_last_name, o.customer_phone
            FROM customer_order o
            JOIN sale s ON s.id = o.sale_id
            WHERE s.shop_id = :shop
        ),
        stats AS (
            SELECT customer_id,
                   count(*) FILTER (WHERE status <> :cancelled)                    AS order_count,
                   max(delivery_date) FILTER (WHERE status <> :cancelled)          AS last_order_date,
                   coalesce(sum(total_cents) FILTER (WHERE status = :delivered), 0) AS delivered_cents,
                   count(*) FILTER (WHERE status = :absent)                        AS absences
            FROM shop_orders
            GROUP BY customer_id
        ),
        latest AS (
            SELECT DISTINCT ON (customer_id) customer_id, customer_first_name, customer_last_name, customer_phone
            FROM shop_orders
            ORDER BY customer_id, created_at DESC, id DESC
        ),
        members AS (
            SELECT customer_id FROM stats
            UNION SELECT customer_id FROM shop_follower WHERE shop_id = :shop
            UNION SELECT id FROM customer WHERE owner_shop_id = :shop
        ),
        shop_customers AS (
            SELECT c.id,
                   c.email,
                   c.owner_shop_id IS NOT NULL AS without_email,
                   CASE WHEN l.customer_first_name IS NOT NULL OR l.customer_last_name IS NOT NULL THEN l.customer_first_name ELSE c.first_name END AS first_name,
                   CASE WHEN l.customer_first_name IS NOT NULL OR l.customer_last_name IS NOT NULL THEN l.customer_last_name ELSE c.last_name END AS last_name,
                   coalesce(l.customer_phone, c.phone)  AS phone,
                   f.id IS NOT NULL                     AS follower,
                   coalesce(f.email_consent, false)     AS email_consent,
                   f.created_at                         AS followed_at,
                   coalesce(st.order_count, 0)          AS order_count,
                   st.last_order_date,
                   coalesce(st.delivered_cents, 0)      AS delivered_cents,
                   coalesce(st.absences, 0)             AS absences
            FROM members m
            JOIN customer c ON c.id = m.customer_id
            LEFT JOIN stats st ON st.customer_id = c.id
            LEFT JOIN latest l ON l.customer_id = c.id
            LEFT JOIN shop_follower f ON f.customer_id = c.id AND f.shop_id = :shop
        )
        SELECT * FROM shop_customers
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param string $search part of a name, of an e-mail address, or at least three digits of a phone
     *
     * @return list<ShopCustomer>
     */
    public function list(Shop $shop, string $search = '', CustomerSort $sort = CustomerSort::Name): array
    {
        [$where, $parameters] = $this->searchClause($search);

        return $this->fetch(
            self::SQL.' WHERE '.$where.' ORDER BY '.$sort->orderBy().', '.CustomerSort::BY_NAME,
            ['shop' => $shop->getId(), ...$parameters],
        );
    }

    /**
     * The customer, when they are a customer of this shop; null otherwise (producer isolation).
     */
    public function find(Shop $shop, int $id): ?ShopCustomer
    {
        return $this->fetch(self::SQL.' WHERE id = :id', ['shop' => $shop->getId(), 'id' => $id])[0] ?? null;
    }

    /**
     * An empty search matches everybody (`LIKE '%%'`); the phone is searched from three digits on.
     *
     * @return array{string, array<string, string>}
     */
    private function searchClause(string $search): array
    {
        $search = mb_strtolower(trim($search));
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        // "Prénom Nom Prénom": "Martin Léa" finds Léa Martin as well as "Léa Martin".
        return [
            "(lower(concat_ws(' ', first_name, last_name, first_name, email)) LIKE :like"
            ." OR (CAST(:phone AS boolean) AND regexp_replace(coalesce(phone, ''), '\\D', '', 'g') LIKE :digits))",
            [
                'like' => '%'.addcslashes($search, '%_\\').'%',
                'phone' => \strlen($digits) >= 3 ? 'true' : 'false',
                'digits' => '%'.$digits.'%',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<ShopCustomer>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $parameters += [
            'cancelled' => OrderStatus::Cancelled->value,
            'delivered' => OrderStatus::Delivered->value,
            'absent' => OrderStatus::NotDelivered->value,
        ];

        /** @var list<ShopCustomerRow> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);

        return array_map(ShopCustomer::fromRow(...), $rows);
    }
}
