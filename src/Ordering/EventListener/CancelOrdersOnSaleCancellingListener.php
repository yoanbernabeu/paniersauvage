<?php

declare(strict_types=1);

namespace App\Ordering\EventListener;

use App\Ordering\Service\SaleOrderCanceller;
use App\Sale\Event\SaleCancelling;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A cancelled sale cancels its active orders and releases their stock, in the same transaction
 * as the sale itself (SaleCancelling is dispatched before the commit).
 */
final readonly class CancelOrdersOnSaleCancellingListener
{
    public function __construct(
        private SaleOrderCanceller $canceller,
    ) {
    }

    #[AsEventListener]
    public function __invoke(SaleCancelling $event): void
    {
        $this->canceller->cancelActiveOrders($event->sale);
    }
}
