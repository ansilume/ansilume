<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CronRuns;
use Cron\CronExpression;
use PHPUnit\Framework\TestCase;

/**
 * Regression: on the night the clocks went back, a schedule at 2:00 in
 * Europe/Berlin ran twice, and one at 3:00 an hour early as well, as the
 * cron library computed the runs in the schedule's timezone; around the
 * clock changes of Africa/Casablanca, Antarctica/Troll and
 * Australia/Lord_Howe it skipped runs, found none, so that the schedule
 * showed no next run, or found an earlier one again, so that it ran every
 * minute. CronRuns asks the library only in UTC: a time that comes twice
 * runs once as cron decides it, a time the clocks skip runs as the library
 * ran it for a change by an hour.
 */
class CronRunsTest extends TestCase
{
    /**
     * The runs as the scheduler chains them, each from the minute after the
     * one before: the zone, the time to start from (UTC), the expression,
     * and the runs in the zone's time.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function nightProvider(): array
    {
        return [
            'back: a time that comes twice runs the first time' => ['Europe/Berlin', '2026-10-24 22:30', '0 2 * * *', ['10-25 02:00 CEST', '10-26 02:00 CET']],
            'back: the hour after it runs at its time only' => ['Europe/Berlin', '2026-10-24 22:30', '0 3 * * *', ['10-25 03:00 CET', '10-26 03:00 CET']],
            'back: the hour before it runs once' => ['Europe/Berlin', '2026-10-24 22:30', '0 1 * * *', ['10-25 01:00 CEST', '10-26 01:00 CET']],
            'back: a half hour that comes twice runs the first time' => ['Europe/Berlin', '2026-10-24 22:30', '30 2 * * *', ['10-25 02:30 CEST', '10-26 02:30 CET']],
            'back: a range of hours runs each hour once' => ['Europe/Berlin', '2026-10-24 22:30', '0 1-3 * * *', ['10-25 01:00 CEST', '10-25 02:00 CEST', '10-25 03:00 CET', '10-26 01:00 CET']],
            'back: a list of hours runs each hour once' => ['Europe/Berlin', '2026-10-24 22:30', '0 2,3 * * *', ['10-25 02:00 CEST', '10-25 03:00 CET', '10-26 02:00 CET']],
            'back: every hour runs at each hour that comes' => ['Europe/Berlin', '2026-10-24 22:30', '0 * * * *', ['10-25 01:00 CEST', '10-25 02:00 CEST', '10-25 02:00 CET', '10-25 03:00 CET']],
            'back: an hour field that starts with * runs the repeated hour again' => ['Europe/Berlin', '2026-10-24 22:30', '0 */2 * * *', ['10-25 02:00 CEST', '10-25 02:00 CET', '10-25 04:00 CET']],
            'back: a minute field that starts with * runs the repeated times again' => ['Europe/Berlin', '2026-10-24 22:30', '*/30 2 * * *', ['10-25 02:00 CEST', '10-25 02:30 CEST', '10-25 02:00 CET', '10-25 02:30 CET', '10-26 02:00 CET']],
            'back: ... but only at the times it holds' => ['Europe/Berlin', '2026-10-24 22:30', '*/30 3 * * *', ['10-25 03:00 CET', '10-25 03:30 CET', '10-26 03:00 CET']],
            'back, USA: 1:00 runs once' => ['America/New_York', '2026-10-31 22:00', '0 1 * * *', ['11-01 01:00 EDT', '11-02 01:00 EST']],
            'back, USA: 2:00 runs at 2:00' => ['America/New_York', '2026-10-31 22:00', '0 2 * * *', ['11-01 02:00 EST', '11-02 02:00 EST']],
            'forward: a time the clocks skip runs an hour later' => ['Europe/Berlin', '2027-03-27 22:30', '0 2 * * *', ['03-28 03:00 CEST', '03-29 02:00 CEST']],
            'forward: 2:30 runs at 3:30' => ['Europe/Berlin', '2027-03-27 22:30', '30 2 * * *', ['03-28 03:30 CEST', '03-29 02:30 CEST']],
            'forward: 3:00 runs at 3:00' => ['Europe/Berlin', '2027-03-27 22:30', '0 3 * * *', ['03-28 03:00 CEST', '03-29 03:00 CEST']],
            'forward: a skipped time and the time it falls on run once' => ['Europe/Berlin', '2027-03-27 22:30', '0 2,3 * * *', ['03-28 03:00 CEST', '03-29 02:00 CEST']],
            'forward: every few minutes of a skipped hour run an hour later' => ['Europe/Berlin', '2027-03-27 22:30', '*/30 2 * * *', ['03-28 03:00 CEST', '03-28 03:30 CEST', '03-29 02:00 CEST']],
            'Casablanca, back: the first 2:00, not the second' => ['Africa/Casablanca', '2026-02-14 22:00', '0 2 * * *', ['02-15 02:00 +01', '02-16 02:00 +00']],
            'Casablanca, forward: 3:00 runs' => ['Africa/Casablanca', '2026-03-21 22:00', '0 3 * * *', ['03-22 03:00 +01', '03-23 03:00 +01']],
            'Lord Howe, forward by 30 minutes: 1:00 has a run' => ['Australia/Lord_Howe', '2026-10-03 12:00', '0 1 * * *', ['10-04 01:00 +1030', '10-05 01:00 +11']],
            'Lord Howe, forward: 2:15 runs at 2:45' => ['Australia/Lord_Howe', '2026-10-03 12:00', '15 2 * * *', ['10-04 02:45 +11', '10-05 02:15 +11']],
            'Lord Howe, back by 30 minutes: 1:30 runs once' => ['Australia/Lord_Howe', '2027-04-03 12:00', '30 1 * * *', ['04-04 01:30 +11', '04-05 01:30 +1030']],
            'Lord Howe, back: every 15 minutes runs on, and never goes back' => [
                'Australia/Lord_Howe',
                '2027-04-03 12:00',
                '*/15 1 * * *',
                ['04-04 01:00 +11', '04-04 01:15 +11', '04-04 01:30 +11', '04-04 01:45 +11', '04-04 01:30 +1030', '04-04 01:45 +1030', '04-05 01:00 +1030'],
            ],
            'Troll, back by two hours: 2:00 runs once' => ['Antarctica/Troll', '2026-10-24 20:00', '0 2 * * *', ['10-25 02:00 +02', '10-26 02:00 +00']],
            'Troll, forward by two hours: 1:30 runs at 3:30' => ['Antarctica/Troll', '2027-03-27 20:00', '30 1 * * *', ['03-28 03:30 +02', '03-29 01:30 +02']],
            'UTC has no such night' => ['UTC', '2026-10-24 22:30', '0 2 * * *', ['10-25 02:00 UTC', '10-26 02:00 UTC']],
            'an offset has no clock changes' => ['+02:00', '2026-10-24 22:30', '0 2 * * *', ['10-25 02:00 GMT+0200', '10-26 02:00 GMT+0200']],
            'an abbreviation is an offset to PHP: CET stays +01:00' => ['CET', '2026-10-24 22:30', '0 2 * * *', ['10-25 02:00 CET', '10-26 02:00 CET']],
            'a run more than a year away, in a zone without changes' => ['Asia/Kolkata', '2026-03-01 00:00', '0 0 29 2 *', ['02-29 00:00 IST']],
            'a run more than a year away, past several changes' => ['Europe/Berlin', '2026-03-01 00:00', '0 0 29 2 *', ['02-29 00:00 CET']],
            'a day of the month or a day of the week, as the library reads both' => ['UTC', '2026-11-01 00:00', '0 0 13 * 5', ['11-06 00:00 UTC', '11-13 00:00 UTC', '11-20 00:00 UTC', '11-27 00:00 UTC']],
        ];
    }

    /**
     * @dataProvider nightProvider
     * @param list<string> $runs
     */
    public function testTheRunsOnTheNightsTheClocksChange(string $zone, string $from, string $cron, array $runs): void
    {
        $this->assertSame($runs, self::runsOf($cron, $zone, $from, count($runs)));
    }

    /**
     * Review finding: a timezone whose offset has seconds, which DateTimeZone
     * accepts and the schedule form's free text allows, put a run 30 seconds
     * before the minute it was looked for from, so the scheduler found the
     * run it had just launched again and launched it twice.
     */
    public function testARunIsNeverBeforeTheMinuteItIsLookedForFrom(): void
    {
        $cron = new CronExpression('0 * * * *');
        $eleven = self::timestamp('2026-11-02 11:00');

        $this->assertSame($eleven + 3570, CronRuns::first($cron, $eleven, '+01:00:30'), '13:00 there is 11:59:30 UTC');
        $this->assertSame($eleven + 3570, CronRuns::first($cron, $eleven + 3540, '+01:00:30'));
        $this->assertSame($eleven + 7170, CronRuns::first($cron, $eleven + 3600, '+01:00:30'), 'the minute after the run: the next one');
    }

    /**
     * The expressions a save refuses that versions up to 2.12.2 saved: the
     * library walks their lists otherwise than it matches a minute, so where
     * it starts decides the runs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedProvider(): array
    {
        $cases = [];
        foreach (['*,5 * * * *', '*/59 * * * *', '*/2,*/3 * * * *', '5-10,45 * * * *', '0 18,6 * * *', '*/90 * * * *', '0 0 * * SAT-SUN'] as $cron) {
            foreach (['UTC', 'Europe/Berlin'] as $zone) {
                $cases["{$cron} in {$zone}"] = [$cron, $zone];
            }
        }

        return $cases;
    }

    /**
     * Review finding: CronRuns asked the library for the first run at or
     * after the minute after the last run, where 2.12.2 asked for the first
     * after that run's minute; for an expression with * and 5 in its minute
     * list that ran every minute instead of at minutes 0 and 5. Such a
     * schedule runs as before until its expression is changed, as its page
     * says (Schedule::CRON_REFUSED_NOTE).
     *
     * @dataProvider refusedProvider
     */
    public function testAStoredExpressionASaveRefusesRunsAsBefore(string $cron, string $zone): void
    {
        $expression = new CronExpression($cron);
        $before = [];
        $at = new \DateTimeImmutable('2026-11-02 10:00:20', new \DateTimeZone('UTC'));
        for ($i = 0; $i < 12; $i++) {
            // As 2.12.2 chained them: the library in the zone, from just after each run.
            $run = $expression->getNextRunDate($at->setTimezone(new \DateTimeZone($zone)), 0, false, $zone);
            $before[] = $run->getTimestamp();
            $at = \DateTimeImmutable::createFromMutable($run)->modify('+30 seconds');
        }

        $this->assertSame($before, self::runsWithin($cron, $zone, self::timestamp('2026-11-02 10:01'), $before[11] + 1));
    }

    public function testAListWithAnAsteriskRunsAtMinutesZeroAndFiveAsBefore(): void
    {
        $this->assertSame(['11-02 10:05 UTC', '11-02 11:00 UTC', '11-02 11:05 UTC', '11-02 12:00 UTC'], self::runsOf('*,5 * * * *', 'UTC', '2026-11-02 10:01', 4));
        $this->assertSame(['11-02 10:59 UTC', '11-02 11:59 UTC', '11-02 12:59 UTC'], self::runsOf('*/59 * * * *', 'UTC', '2026-11-02 10:01', 3));
    }

    public function testATimeThatIsARunIsTheFirstRunFromIt(): void
    {
        $run = self::timestamp('2026-10-25 02:00');

        $this->assertSame($run, CronRuns::first(new CronExpression('0 2 * * *'), $run, 'UTC'));
        $this->assertSame($run + 86400, CronRuns::first(new CronExpression('0 2 * * *'), $run + 60, 'UTC'));
    }

    /**
     * What the library itself does on those nights in the schedule's
     * timezone, which is why CronRuns asks it only in UTC: the runs, each
     * looked for from 30 seconds after the one before, or the exception. If
     * this changes, the library was fixed.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>|string}>
     */
    public static function libraryProvider(): array
    {
        return [
            'runs 2:00 twice' => ['Europe/Berlin', '2026-10-24 22:30', '0 2 * * *', ['10-25 02:00 CEST', '10-25 02:00 CET', '10-26 02:00 CET']],
            'runs 3:00 an hour early as well' => ['Europe/Berlin', '2026-10-24 22:30', '0 3 * * *', ['10-25 02:00 CET', '10-25 03:00 CET', '10-26 03:00 CET']],
            'runs 2:00 in the USA an hour early as well' => ['America/New_York', '2026-10-31 22:00', '0 2 * * *', ['11-01 01:00 EST', '11-01 02:00 EST', '11-02 02:00 EST']],
            'skips 3:00 in Casablanca on the night the clocks go forward' => ['Africa/Casablanca', '2026-03-21 22:00', '0 3 * * *', ['03-23 03:00 +01', '03-24 03:00 +01', '03-25 03:00 +01']],
            'finds no run of 1:00 on Lord Howe Island' => ['Australia/Lord_Howe', '2026-10-03 12:00', '0 1 * * *', 'Impossible CRON expression'],
            'goes back from 1:45 to 1:30 on Lord Howe Island, again and again' => ['Australia/Lord_Howe', '2027-04-03 14:40', '*/15 1 * * *', ['04-04 01:45 +11', '04-04 01:30 +11', '04-04 01:45 +11']],
        ];
    }

    /**
     * @dataProvider libraryProvider
     * @param list<string>|string $runs
     */
    public function testTheLibraryOnTheseNights(string $zone, string $from, string $cron, array|string $runs): void
    {
        $expression = new CronExpression($cron);
        $at = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $actual = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $run = $expression->getNextRunDate($at->setTimezone(new \DateTimeZone($zone)), 0, false, $zone);
                $actual[] = $run->format('m-d H:i T');
                $at = \DateTimeImmutable::createFromMutable($run)->modify('+30 seconds');
            }
        } catch (\RuntimeException $e) {
            $actual = $e->getMessage();
        }

        $this->assertSame($runs, $actual);
    }

    /**
     * Each run around the clock changes of these zones in 2026, from three
     * hours before a change to three hours after it, is a time the rules
     * pick, and none of those times is left out: checked minute by minute.
     */
    public function testEveryRunAroundTheClockChangesFollowsTheRules(): void
    {
        $zones = ['Europe/Berlin', 'America/New_York', 'Africa/Casablanca', 'Australia/Lord_Howe', 'Antarctica/Troll', 'America/Santiago', 'America/Havana'];
        $checked = 0;
        foreach ($zones as $zone) {
            foreach (self::changesIn2026($zone) as $change) {
                foreach (self::expressionsAround($zone, $change) as $cron) {
                    $this->assertSame(
                        self::minutesTheRulesPick($cron, $zone, $change),
                        self::runsWithin($cron, $zone, $change['at'] - 10800, $change['at'] + 10800),
                        "{$cron} in {$zone} around " . gmdate('Y-m-d H:i', $change['at'])
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(150, $checked);
    }

    public function testAnExpressionWithoutRunsThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        CronRuns::first(new CronExpression('0 0 30 2 *'), self::timestamp('2026-10-24 00:00'), 'Europe/Berlin');
    }

    public function testAnUnknownTimezoneThrows(): void
    {
        $this->expectException(\Exception::class);

        CronRuns::first(new CronExpression('0 2 * * *'), self::timestamp('2026-10-24 00:00'), 'Mars/Olympus');
    }

    public function testATimezoneWithANulByteThrowsAValueError(): void
    {
        $this->expectException(\ValueError::class);

        CronRuns::first(new CronExpression('0 2 * * *'), self::timestamp('2026-10-24 00:00'), "UTC\0x");
    }

    /**
     * Every run the application computes comes from CronRuns: a new call of
     * the library's getNextRunDate(), getPreviousRunDate(),
     * getMultipleRunDates() or isDue() with a time would bring back the runs
     * of the nights the clocks change. Schedule::isDue() takes no argument,
     * and ScheduleService calls its own isDue() on $this.
     */
    public function testNothingElseAsksTheLibraryForRuns(): void
    {
        $calls = [];
        foreach (self::applicationFiles() as $path) {
            $tokens = array_values(array_filter(
                token_get_all((string)file_get_contents(self::root() . '/' . $path)),
                static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $token) {
                if (self::asksForRuns($tokens, $i)) {
                    $calls[] = $path . ':' . $token[2];
                }
            }
        }

        $elsewhere = array_values(array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'components/CronRuns.php:')));
        $this->assertSame([], $elsewhere, 'Compute runs with CronRuns (Schedule::nextRunOf(), Schedule::firstRunFrom())');
        $this->assertCount(1, $calls, 'The scan finds the call in CronRuns.');
    }

    /**
     * The runs of $cron in $zone, chained from $from (UTC) as the scheduler
     * chains them.
     *
     * @return list<string>
     */
    private static function runsOf(string $cron, string $zone, string $from, int $count): array
    {
        $expression = new CronExpression($cron);
        $at = self::timestamp($from);
        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            $run = CronRuns::first($expression, $at, $zone);
            $runs[] = (new \DateTimeImmutable('@' . $run))->setTimezone(new \DateTimeZone($zone))->format('m-d H:i T');
            $at = $run + 60;
        }

        return $runs;
    }

    /**
     * The runs of $cron in $zone from $from to before $until, chained.
     *
     * @return list<int>
     */
    private static function runsWithin(string $cron, string $zone, int $from, int $until): array
    {
        $expression = new CronExpression($cron);
        $runs = [];
        for ($run = CronRuns::first($expression, $from, $zone); $run < $until; $run = CronRuns::first($expression, $run + 60, $zone)) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The minutes from three hours before $change to three hours after it
     * at which the rules run $cron in $zone: one that comes again after the
     * clock went back when the minute or the hour field starts with *, and
     * the expression holds its time; in the stretch after the clock went
     * forward one whose time, or the time the clock skipped to it, the
     * expression holds; any other whose time it holds.
     *
     * @param array{at: int, shift: int} $change
     * @return list<int>
     */
    private static function minutesTheRulesPick(string $cron, string $zone, array $change): array
    {
        $expression = new CronExpression($cron);
        $timezone = new \DateTimeZone($zone);
        $again = str_starts_with($cron, '*') || str_starts_with(explode(' ', $cron)[1], '*');
        $holds = static fn (int $wall): bool => $expression->isDue(new \DateTimeImmutable('@' . $wall), 'UTC');
        $picked = [];
        for ($minute = $change['at'] - 10800; $minute < $change['at'] + 10800; $minute += 60) {
            $wall = $minute + $timezone->getOffset(new \DateTimeImmutable('@' . $minute));
            $after = $minute >= $change['at'] && $minute < $change['at'] + abs($change['shift']);
            $runs = match (true) {
                $after && $change['shift'] < 0 => $again && $holds($wall),
                $after => $holds($wall) || $holds($wall - $change['shift']),
                default => $holds($wall),
            };
            if ($runs) {
                $picked[] = $minute;
            }
        }

        return $picked;
    }

    /**
     * The changes of $zone's clock in 2026.
     *
     * @return list<array{at: int, shift: int}>
     */
    private static function changesIn2026(string $zone): array
    {
        $transitions = (new \DateTimeZone($zone))->getTransitions(self::timestamp('2026-01-01 00:00'), self::timestamp('2027-01-01 00:00')) ?: [];
        $changes = [];
        for ($i = 1; $i < count($transitions); $i++) {
            $shift = $transitions[$i]['offset'] - $transitions[$i - 1]['offset'];
            if ($shift !== 0) {
                $changes[] = ['at' => $transitions[$i]['ts'], 'shift' => $shift];
            }
        }
        self::assertNotSame([], $changes, "{$zone} changes its clock in 2026");

        return $changes;
    }

    /**
     * Expressions around $change of $zone's clock: every hour, every 20
     * minutes, every second hour, and the hour where the times that come
     * again or that the clock skipped start, and the hours before and after
     * it, at minutes 0 and 30 and every 15 minutes, and with the hour after
     * it.
     *
     * @param array{at: int, shift: int} $change
     * @return list<string>
     */
    private static function expressionsAround(string $zone, array $change): array
    {
        $offset = (new \DateTimeZone($zone))->getOffset(new \DateTimeImmutable('@' . $change['at']));
        $start = (int)gmdate('G', $change['at'] + $offset - max(0, $change['shift']));
        $expressions = ['0 * * * *', '*/20 * * * *', '0 */2 * * *', sprintf('0 %d,%d * * *', $start, ($start + 1) % 24)];
        foreach ([$start + 23, $start, $start + 1] as $hour) {
            foreach (['0 %d * * *', '30 %d * * *', '*/15 %d * * *'] as $pattern) {
                $expressions[] = sprintf($pattern, $hour % 24);
            }
        }

        return $expressions;
    }

    /**
     * Whether $tokens[$i] is a call of the library's getNextRunDate(),
     * getPreviousRunDate() or getMultipleRunDates(), or of an isDue() with
     * an argument on anything but $this.
     *
     * @param list<mixed> $tokens without whitespace and comments
     */
    private static function asksForRuns(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        $before = $tokens[$i - 1] ?? null;
        if (!is_array($token) || $token[0] !== T_STRING || !is_array($before) || !in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return false;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            return false;
        }
        $method = strtolower((string)$token[1]);
        if ($method !== 'isdue') {
            return in_array($method, ['getnextrundate', 'getpreviousrundate', 'getmultiplerundates'], true);
        }
        $receiver = $tokens[$i - 2] ?? null;

        return ($tokens[$i + 2] ?? null) !== ')' && !(is_array($receiver) && $receiver[1] === '$this');
    }

    /**
     * The PHP files of the application, relative to the repository root.
     *
     * @return list<string>
     */
    private static function applicationFiles(): array
    {
        $root = self::root();
        $files = [];
        foreach (['commands', 'components', 'config', 'controllers', 'helpers', 'jobs', 'mail', 'migrations', 'models', 'services', 'views', 'web'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function timestamp(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
