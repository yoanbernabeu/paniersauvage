<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Csv\CsvWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    public function testWritesAnExcelFriendlyFile(): void
    {
        $csv = new CsvWriter()->write(['Nom', 'Total TTC'], [
            ['Élodie Dupré', '12,50'],
            ['Texte ; avec "guillemets"', null],
            ['Quantité', 3],
        ]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertSame(
            "Nom;\"Total TTC\"\r\n\"Élodie Dupré\";12,50\r\n\"Texte ; avec \"\"guillemets\"\"\";\r\nQuantité;3\r\n",
            substr($csv, 3),
        );
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCells(): iterable
    {
        yield 'formula' => ['=HYPERLINK("http://evil.test";"clic")', '\'=HYPERLINK("http://evil.test";"clic")'];
        yield 'plus' => ['+33 6 12 34 56 78', "'+33 6 12 34 56 78"];
        yield 'minus formula' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1:A2)', "'@SUM(A1:A2)"];
        yield 'tab' => ["\t=1+1", "'\t=1+1"];
        yield 'carriage return' => ["\r=1+1", "'\r=1+1"];
        yield 'negative amount stays a number' => ['-12,50', '-12,50'];
        yield 'negative integer stays a number' => ['-3', '-3'];
        yield 'number followed by a line break is text' => ["-3\n", "'-3\n"];
        yield 'plain text' => ['Sans gluten = non', 'Sans gluten = non'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('provideCells')]
    public function testNeutralisesFormulaInjection(string $cell, string $expected): void
    {
        self::assertSame($expected, new CsvWriter()->sanitize($cell));
    }

    public function testEveryWrittenCellIsSanitized(): void
    {
        $csv = new CsvWriter()->write(['=A1'], [['@cmd', '+1', -5]]);

        self::assertSame("'=A1\r\n'@cmd;'+1;-5\r\n", substr($csv, 3));
    }

    public function testFormatsAmountsAndRatesTheFrenchWay(): void
    {
        $csv = new CsvWriter();

        self::assertSame('12,50', $csv->amount(1250));
        self::assertSame('0,05', $csv->amount(5));
        self::assertSame('1234567,00', $csv->amount(123456700));
        self::assertSame('-0,50', $csv->amount(-50));
        self::assertSame('5,5', $csv->rate(550));
        self::assertSame('20', $csv->rate(2000));
        self::assertSame('2,1', $csv->rate(210));
        self::assertSame('0', $csv->rate(0));
        self::assertSame('8,05', $csv->rate(805));
    }
}
