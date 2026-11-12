<?php

declare(strict_types=1);

namespace App\Ordering\Scheduler;

use App\Ordering\Service\CancelledSaleReconciler;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 15 minutes: cancels the orders left active in a cancelled sale (see CancelledSaleReconciler).
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class ReconcileCancelledSalesTask
{
    public function __construct(
        private CancelledSaleReconciler $reconciler,
    ) {
    }

    public function __invoke(): int
    {
        return $this->reconciler->reconcile();
    }
}
