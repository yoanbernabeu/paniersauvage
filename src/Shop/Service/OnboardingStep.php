<?php

declare(strict_types=1);

namespace App\Shop\Service;

/**
 * One of the first steps of a shop, with the screen that completes it: five to take a first
 * pre-order, plus optional ones (the producer directory) that never count in the progress.
 */
final readonly class OnboardingStep
{
    public function __construct(
        /** shop, product, delivery_point, sale, share; directory (optional). */
        public string $key,
        /** Position in the list, from 1. */
        public int $number,
        public bool $done,
        public string $title,
        public string $text,
        public ?string $url = null,
        public ?string $action = null,
        public ?string $secondUrl = null,
        public ?string $secondAction = null,
        /** Not needed to take orders: left out of the count and of the "next step". */
        public bool $optional = false,
    ) {
    }
}
