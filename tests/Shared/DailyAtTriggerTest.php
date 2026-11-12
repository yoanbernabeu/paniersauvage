<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Scheduler\DailyAtTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyAtTriggerTest extends TestCase
{
    public function testNextRunIsTodayWhenTheTimeIsStillAhead(): void
    {
        $trigger = new DailyAtTrigger('10:00');

        self::assertSame('2026-11-10 10:00 +01:00', $this->format($trigger->getNextRunDate($this->at('2026-11-10 09:59'))));
        self::assertSame('2026-11-11 10:00 +01:00', $this->format($trigger->getNextRunDate($this->at('2026-11-10 10:00'))), 'strictly after');
        self::assertSame('2026-11-11 10:00 +01:00', $this->format($trigger->getNextRunDate($this->at('2026-11-10 18:00'))));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function daylightSavingChanges(): iterable
    {
        // Sunday 29 March 2026: 02:00 CET → 03:00 CEST (a 23-hour day).
        yield 'spring forward, 10:00' => ['10:00', '2026-03-27 10:00', [
            '2026-03-28 10:00 +01:00',
            '2026-03-29 10:00 +02:00',
            '2026-03-30 10:00 +02:00',
        ]];
        yield 'spring forward, 03:30' => ['03:30', '2026-03-27 03:30', [
            '2026-03-28 03:30 +01:00',
            '2026-03-29 03:30 +02:00',
            '2026-03-30 03:30 +02:00',
        ]];
        // Sunday 25 October 2026: 03:00 CEST → 02:00 CET (a 25-hour day).
        yield 'fall back, 10:00' => ['10:00', '2026-10-23 10:00', [
            '2026-10-24 10:00 +02:00',
            '2026-10-25 10:00 +01:00',
            '2026-10-26 10:00 +01:00',
        ]];
        yield 'fall back, 03:30' => ['03:30', '2026-10-23 03:30', [
            '2026-10-24 03:30 +02:00',
            '2026-10-25 03:30 +01:00',
            '2026-10-26 03:30 +01:00',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('daylightSavingChanges')]
    public function testKeepsTheWallClockTimeAcrossDaylightSavingChanges(string $time, string $start, array $expected): void
    {
        $trigger = new DailyAtTrigger($time);
        $run = $this->at($start);

        $runs = [];
        foreach ($expected as $ignored) {
            $run = $trigger->getNextRunDate($run);
            $runs[] = $this->format($run);
        }

        self::assertSame($expected, $runs);
    }

    public function testATimeSkippedOrRepeatedByTheChangeRunsOnce(): void
    {
        $trigger = new DailyAtTrigger('02:30');

        // 02:30 does not exist on 29 March: it runs at 03:30 CEST, then normally.
        $skipped = $trigger->getNextRunDate($this->at('2026-03-28 02:30'));
        self::assertSame('2026-03-29 03:30 +02:00', $this->format($skipped));
        self::assertSame('2026-03-30 02:30 +02:00', $this->format($trigger->getNextRunDate($skipped)));

        // 02:30 happens twice on 25 October: only the first one runs.
        $first = $trigger->getNextRunDate($this->at('2026-10-24 02:30'));
        self::assertSame('2026-10-25 02:30 +02:00', $this->format($first));
        self::assertSame('2026-10-26 02:30 +01:00', $this->format($trigger->getNextRunDate($first)));
    }

    public function testWorksFromARunExpressedInAnotherTimeZone(): void
    {
        $trigger = new DailyAtTrigger('10:00');

        $next = $trigger->getNextRunDate(new \DateTimeImmutable('2026-10-25 08:30', new \DateTimeZone('UTC')));

        self::assertSame('2026-10-25 10:00 +01:00', $this->format($next));
    }

    public function testRejectsAnInvalidTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DailyAtTrigger('25:00');
    }

    public function testDescribesItself(): void
    {
        self::assertSame('every day at 03:30 (Europe/Paris)', (string) new DailyAtTrigger('03:30'));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Paris'));
    }

    private function format(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i P');
    }
}
