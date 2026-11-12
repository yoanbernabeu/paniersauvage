<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Csv\CsvWriter;
use App\Accounting\Model\Amounts;
use App\Accounting\Model\SalesJournal;
use App\Shared\Service\FrenchFormatter;
use App\Shop\Enum\VatRegime;

/**
 * Monthly journal as CSV: one row per sale, product and VAT rate, then one total row per VAT
 * rate and a grand total. Total rows have "Total" in the first column, no sale and no quantity
 * (it would add up pieces and kilograms). Quantities are decimals ("1,5"), in the unit of the
 * "Unité" column. Sales under the franchise en base de TVA carry the legal mention in the last column.
 */
final readonly class SalesJournalCsvExporter
{
    public const array HEADER = [
        'Type', 'Date de livraison', 'Vente', 'Code vente', 'Produit', 'Taux de TVA (%)',
        'Quantité', 'Unité', 'CA TTC', 'CA HT', 'TVA', 'Mention TVA',
    ];

    public function __construct(
        private CsvWriter $csv,
        private FrenchFormatter $formatter,
    ) {
    }

    public function export(SalesJournal $journal): string
    {
        $rows = [];
        foreach ($journal->details as $detail) {
            $rows[] = [
                'Détail',
                $this->formatter->dateShort($detail->deliveryDate),
                $detail->saleTitle,
                $detail->saleCode,
                $detail->label,
                $this->csv->rate($detail->vatRateBp),
                $this->csv->quantity($detail->amounts->quantity),
                $detail->unit->short(),
                ...$this->amounts($detail->amounts),
                $detail->vatExempt ? VatRegime::FRANCHISE_MENTION : '',
            ];
        }

        foreach ($journal->byVatRate as $row) {
            $rows[] = $row->vatExempt
                ? ['Total', '', '', '', 'Total franchise en base de TVA', $this->csv->rate($row->vatRateBp), '', '', ...$this->amounts($row->amounts), VatRegime::FRANCHISE_MENTION]
                : ['Total', '', '', '', 'Total TVA '.$this->formatter->vatRate($row->vatRateBp), $this->csv->rate($row->vatRateBp), '', '', ...$this->amounts($row->amounts), ''];
        }
        $rows[] = ['Total', '', '', '', 'Total du mois', '', '', '', ...$this->amounts($journal->total), ''];

        return $this->csv->write(self::HEADER, $rows);
    }

    /**
     * @return list<string>
     */
    private function amounts(Amounts $amounts): array
    {
        return [
            $this->csv->amount($amounts->grossCents),
            $this->csv->amount($amounts->netCents),
            $this->csv->amount($amounts->vatCents),
        ];
    }
}
