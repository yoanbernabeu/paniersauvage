<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Catalog\Enum\ProductUnit;
use App\Shared\Service\FrenchFormatter;
use App\Shared\Service\Quantity;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig access to the French display formats. A null value renders as an empty string.
 */
final readonly class FormatExtension
{
    public function __construct(
        private FrenchFormatter $formatter,
    ) {
    }

    /** {{ order.totalCents|money }} → "5,20 €". */
    #[AsTwigFilter('money')]
    public function money(?int $cents): string
    {
        return null === $cents ? '' : $this->formatter->money($cents);
    }

    /**
     * {{ offer.maxQty|quantity(offer.product.unit) }} → "1,5 kg" or "2" (quantities are in thousandths);
     * |quantity(unit, false) → "1,5", next to a unit column.
     */
    #[AsTwigFilter('quantity')]
    public function quantity(?int $quantity, ProductUnit $unit, bool $withUnit = true): string
    {
        return match (true) {
            null === $quantity => '',
            $withUnit => $this->formatter->quantity($quantity, $unit),
            default => Quantity::decimal($quantity),
        };
    }

    /** {{ line.quantity|line_quantity(line.unit) }} {{ line.label }} → "1,5 kg Tomates" or "2 × Baguette". */
    #[AsTwigFilter('line_quantity')]
    public function lineQuantity(?int $quantity, ProductUnit $unit): string
    {
        return null === $quantity ? '' : $this->formatter->lineQuantity($quantity, $unit);
    }

    /** <input type="number" value="{{ quantity|quantity_input }}"> → "1.5". */
    #[AsTwigFilter('quantity_input')]
    public function quantityInput(?int $quantity): string
    {
        return null === $quantity ? '' : Quantity::inputValue($quantity);
    }

    /** {{ product.vatRateBp|vat_rate }} → "5,5 %". */
    #[AsTwigFilter('vat_rate')]
    public function vatRate(?int $basisPoints): string
    {
        return null === $basisPoints ? '' : $this->formatter->vatRate($basisPoints);
    }

    /** {{ sale.deliveryDate|date_long }} → "lundi 16 novembre" ; |date_long(true) adds the year. */
    #[AsTwigFilter('date_long')]
    public function dateLong(?\DateTimeInterface $date, bool $withYear = false): string
    {
        return $date instanceof \DateTimeInterface ? $this->formatter->dateLong($date, $withYear) : '';
    }

    /** {{ sale.deliveryDate|date_compact }} → "lun. 16 nov.". */
    #[AsTwigFilter('date_compact')]
    public function dateCompact(?\DateTimeInterface $date): string
    {
        return $date instanceof \DateTimeInterface ? $this->formatter->dateCompact($date) : '';
    }

    /** {{ sale.deliveryDate|date_short }} → "16/11/2026". */
    #[AsTwigFilter('date_short')]
    public function dateShort(?\DateTimeInterface $date): string
    {
        return $date instanceof \DateTimeInterface ? $this->formatter->dateShort($date) : '';
    }

    /** {{ delivery.startsAt|time_short }} → "18 h" or "18 h 30". */
    #[AsTwigFilter('time_short')]
    public function time(?\DateTimeInterface $time): string
    {
        return $time instanceof \DateTimeInterface ? $this->formatter->time($time) : '';
    }

    /** {{ sale.deadlineAt|datetime_long }} → "vendredi 13 novembre à 20 h". */
    #[AsTwigFilter('datetime_long')]
    public function dateTimeLong(?\DateTimeInterface $date, bool $withYear = false): string
    {
        return $date instanceof \DateTimeInterface ? $this->formatter->dateTimeLong($date, $withYear) : '';
    }

    /** {{ schedule.weekday|weekday_name }} → "lundi". */
    #[AsTwigFilter('weekday_name')]
    public function weekday(int $isoWeekday): string
    {
        return $this->formatter->weekday($isoWeekday);
    }

    /** {{ time_range(delivery.startsAt, delivery.endsAt) }} → "18 h – 19 h". */
    #[AsTwigFunction('time_range')]
    public function timeRange(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        return $this->formatter->timeRange($start, $end);
    }
}
