<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

/**
 * Thrown when an order cannot be placed, edited or cancelled: sale not open, deadline passed,
 * invalid delivery or quantities… The message is in French and can be shown to the user.
 */
final class OrderNotAllowedException extends \DomainException
{
}
