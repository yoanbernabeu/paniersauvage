<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Delivery\Entity\DeliveryPoint;
use App\Shop\Enum\SearchOutcome;
use App\Shop\Repository\SearchStatRepository;
use Psr\Clock\ClockInterface;

/**
 * Counts the searches of the producer directory per day, department and outcome (SPEC §11).
 * Sober on purpose: no IP, no cookie, no searched text, only the department of the place found
 * (or of a typed postcode).
 */
final readonly class SearchCounter
{
    public function __construct(
        private SearchStatRepository $stats,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param ?string $postcode postcode of the place found, null for a search by position
     * @param ?string $typed    what the visitor typed (trimmed), read only when it is a postcode
     */
    public function count(SearchOutcome $outcome, ?string $postcode, ?string $typed = null): void
    {
        $postcode ??= null !== $typed && 1 === preg_match('/^\d{5}$/', $typed) ? $typed : null;
        $department = null === $postcode ? '' : DeliveryPoint::departmentOf($postcode);

        $this->stats->record($this->clock->now(), $department, $outcome);
    }
}
