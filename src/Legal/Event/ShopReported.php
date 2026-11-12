<?php

declare(strict_types=1);

namespace App\Legal\Event;

use App\Legal\Enum\ReportReason;
use App\Shop\Entity\Shop;

/**
 * A visitor reported a shop (shop_report): the operator is told (LEGAL_CONTACT_EMAIL).
 */
final readonly class ShopReported
{
    public function __construct(
        public Shop $shop,
        public ReportReason $reason,
        public string $message,
        public ?string $reporterEmail,
        public \DateTimeImmutable $reportedAt,
    ) {
    }
}
