<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Service\FrenchFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrenchFormatterTest extends TestCase
{
    private const string NBSP = "\u{00A0}";

    #[DataProvider('provideAmounts')]
    public function testMoney(int $cents, string $expected): void
    {
        self::assertSame($expected, new FrenchFormatter()->money($cents));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideAmounts(): iterable
    {
        yield 'usual price' => [520, '5,20'.self::NBSP.'€'];
        yield 'zero' => [0, '0,00'.self::NBSP.'€'];
        yield 'cents only' => [5, '0,05'.self::NBSP.'€'];
        yield 'thousands' => [123450, "1\u{202F}234,50".self::NBSP.'€'];
        yield 'negative' => [-130, '-1,30'.self::NBSP.'€'];
    }

    public function testVatRate(): void
    {
        $formatter = new FrenchFormatter();

        self::assertSame('5,5'.self::NBSP.'%', $formatter->vatRate(550));
        self::assertSame('20'.self::NBSP.'%', $formatter->vatRate(2000));
        self::assertSame('2,1'.self::NBSP.'%', $formatter->vatRate(210));
    }

    public function testDates(): void
    {
        $formatter = new FrenchFormatter();
        $monday = new \DateTimeImmutable('2026-11-16 18:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('lundi 16 novembre', $formatter->dateLong($monday));
        self::assertSame('lundi 16 novembre 2026', $formatter->dateLong($monday, true));
        self::assertSame('16/11/2026', $formatter->dateShort($monday));
        self::assertSame('lun. 16 nov.', $formatter->dateCompact($monday));
        self::assertSame('dimanche 1er novembre', $formatter->dateLong(new \DateTimeImmutable('2026-11-01', new \DateTimeZone('Europe/Paris'))));
        self::assertSame('lundi 16 novembre à 18'.self::NBSP.'h', $formatter->dateTimeLong($monday));
    }

    public function testTimes(): void
    {
        $formatter = new FrenchFormatter();
        $paris = new \DateTimeZone('Europe/Paris');

        self::assertSame('18'.self::NBSP.'h', $formatter->time(new \DateTimeImmutable('2026-11-16 18:00', $paris)));
        self::assertSame('8'.self::NBSP.'h'.self::NBSP.'30', $formatter->time(new \DateTimeImmutable('2026-11-16 08:30', $paris)));
        self::assertSame(
            '18'.self::NBSP.'h – 19'.self::NBSP.'h',
            $formatter->timeRange(new \DateTimeImmutable('2026-11-16 18:00', $paris), new \DateTimeImmutable('2026-11-16 19:00', $paris)),
        );
    }

    public function testDatesAreShownInParisTime(): void
    {
        $utc = new \DateTimeImmutable('2026-11-16 23:30', new \DateTimeZone('UTC'));

        self::assertSame('mardi 17 novembre', new FrenchFormatter()->dateLong($utc));
        self::assertSame('0'.self::NBSP.'h'.self::NBSP.'30', new FrenchFormatter()->time($utc));
    }

    public function testWeekday(): void
    {
        self::assertSame('lundi', new FrenchFormatter()->weekday(1));
        self::assertSame('dimanche', new FrenchFormatter()->weekday(7));
    }
}
