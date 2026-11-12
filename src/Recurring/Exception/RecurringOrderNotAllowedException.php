<?php

declare(strict_types=1);

namespace App\Recurring\Exception;

/**
 * A subscription action refused by the rules of the plan. The message is in French, shown as is.
 */
final class RecurringOrderNotAllowedException extends \DomainException
{
}
