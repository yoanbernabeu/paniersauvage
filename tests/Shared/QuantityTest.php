<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Catalog\Enum\ProductUnit;
use App\Shared\Service\FrenchFormatter;
use App\Shared\Service\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function testWholeUnits(): void
    {
        self::assertSame(1000, Quantity::UNIT);
        self::assertSame(3000, Quantity::units(3));
        self::assertTrue(Quantity::isWhole(3000));
        self::assertTrue(Quantity::isWhole(0));
        self::assertFalse(Quantity::isWhole(1500));
        self::assertFalse(Quantity::isWhole(1));
    }

    /**
     * Price of a quantity, rounded to the nearest cent, halves away from zero.
     */
    #[DataProvider('providePrices')]
    public function testPriceIsRoundedToTheCent(int $quantity, int $unitPriceCents, int $expected): void
    {
        self::assertSame($expected, Quantity::priceCents($quantity, $unitPriceCents));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function providePrices(): iterable
    {
        yield 'whole pieces are exact' => [3000, 299, 897];
        yield 'one piece' => [1000, 520, 520];
        yield 'nothing' => [0, 520, 0];
        yield '1,5 kg at 4,50 €' => [1500, 450, 675];
        yield 'half a cent goes up' => [1500, 299, 449];
        yield '1,25 kg at 4,50 € = 5,625 €' => [1250, 450, 563];
        yield 'below half a cent goes down' => [1001, 299, 299];
        yield 'just below a half' => [333, 100, 33];
        yield 'just above a half' => [335, 100, 34];
        yield '1 g' => [1, 450, 0];
        yield 'negative halves go away from zero' => [-1500, 299, -449];
    }

    #[DataProvider('provideTypedValues')]
    public function testParsesWhatSomeoneTyped(string $typed, ?int $expected): void
    {
        self::assertSame($expected, Quantity::parse($typed));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function provideTypedValues(): iterable
    {
        yield 'decimal comma' => ['1,5', 1500];
        yield 'decimal point' => ['1.5', 1500];
        yield 'whole' => ['2', 2000];
        yield 'zero' => ['0', 0];
        yield 'grams' => ['0,250', 250];
        yield 'three decimals' => ['1,125', 1125];
        yield 'trailing separator' => ['2,', 2000];
        yield 'spaces' => [' 1,5 ', 1500];
        yield 'thousands separator' => ["1\u{202F}000", 1_000_000];
        yield 'too many decimals' => ['1,2345', null];
        yield 'negative' => ['-1', null];
        yield 'not a number' => ['beaucoup', null];
        yield 'empty' => ['', null];
        yield 'separator only' => [',5', null];
        yield 'exponent' => ['1e3', null];
    }

    public function testFormatsAsADecimal(): void
    {
        self::assertSame('1,5', Quantity::decimal(1500));
        self::assertSame('0,25', Quantity::decimal(250));
        self::assertSame('0,005', Quantity::decimal(5));
        self::assertSame('2', Quantity::decimal(2000));
        self::assertSame('0', Quantity::decimal(0));
        self::assertSame('-1,5', Quantity::decimal(-1500));
        self::assertSame('1.125', Quantity::inputValue(1125));
        self::assertSame('12', Quantity::inputValue(12000));
    }

    public function testUnitsSayWhichQuantitiesExist(): void
    {
        self::assertTrue(ProductUnit::Kg->isWeighed());
        self::assertFalse(ProductUnit::Piece->isWeighed());
        self::assertFalse(ProductUnit::Bunch->isWeighed());

        self::assertTrue(ProductUnit::Kg->accepts(1250));
        self::assertTrue(ProductUnit::Piece->accepts(2000));
        self::assertTrue(ProductUnit::Piece->accepts(0));
        self::assertFalse(ProductUnit::Piece->accepts(1500));
        self::assertFalse(ProductUnit::Bunch->accepts(1));
        self::assertFalse(ProductUnit::Kg->accepts(-1));
        self::assertFalse(ProductUnit::Piece->accepts(-1000));
    }

    public function testFrenchDisplay(): void
    {
        $formatter = new FrenchFormatter();

        self::assertSame("1,5\u{00A0}kg", $formatter->quantity(1500, ProductUnit::Kg));
        self::assertSame('2', $formatter->quantity(2000, ProductUnit::Piece));
        self::assertSame("0,25\u{00A0}kg", $formatter->lineQuantity(250, ProductUnit::Kg));
        self::assertSame('3 ×', $formatter->lineQuantity(3000, ProductUnit::Bunch));
    }
}
