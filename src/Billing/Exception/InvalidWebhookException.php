<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * A webhook call that must be rejected: bad signature or unreadable payload.
 */
final class InvalidWebhookException extends \RuntimeException
{
}
