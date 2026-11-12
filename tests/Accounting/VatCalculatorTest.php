<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Service\VatCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function provideAmounts(): iterable
    {
        // gross, rate (bp), expected net, expected VAT
        yield '5,5 % on 5,20 €' => [520, 550, 493, 27];            // 492,89 → 493
        yield '5,5 % on 1,30 €' => [130, 550, 123, 7];             // 123,22 → 123
        yield '20 % on 12,00 €' => [1200, 2000, 1000, 200];
        yield '20 % on 0,01 €' => [1, 2000, 1, 0];                 // 0,83 → 1
        yield '10 % on 9,99 €' => [999, 1000, 908, 91];            // 908,18 → 908
        yield '2,1 % on 100,00 €' => [10000, 210, 9794, 206];      // 9794,32 → 9794
        yield '0 %' => [520, 0, 520, 0];
        yield 'nothing' => [0, 550, 0, 0];
        yield 'half rounds up' => [3, 10000, 2, 1];                // 1,5 → 2
        yield 'negative amount mirrors the positive one' => [-520, 550, -493, -27];
    }

    #[DataProvider('provideAmounts')]
    public function testExtractsVatFromTheGrossAmount(int $gross, int $rate, int $net, int $vat): void
    {
        $calculator = new VatCalculator();

        self::assertSame($net, $calculator->netFromGross($gross, $rate));
        self::assertSame($vat, $calculator->vatFromGross($gross, $rate));
    }

    public function testNetPlusVatAlwaysEqualsGrossAndMatchesFloatRounding(): void
    {
        $calculator = new VatCalculator();

        foreach ([0, 210, 550, 1000, 2000] as $rate) {
            for ($gross = 0; $gross <= 3000; ++$gross) {
                $net = $calculator->netFromGross($gross, $rate);

                self::assertSame($gross, $net + $calculator->vatFromGross($gross, $rate));
                // Same result as the naive float rounding, without any float.
                self::assertSame((int) round($gross * 10000 / (10000 + $rate)), $net, \sprintf('%d cents at %d bp', $gross, $rate));
            }
        }
    }

    public function testRejectsANegativeRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VatCalculator()->netFromGross(100, -1);
    }
}
