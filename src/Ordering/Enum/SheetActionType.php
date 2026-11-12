<?php

declare(strict_types=1);

namespace App\Ordering\Enum;

/**
 * What the producer did on the delivery sheet, replayed by App\Ordering\Service\DeliverySheetSync.
 */
enum SheetActionType: string
{
    /** Delivered, not delivered, or back to deliver. */
    case Mark = 'mark';
    /** Actual weights of the products sold by weight. */
    case Weigh = 'weigh';
}
