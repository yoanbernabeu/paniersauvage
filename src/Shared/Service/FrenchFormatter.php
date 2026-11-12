<?php

declare(strict_types=1);

namespace App\Shared\Service;

use App\Catalog\Enum\ProductUnit;
use App\Kernel;

/**
 * French display formats for amounts, dates and times (screens, e-mails, PDF).
 * Dates are always shown in the Europe/Paris timezone.
 */
final class FrenchFormatter
{
    private const string NBSP = "\u{00A0}";
    private const string NARROW_NBSP = "\u{202F}";
    private const array WEEKDAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /**
     * 520 → "5,20 €" (amount in cents).
     */
    public function money(int $cents): string
    {
        $formatted = number_format(abs($cents) / 100, 2, ',', self::NARROW_NBSP);

        return ($cents < 0 ? '-' : '').$formatted.self::NBSP.'€';
    }

    /**
     * Quantity in thousandths of the unit: 1500 kg → "1,5 kg", 2000 pieces → "2".
     */
    public function quantity(int $quantity, ProductUnit $unit): string
    {
        $formatted = Quantity::decimal($quantity);

        return $unit->isWeighed() ? $formatted.self::NBSP.'kg' : $formatted;
    }

    /**
     * Quantity in front of a product name: "1,5 kg" (by weight) or "2 ×".
     */
    public function lineQuantity(int $quantity, ProductUnit $unit): string
    {
        return $unit->isWeighed() ? $this->quantity($quantity, $unit) : Quantity::decimal($quantity).' ×';
    }

    /**
     * 550 → "5,5 %" (rate in basis points).
     */
    public function vatRate(int $basisPoints): string
    {
        $formatted = rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');

        return $formatted.self::NBSP.'%';
    }

    /**
     * "lundi 16 novembre", or "lundi 16 novembre 2026" with the year.
     */
    public function dateLong(\DateTimeInterface $date, bool $withYear = false): string
    {
        $date = $this->local($date);
        $day = '1' === $date->format('j') ? '1er' : $date->format('j');

        return \sprintf('%s %s %s', $this->intl($date, 'EEEE'), $day, $this->intl($date, $withYear ? 'MMMM y' : 'MMMM'));
    }

    /**
     * "lun. 16 nov." — for dense lists.
     */
    public function dateCompact(\DateTimeInterface $date): string
    {
        return $this->intl($this->local($date), 'EEE d MMM');
    }

    /**
     * "16/11/2026".
     */
    public function dateShort(\DateTimeInterface $date): string
    {
        return $this->local($date)->format('d/m/Y');
    }

    /**
     * "18 h" or "18 h 30".
     */
    public function time(\DateTimeInterface $time): string
    {
        $time = $this->local($time);
        $minutes = $time->format('i');

        return $time->format('G').self::NBSP.'h'.('00' === $minutes ? '' : self::NBSP.$minutes);
    }

    /**
     * "18 h – 19 h".
     */
    public function timeRange(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        return $this->time($start).' – '.$this->time($end);
    }

    /**
     * "vendredi 13 novembre à 20 h".
     */
    public function dateTimeLong(\DateTimeInterface $date, bool $withYear = false): string
    {
        return $this->dateLong($date, $withYear).' à '.$this->time($date);
    }

    /**
     * ISO weekday (1 = Monday … 7 = Sunday) → "lundi".
     */
    public function weekday(int $isoWeekday): string
    {
        return self::WEEKDAYS[$isoWeekday] ?? throw new \InvalidArgumentException(\sprintf('Invalid ISO weekday "%d".', $isoWeekday));
    }

    private function local(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone(Kernel::TIMEZONE));
    }

    private function intl(\DateTimeImmutable $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, Kernel::TIMEZONE, \IntlDateFormatter::GREGORIAN, $pattern);
        $formatted = $formatter->format($date);

        if (false === $formatted) {
            throw new \RuntimeException(\sprintf('Unable to format a date with pattern "%s".', $pattern));
        }

        return $formatted;
    }
}
