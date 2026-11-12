<?php

declare(strict_types=1);

namespace App\Accounting\Model;

/**
 * One line of an accounting entry: an account debited or credited, in cents (one side is 0).
 */
final readonly class EntryLine
{
    public function __construct(
        public string $account,
        /** Name of the account for the FEC format (CompteLib). */
        public string $accountLabel,
        public string $label,
        public int $debitCents,
        public int $creditCents,
    ) {
    }
}
