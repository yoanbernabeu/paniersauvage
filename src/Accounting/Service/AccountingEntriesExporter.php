<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Csv\CsvWriter;
use App\Accounting\Entity\AccountingSettings;
use App\Accounting\Model\JournalEntry;

/**
 * Writes the accounting entries of a month in two importable formats:
 *
 * - CSV (`;`, UTF-8 with BOM, decimal comma), the columns most accounting software map on import:
 *   Date, Journal, Compte, Libellé, Débit, Crédit, Pièce;
 * - the 18 columns of the "fichier des écritures comptables" (FEC, article A47 A-1 of the Livre des
 *   procédures fiscales): tab separated, field names on the first line, dates AAAAMMJJ, decimal
 *   comma, UTF-8. It is an import file of draft entries (ValidDate empty), not the FEC of the
 *   business: that one covers every journal of the financial year and is produced by whoever keeps
 *   the books, after validation.
 */
final readonly class AccountingEntriesExporter
{
    public const array CSV_HEADER = ['Date', 'Journal', 'Compte', 'Libellé', 'Débit', 'Crédit', 'Pièce'];

    public const array FEC_HEADER = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib', 'CompAuxNum',
        'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit', 'EcritureLet', 'DateLet',
        'ValidDate', 'Montantdevise', 'Idevise',
    ];

    public const string JOURNAL_LABEL = 'Journal des ventes';

    public function __construct(
        private CsvWriter $csv,
    ) {
    }

    /**
     * @param list<JournalEntry> $entries
     */
    public function csv(array $entries, AccountingSettings $settings): string
    {
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $rows[] = [
                    $entry->date->format('d/m/Y'),
                    $settings->getJournalCode(),
                    $line->account,
                    $line->label,
                    $this->amount($line->debitCents),
                    $this->amount($line->creditCents),
                    $entry->piece,
                ];
            }
        }

        return $this->csv->write(self::CSV_HEADER, $rows);
    }

    /**
     * @param list<JournalEntry> $entries
     */
    public function fec(array $entries, AccountingSettings $settings, \DateTimeImmutable $month): string
    {
        $lines = [implode("\t", self::FEC_HEADER)];

        foreach ($entries as $index => $entry) {
            // Unique within the export, in date order; the accounting software renumbers on import.
            $number = \sprintf('%s%s%04d', $settings->getJournalCode(), $month->format('Ym'), $index + 1);
            $date = $entry->date->format('Ymd');

            foreach ($entry->lines as $line) {
                $lines[] = implode("\t", array_map($this->fecCell(...), [
                    $settings->getJournalCode(),
                    self::JOURNAL_LABEL,
                    $number,
                    $date,
                    $line->account,
                    $line->accountLabel,
                    '',
                    '',
                    $entry->piece,
                    $date,
                    $line->label,
                    $this->csv->amount($line->debitCents),
                    $this->csv->amount($line->creditCents),
                    '',
                    '',
                    '',
                    '',
                    '',
                ]));
            }
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function amount(int $cents): string
    {
        return 0 === $cents ? '' : $this->csv->amount($cents);
    }

    /**
     * A tab or a line break inside a value would shift the columns.
     */
    private function fecCell(string $value): string
    {
        return (string) preg_replace('/[\t\r\n]+/', ' ', $value);
    }
}
