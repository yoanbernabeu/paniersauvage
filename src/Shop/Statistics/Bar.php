<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

/**
 * One bar of a chart: a week (its Monday) or a month (its first day), and its value.
 */
final readonly class Bar
{
    public function __construct(
        public \DateTimeImmutable $period,
        public int $value,
    ) {
    }

    /**
     * Height of the bar in percent of the tallest one (at least 2 % for a non-zero value, so that it shows).
     *
     * @param list<self> $bars
     */
    public function heightIn(array $bars): int
    {
        if (0 === $this->value) {
            return 0;
        }

        return max(2, intdiv(100 * $this->value, max([$this->value, ...array_map(static fn (self $bar): int => $bar->value, $bars)])));
    }
}
