<?php

declare(strict_types=1);

namespace App\Billing\Service;

/**
 * Alert shown at the top of every producer page (templates/billing/_banner.html.twig).
 */
final readonly class BillingBanner
{
    /**
     * @param 'warning'|'destructive' $variant variant of the Alert component
     */
    public function __construct(
        public string $variant,
        public string $title,
        public string $message,
        public string $action,
    ) {
    }
}
