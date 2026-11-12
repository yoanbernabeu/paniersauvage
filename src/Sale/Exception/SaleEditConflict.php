<?php

declare(strict_types=1);

namespace App\Sale\Exception;

/**
 * A sale edition that cannot be saved. The message is written for the producer (French, shown as a form error).
 */
final class SaleEditConflict extends \DomainException
{
}
