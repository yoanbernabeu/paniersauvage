<?php

declare(strict_types=1);

namespace App\Accounting\Model;

/**
 * One VAT rate of the month. Sales under the franchise en base de TVA have their own row (rate 0).
 */
final readonly class VatRateRow
{
    public function __construct(
        public int $vatRateBp,
        public Amounts $amounts,
        /** Sales made under the franchise en base de TVA (art. 293 B du CGI): no VAT, legal mention. */
        public bool $vatExempt,
    ) {
    }
}
