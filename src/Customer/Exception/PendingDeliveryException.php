<?php

declare(strict_types=1);

namespace App\Customer\Exception;

/**
 * The account cannot be erased yet: a producer still has an order to deliver to this customer.
 */
final class PendingDeliveryException extends \DomainException
{
}
