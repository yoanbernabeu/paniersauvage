<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Catalog\Enum\ProductUnit;

/**
 * Finest grain of the journal: one sale, one product, one VAT rate.
 */
final readonly class DetailRow
{
    public function __construct(
        public \DateTimeImmutable $deliveryDate,
        public string $saleTitle,
        public string $saleCode,
        public string $label,
        public int $vatRateBp,
        public Amounts $amounts,
        /** Unit of the quantity in `$amounts` (thousandths of it). */
        public ProductUnit $unit,
        /** Sales made under the franchise en base de TVA (art. 293 B du CGI): no VAT, legal mention. */
        public bool $vatExempt,
    ) {
    }
}
