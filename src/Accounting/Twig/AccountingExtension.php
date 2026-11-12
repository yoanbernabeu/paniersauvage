<?php

declare(strict_types=1);

namespace App\Accounting\Twig;

use App\Kernel;
use Twig\Attribute\AsTwigFilter;

final class AccountingExtension
{
    /** {{ journal.month|month_label }} → "novembre 2026". */
    #[AsTwigFilter('month_label')]
    public function monthLabel(\DateTimeInterface $month): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, Kernel::TIMEZONE, \IntlDateFormatter::GREGORIAN, 'LLLL y');
        $formatted = $formatter->format($month);

        if (false === $formatted) {
            throw new \RuntimeException('Unable to format a month.');
        }

        return $formatted;
    }
}
