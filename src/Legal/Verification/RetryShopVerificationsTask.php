<?php

declare(strict_types=1);

namespace App\Legal\Verification;

use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 6 hours: checks again the SIRETs saved while the company register was unreachable.
 */
#[AsPeriodicTask(frequency: '6 hours')]
final readonly class RetryShopVerificationsTask
{
    public function __construct(
        private PendingVerifications $pendingVerifications,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function __invoke(): array
    {
        return $this->pendingVerifications->process();
    }
}
