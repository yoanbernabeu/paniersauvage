<?php

declare(strict_types=1);

namespace App\Accounting\Model;

use App\Catalog\Enum\ProductUnit;

/**
 * A product whose VAT rate changed during the month appears once per rate.
 */
final readonly class ProductRow
{
    public function __construct(
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
