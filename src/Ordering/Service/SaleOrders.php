<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shop\Entity\Shop;

/**
 * Read-only views of the orders of a sale, for the producer screens. Orders themselves are
 * created and edited elsewhere (Ordering domain).
 */
final readonly class SaleOrders
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    /**
     * Orders that count (not cancelled), sorted by customer name.
     *
     * @return list<Order>
     */
    public function forSale(Sale $sale): array
    {
        return $this->orders->findBySale($sale);
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<Order>
     */
    public function forDelivery(SaleDelivery $delivery, array $orders): array
    {
        return array_values(array_filter($orders, static fn (Order $order): bool => $order->getSaleDelivery() === $delivery));
    }

    public function findInShop(int $id, Shop $shop): ?Order
    {
        $order = $this->orders->find($id);

        return null !== $order && $order->getShop() === $shop ? $order : null;
    }

    /**
     * Number of orders that count, per sale id (sales without order are absent).
     *
     * @param list<Sale> $sales
     *
     * @return array<int, int>
     */
    public function countBySale(array $sales): array
    {
        return [] === $sales ? [] : $this->orders->countActiveBySale($sales);
    }

    /**
     * Quantity to produce per offer, in the order of the sale's offers (offers nobody ordered included).
     *
     * @param list<Order> $orders
     *
     * @return list<array{offer: SaleOffer, quantity: int}>
     */
    public function productionTotals(Sale $sale, array $orders): array
    {
        $totals = [];
        foreach ($sale->getOffers() as $offer) {
            $totals[spl_object_id($offer)] = ['offer' => $offer, 'quantity' => 0];
        }

        foreach ($orders as $order) {
            if (!$order->isActive()) {
                continue;
            }

            foreach ($order->getLines() as $line) {
                $offer = $line->getSaleOffer();
                $totals[spl_object_id($offer)] ??= ['offer' => $offer, 'quantity' => 0];
                $totals[spl_object_id($offer)]['quantity'] += $line->getQuantity();
            }
        }

        return array_values($totals);
    }

    /**
     * Ids of the offers referenced by at least one order line, cancelled orders included: they cannot be removed.
     *
     * @return list<int>
     */
    public function orderedOfferIds(Sale $sale): array
    {
        return $this->orders->findOrderedOfferIds($sale);
    }

    /**
     * Orders per delivery of the sale (deliveries without order are absent): `all` includes the
     * cancelled orders (the delivery cannot be removed), `active` only the ones that count (the
     * customers expect this time window).
     *
     * @return array<int, array{all: int, active: int}> delivery id => counts
     */
    public function countByDelivery(Sale $sale): array
    {
        return $this->orders->countByDelivery($sale);
    }
}
