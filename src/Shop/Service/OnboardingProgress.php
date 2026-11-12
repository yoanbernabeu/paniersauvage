<?php

declare(strict_types=1);

namespace App\Shop\Service;

/**
 * Where a shop stands in its first steps, computed from its real data.
 */
final readonly class OnboardingProgress
{
    public const int TOTAL = 5;

    public function __construct(
        /** The shop page says what it sells and how to pay (description + accepted means of payment). */
        public bool $shopCompleted,
        public bool $hasProduct,
        public bool $hasDeliveryPoint,
        public bool $hasSaleOrSchedule,
        /** The link reached customers: somebody follows the shop or has ordered. */
        public bool $shared,
        /** @var list<OnboardingStep> the five steps then the optional ones, in order, with their screens */
        public array $steps = [],
    ) {
    }

    public function doneCount(): int
    {
        return \count(array_filter([$this->shopCompleted, $this->hasProduct, $this->hasDeliveryPoint, $this->hasSaleOrSchedule, $this->shared]));
    }

    public function total(): int
    {
        return self::TOTAL;
    }

    public function isComplete(): bool
    {
        return self::TOTAL === $this->doneCount();
    }

    /**
     * The first required step left to do, or null when they are all done.
     */
    public function nextStep(): ?OnboardingStep
    {
        foreach ($this->steps as $step) {
            if (!$step->done && !$step->optional) {
                return $step;
            }
        }

        return null;
    }
}
