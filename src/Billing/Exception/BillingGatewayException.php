<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * The billing provider could not be reached or refused a request.
 */
final class BillingGatewayException extends \RuntimeException
{
}
