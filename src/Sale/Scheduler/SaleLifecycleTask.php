<?php

declare(strict_types=1);

namespace App\Sale\Scheduler;

use App\Sale\Service\SaleLifecycle;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 5 minutes: opens, closes and finishes the sales that are due.
 */
#[AsPeriodicTask(frequency: '5 minutes')]
final readonly class SaleLifecycleTask
{
    public function __construct(
        private SaleLifecycle $lifecycle,
    ) {
    }

    /**
     * @return array{opened: int, closed: int, finished: int, missed: int}
     */
    public function __invoke(): array
    {
        $closed = $this->lifecycle->closeDueSales();
        $missed = $this->lifecycle->cancelMissedSales();
        $opened = $this->lifecycle->openDueSales();
        $finished = $this->lifecycle->finishDueSales();

        return ['opened' => $opened, 'closed' => $closed, 'finished' => $finished, 'missed' => $missed];
    }
}
