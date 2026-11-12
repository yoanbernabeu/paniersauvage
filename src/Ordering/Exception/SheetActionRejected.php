<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

/**
 * An action of the delivery sheet that can never be applied (a product that is not part of the
 * order, a weight out of range, an identifier already used for another order): the browser drops it.
 * The message is shown to the producer.
 */
final class SheetActionRejected extends \RuntimeException
{
}
