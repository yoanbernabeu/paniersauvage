<?php

declare(strict_types=1);

namespace App\Accounting\Model;

/**
 * The accounting entry of one delivered order in the sales journal: the counterpart account is
 * debited with the amount due, sales (by VAT rate) and VAT collected are credited. Balanced.
 */
final readonly class JournalEntry
{
    /**
     * @param string          $piece order reference (PieceRef)
     * @param list<EntryLine> $lines
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public string $piece,
        public array $lines,
        public bool $vatExempt,
    ) {
    }

    public function debitCents(): int
    {
        return array_sum(array_map(static fn (EntryLine $line): int => $line->debitCents, $this->lines));
    }

    public function creditCents(): int
    {
        return array_sum(array_map(static fn (EntryLine $line): int => $line->creditCents, $this->lines));
    }
}
