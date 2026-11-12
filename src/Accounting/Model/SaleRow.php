<?php

declare(strict_types=1);

namespace App\Accounting\Model;

final readonly class SaleRow
{
    public function __construct(
        public \DateTimeImmutable $deliveryDate,
        public string $title,
        public string $code,
        public int $orderCount,
        public Amounts $amounts,
    ) {
    }
}
