<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Ordering\Repository\OrderRepository;
use App\Sale\Entity\Sale;

final readonly class SaleSummaryBuilder
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    /**
     * Cancelled orders are left out.
     */
    public function build(Sale $sale): SaleSummary
    {
        $orders = $this->orders->findBySale($sale);

        $totalCents = 0;
        $production = [];
        $groups = [];

        // Keep the delivery slots in the order chosen by the producer.
        foreach ($sale->getDeliveries() as $delivery) {
            $groups[spl_object_id($delivery)] = ['delivery' => $delivery, 'orders' => []];
        }

        foreach ($orders as $order) {
            $totalCents += $order->getTotalCents();

            $delivery = $order->getSaleDelivery();
            $groups[spl_object_id($delivery)] ??= ['delivery' => $delivery, 'orders' => []];
            $groups[spl_object_id($delivery)]['orders'][] = $order;

            foreach ($order->getLines() as $line) {
                $key = spl_object_id($line->getSaleOffer());
                $production[$key] ??= ['label' => $line->getLabel(), 'unit' => $line->getUnit(), 'quantity' => 0];
                $production[$key]['quantity'] += $line->getQuantity();
            }
        }

        usort($production, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return new SaleSummary(
            \count($orders),
            $totalCents,
            $production,
            array_values(array_filter($groups, static fn (array $group): bool => [] !== $group['orders'])),
        );
    }
}
