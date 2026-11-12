<?php

declare(strict_types=1);

namespace App\Accounting\Model;

/**
 * A quantity and its VAT-inclusive, net and VAT amounts, in cents. Gross = net + VAT, always.
 * The quantity is in thousandths of the unit (App\Shared\Service\Quantity): it only means
 * something for one product; totals over several products show the amounts only.
 */
final readonly class Amounts
{
    public function __construct(
        public int $quantity = 0,
        public int $grossCents = 0,
        public int $netCents = 0,
        public int $vatCents = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $this->quantity + $other->quantity,
            $this->grossCents + $other->grossCents,
            $this->netCents + $other->netCents,
            $this->vatCents + $other->vatCents,
        );
    }
}
