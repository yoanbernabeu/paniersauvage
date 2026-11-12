<?php

declare(strict_types=1);

namespace App\Billing\Service;

/**
 * Rules of the solidarity plan: pay what you can, in whole euros from 0 to 10 € a month (Stripe
 * cannot charge less than 0,50 €, so no cents), 5 € suggested. At 0 € there is no Stripe
 * subscription at all: the plan is active for 12 months and renewed by a new declaration.
 */
final class SolidarityPlan
{
    public const int MIN_EUROS = 0;
    public const int MAX_EUROS = 10;
    public const int SUGGESTED_EUROS = 5;

    /** Length of a free (0 €) solidarity period. */
    public const int FREE_PERIOD_MONTHS = 12;

    /** The renewal reminder leaves this many days before the end of a free period. */
    public const int REMINDER_DAYS = 30;

    public static function isValidAmount(int $cents): bool
    {
        return \in_array($cents, range(self::MIN_EUROS * 100, self::MAX_EUROS * 100, 100), true);
    }
}
