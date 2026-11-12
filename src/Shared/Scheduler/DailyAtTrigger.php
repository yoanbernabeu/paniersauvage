<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Every day at a fixed wall-clock time in a time zone (Europe/Paris by default), daylight saving
 * changes included: a "1 day" periodic trigger drifts by one hour at each change, this one does not.
 *
 * A time that does not exist on the day clocks go forward (e.g. 02:30) runs one hour later; a time
 * that occurs twice on the day clocks go back runs once, at its first occurrence.
 */
final readonly class DailyAtTrigger implements TriggerInterface
{
    private int $hour;
    private int $minute;
    private \DateTimeZone $timezone;

    public function __construct(string $time, string $timezone = 'Europe/Paris')
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            throw new \InvalidArgumentException(\sprintf('Expected a time as "HH:MM", got "%s".', $time));
        }

        $this->hour = (int) $matches[1];
        $this->minute = (int) $matches[2];
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function getNextRunDate(\DateTimeImmutable $run): \DateTimeImmutable
    {
        $local = $run->setTimezone($this->timezone);
        $next = $local->setTime($this->hour, $this->minute);

        if ($next <= $run) {
            // Calendar day arithmetic first, then the wall-clock time: no 24-hour step.
            $next = $local->modify('+1 day')->setTime($this->hour, $this->minute);
        }

        return $next;
    }

    public function __toString(): string
    {
        return \sprintf('every day at %02d:%02d (%s)', $this->hour, $this->minute, $this->timezone->getName());
    }
}
