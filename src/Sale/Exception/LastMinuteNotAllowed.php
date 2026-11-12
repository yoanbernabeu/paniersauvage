<?php

declare(strict_types=1);

namespace App\Sale\Exception;

/**
 * A last-minute sale that cannot be opened. The message is written for the producer (French).
 */
final class LastMinuteNotAllowed extends \DomainException
{
}
