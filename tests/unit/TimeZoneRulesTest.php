<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * The rules of every time zone a schedule can run in are those of the
 * committed fixture (tests/fixtures/time-zone-rules.json), from
 * TimeZoneRules::FROM to TimeZoneRules::UNTIL: a PHP whose time zone data
 * changes when the clocks change in a zone turns the test red until someone
 * checked whether the next runs stored by the release before need a
 * migration, and wrote the fixture anew (TimeZoneRules::REPORT says how).
 * New data that changes no rule in the window passes: it moves no stored
 * run.
 *
 * Regression: PHP 8.5.13 brought time zone data 2026.6 (IANA's 2026f),
 * which keeps America/Havana and its alias Cuba at UTC-4 from 2026-11-01 to
 * 2027-03-14, where 8.5.12 (2026.5), the PHP of 2.15.1, turned the clocks
 * back an hour: 2.15.1 stored and ran the schedules there an hour late, and
 * nothing told anyone; the change was found by hand. The CI builds its
 * images with pull, so a new PHP release reaches this test on its own.
 */
class TimeZoneRulesTest extends TestCase
{
    /** The fingerprint of America/Havana and Cuba with time zone data 2026.5 (PHP 8.5.12). */
    public const HAVANA_2026_5 = 'c385628ddc1e6dd3';

    /** Their fingerprint with time zone data 2026.6 (PHP 8.5.13); Php8513UpdateDocsContractTest reads it too. */
    public const HAVANA_2026_6 = 'd67276c750cfb702';

    /** How far apart the moments are at which every zone's offset is compared with its rules. */
    private const SAMPLE_STEP = 604800;

    /** The migrations that computed the next runs anew for new time zone data, which the report names. */
    private const MIGRATIONS_THE_REPORT_NAMES = [
        'm000090_000000_recompute_schedule_next_runs_for_time_zone_data',
        'm000095_000000_recompute_schedule_next_runs_for_cuba',
    ];

    public function testTheRulesOfEveryTimeZoneAreThoseOfTheFixture(): void
    {
        $fixture = self::fixture();
        $running = TimeZoneRules::ofRunningPhp();
        $differences = TimeZoneRules::differences($fixture['zones'], $running['zones']);

        $this->assertSame(
            TimeZoneRules::NO_DIFFERENCES,
            $differences,
            TimeZoneRules::report($fixture, $running, $differences)
        );
    }

    public function testTheFixtureIsTheTextTheScriptWritesForTheWindowOfTheRules(): void
    {
        $fixture = self::fixture();
        $rewrite = 'Write it anew: ' . TimeZoneRules::WRITE;

        $this->assertSame(
            [TimeZoneRules::FROM, TimeZoneRules::UNTIL],
            [$fixture['from'], $fixture['until']],
            'The fixture was written for another window than TimeZoneRules::FROM to UNTIL. ' . $rewrite
        );
        $this->assertSame(
            TimeZoneRules::json($fixture),
            (string)file_get_contents(self::root() . '/' . TimeZoneRules::FIXTURE),
            'The fixture is not the text the script writes. ' . $rewrite
        );
    }

    public function testTheScriptPrintsTheFixtureOfTheRunningPhpTheSameOnEveryRun(): void
    {
        $expected = TimeZoneRules::json(TimeZoneRules::ofRunningPhp());

        $this->assertSame($expected, $this->runScript([]));
        $this->assertSame($expected, $this->runScript([]));
    }

    public function testTheScriptPrintsTheRulesOfTheZonesItIsGiven(): void
    {
        $names = ['UTC', '+05:30', '-04:00', '+01:00:30', 'Mars/Olympus'];
        $expected = "UTC UTC+00:00 from 2026-11-20T00:00:00Z\n"
            . "+05:30 UTC+05:30 from 2026-11-20T00:00:00Z\n"
            . "-04:00 UTC-04:00 from 2026-11-20T00:00:00Z\n"
            . "+01:00:30 UTC+01:00:30 from 2026-11-20T00:00:00Z\n"
            . sprintf(TimeZoneRules::UNKNOWN_LINE, 'Mars/Olympus') . "\n";

        $this->assertSame($expected, TimeZoneRules::describe($names));
        $this->assertSame($expected, $this->runScript($names));
    }

    public function testTheScriptShowsEachChangeOfAZoneThatChangesItsClock(): void
    {
        $lines = explode("\n", rtrim(TimeZoneRules::describe(['Cuba'])));
        $rules = TimeZoneRules::rulesOf(new \DateTimeZone('Cuba'));

        $this->assertCount(count($rules), $lines);
        foreach ($rules as $i => $rule) {
            $this->assertStringEndsWith(' from ' . gmdate('Y-m-d\TH:i:s\Z', $rule['at']), $lines[$i]);
            $this->assertStringStartsWith('Cuba UTC', $lines[$i]);
        }
    }

    /**
     * The zones every schedule can store whose offset comes from the time
     * zone data: every identifier, the aliases too, and every abbreviation,
     * each once whatever its case, sorted.
     */
    public function testTheZonesAreEveryIdentifierAndEveryAbbreviationOnce(): void
    {
        $names = TimeZoneRules::names();
        $lower = array_map('strtolower', $names);
        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $names);
        $this->assertSame($lower, array_values(array_unique($lower)));
        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        $this->assertSame([], array_values(array_diff($identifiers, $names)));
        $abbreviations = array_map('strval', array_keys(\DateTimeZone::listAbbreviations()));
        $this->assertSame([], array_values(array_diff($abbreviations, $lower)));
        foreach (['America/Havana', 'Cuba', 'Canada/Pacific', 'CET', 'EST', 'GMT+0', 'pst', 'cest', 'a'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('cet', $names, 'CET is an identifier already');
    }

    /**
     * Regression of the fingerprint's method: getTransitions() returns false
     * for a UTC offset and an abbreviation (and the identifiers CET, EST,
     * GMT+0 and the like that PHP reads as one), which PHP keeps at one
     * offset: their rules are that offset, as those of the zone of the data
     * with the same fixed offset, whose changes PHP lists.
     */
    public function testAZoneWithoutAListOfChangesHasTheRulesOfItsOneOffset(): void
    {
        $alike = [
            '-05:00' => 'Etc/GMT+5',
            'est' => 'Etc/GMT+5',
            'EST' => 'Etc/GMT+5',
            'pst' => 'Etc/GMT+8',
            'cest' => 'Etc/GMT-2',
            'GMT+0' => 'UTC',
            'gmt' => 'UTC',
        ];
        foreach ($alike as $name => $ofTheData) {
            $zone = new \DateTimeZone($name);
            $listed = new \DateTimeZone($ofTheData);

            $this->assertFalse($zone->getTransitions(0, PHP_INT_MAX), $name);
            $this->assertIsArray($listed->getTransitions(0, PHP_INT_MAX), $ofTheData);
            $this->assertSame(TimeZoneRules::rulesOf($listed), TimeZoneRules::rulesOf($zone), $name);
            $this->assertSame(TimeZoneRules::fingerprint($listed), TimeZoneRules::fingerprint($zone), $name);
        }
    }

    public function testAZoneOfTheDataWhoseChangesPhpDoesNotListIsRefused(): void
    {
        $zone = new class ('Europe/Berlin') extends \DateTimeZone {
            /** @return list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>|false */
            public function getTransitions(
                int $timestampBegin = PHP_INT_MIN,
                int $timestampEnd = PHP_INT_MAX
            ): array|false {
                return false;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf(TimeZoneRules::NO_CHANGES, 'Europe/Berlin'));
        TimeZoneRules::rulesOf($zone);
    }

    /**
     * Only a change of the offset in the window counts: not one of the
     * abbreviation or the DST flag alone, which moves no run, and not one
     * before FROM or at UNTIL, which getTransitions() lists for some zones.
     */
    public function testTheRulesAreTheOffsetAtTheStartAndEachChangeOfItInTheWindow(): void
    {
        $from = (new \DateTimeImmutable(TimeZoneRules::FROM))->getTimestamp();
        $until = (new \DateTimeImmutable(TimeZoneRules::UNTIL))->getTimestamp();
        $zone = new class ('UTC') extends \DateTimeZone {
            /** @var list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}> */
            public array $listed = [];

            /** @return list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>|false */
            public function getTransitions(
                int $timestampBegin = PHP_INT_MIN,
                int $timestampEnd = PHP_INT_MAX
            ): array|false {
                return $this->listed;
            }

            public function getOffset(\DateTimeInterface $datetime): int
            {
                return 3600;
            }
        };
        $transition = static fn (int $at, int $offset, bool $isdst, string $abbr): array => [
            'ts' => $at,
            'time' => gmdate('Y-m-d\TH:i:sO', $at),
            'offset' => $offset,
            'isdst' => $isdst,
            'abbr' => $abbr,
        ];
        $zone->listed = [
            $transition($from - 86400, 7200, true, 'CEST'),
            $transition($from, 3600, false, 'CET'),
            $transition($from + 100, 3600, true, 'XYZ'),
            $transition($from + 200, 7200, true, 'CEST'),
            $transition($from + 300, -18000, false, 'EST'),
            $transition($until, 3600, false, 'CET'),
        ];

        $this->assertSame(
            [
                ['at' => $from, 'offset' => 3600],
                ['at' => $from + 200, 'offset' => 7200],
                ['at' => $from + 300, 'offset' => -18000],
            ],
            TimeZoneRules::rulesOf($zone)
        );
    }

    /**
     * The rules are exact: at both sides of every change and every week of
     * the window, each zone's offset as PHP converts a moment (getOffset(),
     * which CronRuns reads too) is the one its rules give, for the zones
     * without a list of changes as well.
     */
    public function testTheRulesOfEveryZoneAgreeWithItsOffsetAtEveryMoment(): void
    {
        $until = (new \DateTimeImmutable(TimeZoneRules::UNTIL))->getTimestamp();
        $disagreements = [];
        foreach (TimeZoneRules::names() as $name) {
            $zone = new \DateTimeZone($name);
            $rules = TimeZoneRules::rulesOf($zone);
            foreach (self::moments($rules, $until) as $at => $offset) {
                $actual = $zone->getOffset(new \DateTimeImmutable('@' . $at));
                if ($actual !== $offset) {
                    $disagreements[] = sprintf('%s at %s: %d, the rules %d', $name, gmdate('c', $at), $actual, $offset);
                }
            }
        }

        $this->assertSame([], $disagreements);
    }

    /**
     * New time zone data that changes no rule in the window moves no stored
     * run: it passes, whatever the versions.
     */
    public function testANewVersionOfTheDataThatChangesNoRulePasses(): void
    {
        $zones = [
            'America/Havana' => self::HAVANA_2026_6,
            'UTC' => TimeZoneRules::fingerprint(new \DateTimeZone('UTC')),
        ];
        $fixture = self::rules('8.5.12', '2026.5', $zones);
        $running = self::rules('8.5.13', '2026.6', $zones);

        $this->assertSame(
            TimeZoneRules::NO_DIFFERENCES,
            TimeZoneRules::differences($fixture['zones'], $running['zones'])
        );
    }

    /**
     * Regression: with the fixture of PHP 8.5.12 (time zone data 2026.5),
     * PHP 8.5.13 reports America/Havana and Cuba, and the report names the
     * versions, why it matters, what to do and the command that writes the
     * fixture anew; zones only one side has are reported too.
     */
    public function testTheReportNamesTheZonesTheVersionsAndWhatToDo(): void
    {
        $utc = TimeZoneRules::fingerprint(new \DateTimeZone('UTC'));
        $fixture = self::rules('8.5.12', '2026.5', [
            'America/Havana' => self::HAVANA_2026_5,
            'Cuba' => self::HAVANA_2026_5,
            'Mars/Olympus' => $utc,
            'UTC' => $utc,
        ]);
        $running = self::rules('8.5.13', '2026.6', [
            'America/Havana' => self::HAVANA_2026_6,
            'Cuba' => self::HAVANA_2026_6,
            'Europe/Berlin' => $utc,
            'UTC' => $utc,
        ]);
        $differences = TimeZoneRules::differences($fixture['zones'], $running['zones']);
        $report = TimeZoneRules::report($fixture, $running, $differences);

        $this->assertSame(
            ['changed' => ['America/Havana', 'Cuba'], 'added' => ['Europe/Berlin'], 'removed' => ['Mars/Olympus']],
            $differences
        );
        $zones = 'America/Havana Cuba Europe/Berlin Mars/Olympus';
        foreach (
            [
                'differ from those of ' . TimeZoneRules::FIXTURE
                    . ' from 2026-11-20T00:00:00+00:00 to 2040-01-01T00:00:00+00:00.',
                "\nChanged: America/Havana, Cuba\nAdded: Europe/Berlin\nRemoved: Mars/Olympus\n",
                'This run: PHP 8.5.13 with time zone data 2026.6. The fixture: PHP 8.5.12 with time zone data 2026.5.',
                'stays off by the change until it has run',
                'diff <(docker run --rm -v "$PWD":/w:ro -w /w php:8.5.12-cli php tests/time-zone-rules.php ' . $zones
                    . ') <(docker compose exec -T app php tests/time-zone-rules.php ' . $zones . ')',
                'm000090_000000_recompute_schedule_next_runs_for_time_zone_data',
                'm000095_000000_recompute_schedule_next_runs_for_cuba',
                'docs/updating.md',
                'Write the fixture anew and commit it: ' . TimeZoneRules::WRITE,
            ] as $part
        ) {
            $this->assertStringContainsString($part, $report);
        }
    }

    /**
     * Regression (review of the guard): a run on a PHP older than the
     * fixture's, as on a stack built before the fixture was written, was
     * told to write a migration and the fixture anew, which commits the
     * older rules. It is told to build the images anew instead, whether its
     * time zone data or only its PHP is older.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function olderRunProvider(): array
    {
        return [
            'older time zone data' => ['8.5.12', '2026.5'],
            'older PHP with the same data' => ['8.5.12', '2026.6'],
            'data a PHP of the distribution reads from the system' => ['8.5.13', '0.system'],
        ];
    }

    /**
     * @dataProvider olderRunProvider
     */
    public function testRegressionARunOnAnOlderPhpIsToldToBuildTheImagesAnew(string $php, string $data): void
    {
        $fixture = self::rules('8.5.13', '2026.6', ['America/Havana' => self::HAVANA_2026_6]);
        $running = self::rules($php, $data, ['America/Havana' => self::HAVANA_2026_5]);
        $report = TimeZoneRules::report($fixture, $running, TimeZoneRules::differences($fixture['zones'], $running['zones']));

        $this->assertSame(
            sprintf(
                TimeZoneRules::OLDER_THAN_THE_FIXTURE,
                TimeZoneRules::FIXTURE,
                TimeZoneRules::FROM,
                TimeZoneRules::UNTIL,
                'Changed: America/Havana',
                $php,
                $data,
                '8.5.13',
                '2026.6'
            ),
            $report
        );
        $this->assertStringContainsString('docker compose up -d --build', $report);
        $this->assertStringContainsString('Do not write the fixture anew', $report);
        $this->assertStringNotContainsString(TimeZoneRules::WRITE, $report);
        $this->assertStringNotContainsString('write a migration', $report);
        $ci = (string)file_get_contents(self::root() . '/docs/ci.md');
        $this->assertStringContainsString("the message says `This PHP is older than the fixture's` instead", $ci, 'docs/ci.md names the message.');
        $this->assertStringContainsString("This PHP is older than the fixture's", TimeZoneRules::OLDER_THAN_THE_FIXTURE);
    }

    public function testTheReportListsOnlyTheKindsOfDifferenceThereAre(): void
    {
        $fixture = self::rules('8.5.12', '2026.5', ['Cuba' => self::HAVANA_2026_5]);
        $running = self::rules('8.5.13', '2026.6', ['Cuba' => self::HAVANA_2026_6]);
        $report = TimeZoneRules::report(
            $fixture,
            $running,
            TimeZoneRules::differences($fixture['zones'], $running['zones'])
        );

        $this->assertStringContainsString("\nChanged: Cuba\nThis run: PHP 8.5.13", $report);
        $this->assertStringNotContainsString('Added:', $report);
        $this->assertStringNotContainsString('Removed:', $report);
    }

    /** The migrations, the script and the docs the report sends a reader to exist. */
    public function testWhatTheReportNamesExists(): void
    {
        $root = self::root();
        foreach (self::MIGRATIONS_THE_REPORT_NAMES as $migration) {
            $this->assertStringContainsString($migration, TimeZoneRules::REPORT);
            $this->assertFileExists($root . '/migrations/' . $migration . '.php');
        }
        $this->assertFileExists($root . '/' . TimeZoneRules::SCRIPT);
        $this->assertFileExists($root . '/docs/updating.md');
        $this->assertStringContainsString('TimeZoneRulesTest', (string)file_get_contents($root . '/docs/ci.md'));
    }

    /**
     * The moments at which a zone's offset is compared with its rules, with
     * the offset the rules give: a second before and at each change, and
     * every SAMPLE_STEP seconds from the start of the window.
     *
     * @param list<array{at: int, offset: int}> $rules
     * @return array<int, int>
     */
    private static function moments(array $rules, int $until): array
    {
        $moments = [];
        foreach ($rules as $i => $rule) {
            if ($i > 0) {
                $moments[$rule['at'] - 1] = $rules[$i - 1]['offset'];
            }
            $moments[$rule['at']] = $rule['offset'];
        }
        $current = 0;
        for ($at = $rules[0]['at']; $at < $until; $at += self::SAMPLE_STEP) {
            while (isset($rules[$current + 1]) && $rules[$current + 1]['at'] <= $at) {
                $current++;
            }
            $moments[$at] = $rules[$current]['offset'];
        }

        return $moments;
    }

    /**
     * @param array<string, string> $zones
     * @return array{php: string, time_zone_data: string, from: string, until: string, zones: array<string, string>}
     */
    private static function rules(string $php, string $data, array $zones): array
    {
        return [
            'php' => $php,
            'time_zone_data' => $data,
            'from' => TimeZoneRules::FROM,
            'until' => TimeZoneRules::UNTIL,
            'zones' => $zones,
        ];
    }

    /**
     * The committed fixture.
     *
     * @return array{php: string, time_zone_data: string, from: string, until: string, zones: array<string, string>}
     */
    private static function fixture(): array
    {
        $path = self::root() . '/' . TimeZoneRules::FIXTURE;
        $rewrite = 'Write it anew: ' . TimeZoneRules::WRITE;
        self::assertFileExists($path, $rewrite);
        $fixture = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($fixture, 'The fixture is no JSON. ' . $rewrite);
        foreach (['php', 'time_zone_data', 'from', 'until'] as $key) {
            self::assertIsString($fixture[$key] ?? null, "The fixture has no {$key}. " . $rewrite);
        }
        self::assertIsArray($fixture['zones'] ?? null, 'The fixture has no zones. ' . $rewrite);
        $notText = array_keys(array_filter($fixture['zones'], static fn (mixed $value): bool => !is_string($value)));
        self::assertSame([], $notText, 'Zones of the fixture without a fingerprint. ' . $rewrite);

        /** @var array{php: string, time_zone_data: string, from: string, until: string,
         *     zones: array<string, string>} $fixture */
        return $fixture;
    }

    /**
     * What tests/time-zone-rules.php prints with $arguments, in a PHP
     * process of its own.
     *
     * @param list<string> $arguments
     */
    private function runScript(array $arguments): string
    {
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'display_errors=stderr', TimeZoneRules::SCRIPT], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "The script failed: {$stderr}");
        $this->assertSame('', $stderr);

        return $stdout;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
