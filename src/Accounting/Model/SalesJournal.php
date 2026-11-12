<?php

declare(strict_types=1);

namespace App\Accounting\Model;

/**
 * Sales journal of one month for one shop, built from delivered orders only.
 * It describes what was delivered, never what was collected (SPEC §1).
 */
final readonly class SalesJournal
{
    /**
     * @param \DateTimeImmutable $month     first day of the month, at midnight
     * @param list<VatRateRow>   $byVatRate sorted by rate
     * @param list<ProductRow>   $byProduct sorted by label
     * @param list<SaleRow>      $bySale    sorted by delivery date
     * @param list<DetailRow>    $details   one row per sale, product and VAT rate
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public int $orderCount,
        public Amounts $total,
        public array $byVatRate,
        public array $byProduct,
        public array $bySale,
        public array $details,
    ) {
    }

    /**
     * Some sales of the month were made under the franchise en base de TVA: the journal carries
     * the mention VatRegime::FRANCHISE_MENTION.
     */
    public function isVatExempt(): bool
    {
        return [] !== array_filter($this->byVatRate, static fn (VatRateRow $row): bool => $row->vatExempt);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->orderCount;
    }

    public function previousMonth(): \DateTimeImmutable
    {
        return $this->month->modify('first day of previous month');
    }

    public function nextMonth(): \DateTimeImmutable
    {
        return $this->month->modify('first day of next month');
    }
}
