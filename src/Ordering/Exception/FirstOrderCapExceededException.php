<?php

declare(strict_types=1);

namespace App\Ordering\Exception;

/**
 * A new customer's order goes beyond the cap the producer set for a first order
 * (`Shop::getFirstOrderCapCents()`). The French message is polite and gives the producer's contact.
 */
final class FirstOrderCapExceededException extends \DomainException
{
}
