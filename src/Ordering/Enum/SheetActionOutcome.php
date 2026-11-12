<?php

declare(strict_types=1);

namespace App\Ordering\Enum;

/**
 * What became of an action of the delivery sheet (App\Ordering\Service\DeliverySheetSync).
 */
enum SheetActionOutcome: string
{
    /** Saved: the order changed and its history has a new entry. */
    case Applied = 'applied';
    /** The order was already in the requested state: nothing to save. */
    case Unchanged = 'unchanged';
    /** Refused: the order was cancelled or changed elsewhere in the meantime. */
    case Conflict = 'conflict';
}
