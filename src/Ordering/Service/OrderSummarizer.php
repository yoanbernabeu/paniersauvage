<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Ordering\Model\DeliverySheet;

/**
 * Pure computations on a list of orders for the producer screens (no database access).
 */
final class OrderSummarizer
{
    /**
     * Splits the orders of one delivery into "to deliver" and "delivered", and sums the truck load.
     * Orders marked "not delivered" go to the bottom of the list still to deliver.
     *
     * @param list<Order> $orders active orders of one delivery
     */
    public function deliverySheet(array $orders): DeliverySheet
    {
        $todo = [];
        $done = [];
        foreach ($orders as $order) {
            if ($order->isDelivered()) {
                $done[] = $order;
            } else {
                $todo[] = $order;
            }
        }
        usort($todo, static fn (Order $a, Order $b): int => (int) !$a->isConfirmed() <=> (int) !$b->isConfirmed());

        return new DeliverySheet($todo, $done, $this->truckLoad($orders));
    }

    /**
     * Amount the customers will settle with the producer, all orders together (cancelled ones excluded).
     *
     * @param list<Order> $orders
     */
    public function totalCents(array $orders): int
    {
        $total = 0;
        foreach ($orders as $order) {
            if ($order->isActive()) {
                $total += $order->getTotalCents();
            }
        }

        return $total;
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<array{offer: int, label: string, unit: ProductUnit, total: int, remaining: int}> quantities in thousandths
     */
    private function truckLoad(array $orders): array
    {
        $load = [];
        foreach ($orders as $order) {
            foreach ($order->getLines() as $line) {
                $key = spl_object_id($line->getSaleOffer());
                $load[$key] ??= ['offer' => (int) $line->getSaleOffer()->getId(), 'label' => $line->getLabel(), 'unit' => $line->getUnit(), 'total' => 0, 'remaining' => 0];
                $load[$key]['total'] += $line->getQuantity();
                if (!$order->isDelivered()) {
                    $load[$key]['remaining'] += $line->getQuantity();
                }
            }
        }

        return array_values($load);
    }
}
