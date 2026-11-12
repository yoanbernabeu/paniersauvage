<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

/**
 * A share (`part` out of `whole`), as a whole percentage rounded half up: no float.
 */
final readonly class Rate
{
    public function __construct(
        public int $part,
        public int $whole,
    ) {
    }

    /**
     * Null when there is nothing to compare with (no "0 %" made up from nothing).
     */
    public function percent(): ?int
    {
        return $this->whole > 0 ? intdiv(200 * $this->part + $this->whole, 2 * $this->whole) : null;
    }
}
