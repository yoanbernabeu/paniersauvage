<?php

declare(strict_types=1);

namespace App\Accounting\Csv;

use App\Shared\Service\Quantity;

/**
 * Builds CSV files that open directly in Excel (French locale): `;` separator, UTF-8 with BOM,
 * CRLF line endings, decimal comma.
 *
 * Every cell is protected against formula injection: a value starting with `=`, `+`, `-`, `@`,
 * a tab or a carriage return is prefixed with an apostrophe, so a spreadsheet never evaluates
 * what a customer typed (OWASP "CSV injection"). Plain numbers ("-12,50") are kept as is.
 */
final class CsvWriter
{
    public const string BOM = "\u{FEFF}";
    public const string SEPARATOR = ';';

    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    public function write(array $header, iterable $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to open a temporary stream for the CSV export.');
        }

        $this->put($stream, $header);
        foreach ($rows as $row) {
            $this->put($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if (false === $csv) {
            throw new \RuntimeException('Unable to read the CSV export back.');
        }

        return self::BOM.$csv;
    }

    /**
     * 1250 → "12,50": no currency sign and no thousands separator, so Excel reads a number.
     */
    public function amount(int $cents): string
    {
        return \sprintf('%s%d,%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    /**
     * 1500 → "1,5" (quantity in thousandths of the unit).
     */
    public function quantity(int $quantity): string
    {
        return Quantity::decimal($quantity);
    }

    /**
     * 550 → "5,5" (VAT rate in basis points, as a percentage).
     */
    public function rate(int $basisPoints): string
    {
        $decimals = abs($basisPoints) % 100;
        $formatted = \sprintf('%s%d', $basisPoints < 0 ? '-' : '', intdiv(abs($basisPoints), 100));

        return 0 === $decimals ? $formatted : $formatted.','.rtrim(\sprintf('%02d', $decimals), '0');
    }

    /**
     * Neutralises a cell that a spreadsheet would evaluate as a formula.
     */
    public function sanitize(string $value): string
    {
        if ('' === $value || 1 === preg_match('/^-?\d+(,\d+)?$/D', $value)) {
            return $value;
        }

        return \in_array($value[0], self::FORMULA_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * @param resource              $stream
     * @param list<string|int|null> $row
     */
    private function put($stream, array $row): void
    {
        $cells = array_map(fn (string|int|null $cell): string => $this->sanitize((string) $cell), $row);

        if (false === fputcsv($stream, $cells, self::SEPARATOR, '"', '', "\r\n")) {
            throw new \RuntimeException('Unable to write a CSV row.');
        }
    }
}
