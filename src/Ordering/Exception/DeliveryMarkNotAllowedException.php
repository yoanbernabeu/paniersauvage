<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

/**
 * Thrown when an order cannot be ticked on the delivery sheet (cancelled order, orders not closed
 * yet…). The message is in French and can be shown to the producer.
 */
final class DeliveryMarkNotAllowedException extends \DomainException
{
}
