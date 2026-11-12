<?php

declare(strict_types=1);

namespace App\Ordering\Model;

use App\Ordering\Enum\SheetActionOutcome;

/**
 * What became of an action of the delivery sheet. `replayed`: the action had already been
 * processed, this is the outcome recorded then (nothing changed this time).
 */
final readonly class SheetActionResult
{
    public function __construct(
        public SheetActionOutcome $outcome,
        public ?string $message = null,
        public bool $replayed = false,
    ) {
    }
}
