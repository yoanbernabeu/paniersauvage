<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Repository\OrderRepository;
use App\Sale\Event\SaleCancelled;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Safety net: cancels the orders still active in a cancelled sale.
 *
 * The cancellation itself is atomic, but an order placed at the very moment the sale is cancelled
 * can still be committed afterwards. Idempotent: run it as often as needed. SaleCancelled is
 * dispatched again for each sale fixed, so that the late customers get the notice too (the
 * notice is sent once per customer and per sale).
 */
final readonly class CancelledSaleReconciler
{
    public function __construct(
        private OrderRepository $orders,
        private SaleOrderCanceller $canceller,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of orders cancelled
     */
    public function reconcile(): int
    {
        $total = 0;
        foreach ($this->orders->findCancelledSalesWithActiveOrders() as $sale) {
            $count = $this->canceller->cancelActiveOrders($sale);
            if (0 === $count) {
                continue;
            }

            $this->logger->warning('{count} order(s) were still active in the cancelled sale "{code}": cancelled.', [
                'count' => $count,
                'code' => $sale->getCode(),
                'sale_id' => $sale->getId(),
            ]);
            $this->eventDispatcher->dispatch(new SaleCancelled($sale));
            $total += $count;
        }

        return $total;
    }
}
