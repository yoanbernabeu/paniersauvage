<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Shop\Entity\Shop;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * "2 absences": how many times a customer did not come for an order (`not_delivered`) at this
 * producer lately, shown discreetly next to their name on the producer's screens. Only the shop's
 * own orders count, never another producer's (SPEC §2).
 *
 * One query per shop and request (the absences of the whole shop, a few rows), whatever the number
 * of orders on the page.
 */
final class AbsenceHistory implements ResetInterface
{
    /** Absences older than this are forgotten: a customer is not labelled forever. */
    public const int WINDOW_DAYS = 180;

    /** @var array<int, array<int, list<int>>> shop id => customer id => sale ids of the absences */
    private array $absences = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Absences of the order's customer at its shop in the last WINDOW_DAYS days, the order's own
     * sale left out (the sheet being filled in is not "history").
     */
    public function recentFor(Order $order): int
    {
        $saleId = $order->getSale()->getId();
        $sales = $this->load($order->getShop())[$order->getCustomer()->getId() ?? 0] ?? [];

        return \count(array_filter($sales, static fn (int $id): bool => $id !== $saleId));
    }

    public function reset(): void
    {
        $this->absences = [];
    }

    /**
     * @return array<int, list<int>>
     */
    private function load(Shop $shop): array
    {
        $shopId = $shop->getId() ?? 0;
        if (isset($this->absences[$shopId])) {
            return $this->absences[$shopId];
        }

        $since = $this->clock->now()->modify(\sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d');

        /** @var list<array{customer_id: int, sale_id: int}> $rows integer columns come back as PHP integers */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.customer_id, o.sale_id
             FROM customer_order o
             JOIN sale s ON s.id = o.sale_id
             WHERE s.shop_id = :shop AND o.status = :absent AND s.delivery_date >= :since',
            ['shop' => $shopId, 'absent' => OrderStatus::NotDelivered->value, 'since' => $since],
        );

        $absences = [];
        foreach ($rows as $row) {
            $absences[$row['customer_id']][] = $row['sale_id'];
        }

        return $this->absences[$shopId] = $absences;
    }
}
