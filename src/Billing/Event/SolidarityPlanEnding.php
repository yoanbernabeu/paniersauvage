<?php

declare(strict_types=1);

namespace App\Billing\Event;

use App\Billing\Entity\ShopSubscription;

/**
 * A free (0 €) solidarity period ends within 30 days: the producer is reminded to renew their
 * declaration (e-mail sent by the Notification domain). Dispatched once per period.
 */
final readonly class SolidarityPlanEnding
{
    public function __construct(
        public ShopSubscription $subscription,
    ) {
    }
}
