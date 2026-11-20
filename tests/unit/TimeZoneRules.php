<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * The rules of the time zones a schedule can run in, as the PHP that runs
 * reads them from the time zone data it bundles: for TimeZoneRulesTest, and
 * for tests/time-zone-rules.php, which writes its fixture and shows the
 * rules of single zones.
 *
 * A release of PHP can bring time zone data that changes the rules of a
 * zone for the dates to come: PHP 8.5.13 (data 2026.6) keeps America/Havana
 * at UTC-4 from 2026-11-01 to 2027-03-14, where 8.5.12 (2026.5) turned its
 * clocks back an hour. A schedule's next run is stored as a moment, computed
 * with the rules of the PHP that stored it, so each next run stored in such
 * a zone stays off by the change until it has run, unless a migration
 * computes it anew (m000090, m000095).
 *
 * The rules of a zone here are what decides the runs of its schedules
 * (CronRuns): its offset from UTC at every moment from FROM to UNTIL, as the
 * offset at FROM and each moment it changes, with the offset after it. A
 * change of the abbreviation or of the DST flag alone moves no run and is
 * left out. The changes are read from getTransitions(), as CronRuns reads
 * them. It returns false for a UTC offset (+02:00, and the identifiers GMT+0
 * and GMT-0) and for an abbreviation (pst, CEST; PHP reads the identifiers
 * CET, EET, EST, GMT, HST, MET, MST, UCT and WET as abbreviations too):
 * PHP keeps one offset for such a zone, the same at every moment, so its
 * rules are its offset at FROM, as CronRuns reads it (getOffset()). A zone
 * of the time zone data whose changes PHP does not list is refused
 * (NO_CHANGES): one offset would leave its changes out.
 *
 * The zones are every identifier of the time zone data (the aliases of
 * DateTimeZone::ALL_WITH_BC, such as Cuba and Canada/Pacific, included) and
 * every abbreviation PHP reads (listAbbreviations()), each once whatever
 * its case, as PHP reads names without case: the names Schedule's timezone
 * rule takes but UTC offsets, whose offset no time zone data changes.
 */
final class TimeZoneRules
{
    /** Where the window of the rules starts: the stored next runs lie after it. */
    public const FROM = '2026-11-20T00:00:00+00:00';

    /** Where it ends: far enough for the yearly and leap-day expressions. */
    public const UNTIL = '2040-01-01T00:00:00+00:00';

    /** The fixture, from the root of the repository. */
    public const FIXTURE = 'tests/fixtures/time-zone-rules.json';

    /** The script that writes the fixture, and shows the rules of the zones it is given. */
    public const SCRIPT = 'tests/time-zone-rules.php';

    /** The command that writes the fixture anew, run from the root of the repository. */
    public const WRITE = 'docker compose exec -T app php ' . self::SCRIPT . ' > ' . self::FIXTURE;

    /** What differences() gives when the rules of every zone are those of the fixture. */
    public const NO_DIFFERENCES = ['changed' => [], 'added' => [], 'removed' => []];

    /** sprintf(): a zone of the time zone data for which PHP lists no changes of the clock. */
    public const NO_CHANGES = 'PHP lists no changes of the clock for the time zone %s, which is no UTC offset'
        . ' and no abbreviation: its rules cannot be read.';

    /** sprintf(): a line of describe(), the zone, its offset and when it starts. */
    public const RULE_LINE = '%s UTC%s from %s';

    /** sprintf(): a line of describe() for a zone the running PHP does not know. */
    public const UNKNOWN_LINE = '%s unknown to this PHP';

    /**
     * sprintf(): the report of rules that differ from the fixture's: the
     * fixture, the window, a line per kind of difference, the PHP and time
     * zone data of the run and of the fixture, the PHP image of the
     * fixture's PHP, the zones, and the command that writes the fixture.
     */
    public const REPORT = 'The rules of time zones differ from those of %1$s from %2$s to %3$s.' . "\n"
        . '%4$s' . "\n"
        . 'This run: PHP %5$s with time zone data %6$s. The fixture: PHP %7$s with time zone data %8$s.' . "\n\n"
        . 'Why it matters: PHP bundles its own time zone data. A schedule\'s next run is stored as a moment,'
        . ' computed with the rules of the PHP that stored it, so each next run that the release before'
        . ' stored in a changed zone stays off by the change until it has run (2.15.1, on PHP 8.5.12, runs'
        . ' the schedules in America/Havana an hour late from 2026-11-01 to 2027-03-14). A schedule in a'
        . ' removed zone can no longer compute its runs. An added zone moves no stored run, but the'
        . ' fixture must cover it.' . "\n\n"
        . 'What to do:' . "\n"
        . '1. See how the rules of these zones changed, for the dates after the last release:' . "\n"
        . '   diff <(docker run --rm -v "$PWD":/w:ro -w /w php:%9$s-cli php ' . self::SCRIPT . ' %10$s)'
        . ' <(docker compose exec -T app php ' . self::SCRIPT . ' %10$s)' . "\n"
        . '2. Where a stored next run moves, write a migration that computes the next runs of the'
        . ' schedules in these zones anew, as m000090_000000_recompute_schedule_next_runs_for_time_zone_data'
        . ' and m000095_000000_recompute_schedule_next_runs_for_cuba do: it keeps a run that passed in the'
        . ' hour between its new time and the time stored, and never moves a next run earlier where the'
        . ' old data ran it early. Write update notes for it in docs/updating.md.' . "\n"
        . '3. Write the fixture anew and commit it: %11$s';

    /**
     * sprintf(), with the arguments of REPORT: the report when the PHP of
     * the run, or its time zone data, is older than the fixture's, as on a
     * stack built before the fixture was written. Its steps would write the
     * older rules into the fixture.
     *
     * Regression (review of the guard): the report told every run to write
     * a migration and the fixture anew, also a run on an older PHP.
     */
    public const OLDER_THAN_THE_FIXTURE = 'The rules of time zones differ from those of %1$s from %2$s to %3$s.' . "\n"
        . '%4$s' . "\n"
        . 'This run: PHP %5$s with time zone data %6$s. The fixture: PHP %7$s with time zone data %8$s.' . "\n\n"
        . 'This PHP is older than the fixture\'s: build the images anew on the current base images'
        . ' (docker compose up -d --build; the compose files build with pull) and run the tests again.'
        . ' Do not write the fixture anew: that would commit the older rules.';

    /** sprintf(): a line of the report, the kind of difference and its zones. */
    public const REPORT_LINE = '%s: %s';

    /** How many hex digits of its SHA-256 a zone's fingerprint keeps. */
    private const FINGERPRINT_LENGTH = 16;

    /** The timezone_type of a zone of the time zone data (1 is a UTC offset, 2 an abbreviation). */
    private const ZONE_OF_THE_DATA = 3;

    /**
     * The zones a schedule can run in whose offset comes from the time zone
     * data, sorted: every identifier, and every abbreviation whose name is
     * no identifier's, whatever the case.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $identifier) {
            $names[strtolower($identifier)] = $identifier;
        }
        foreach (array_keys(\DateTimeZone::listAbbreviations()) as $abbreviation) {
            $names[strtolower((string)$abbreviation)] ??= (string)$abbreviation;
        }
        $names = array_values($names);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The rules of $zone from FROM to UNTIL: its offset at FROM, and each
     * moment its offset changes, with the offset after it.
     *
     * @return list<array{at: int, offset: int}>
     * @throws \LogicException for a zone of the time zone data whose changes PHP does not list
     */
    public static function rulesOf(\DateTimeZone $zone): array
    {
        $from = self::timestamp(self::FROM);
        $until = self::timestamp(self::UNTIL);
        $offset = $zone->getOffset(new \DateTimeImmutable('@' . $from));
        $rules = [['at' => $from, 'offset' => $offset]];
        $transitions = $zone->getTransitions($from, $until);
        if ($transitions === false) {
            if ($zone->__serialize()['timezone_type'] === self::ZONE_OF_THE_DATA) {
                throw new \LogicException(sprintf(self::NO_CHANGES, $zone->getName()));
            }

            return $rules;
        }
        foreach ($transitions as $transition) {
            if ($transition['ts'] > $from && $transition['ts'] < $until && $transition['offset'] !== $offset) {
                $offset = $transition['offset'];
                $rules[] = ['at' => $transition['ts'], 'offset' => $offset];
            }
        }

        return $rules;
    }

    /** A short hash of the rules of $zone (rulesOf()). */
    public static function fingerprint(\DateTimeZone $zone): string
    {
        $text = implode(' ', array_map(
            static fn (array $rule): string => $rule['at'] . ':' . $rule['offset'],
            self::rulesOf($zone)
        ));

        return substr(hash('sha256', $text), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * The fixture as the running PHP writes it: its version, the version of
     * its time zone data, the window, and the fingerprint of every zone.
     *
     * @return array{php: string, time_zone_data: string, from: string, until: string,
     *     zones: array<string, string>}
     */
    public static function ofRunningPhp(): array
    {
        $zones = [];
        foreach (self::names() as $name) {
            $zones[$name] = self::fingerprint(new \DateTimeZone($name));
        }

        return [
            'php' => PHP_VERSION,
            'time_zone_data' => timezone_version_get(),
            'from' => self::FROM,
            'until' => self::UNTIL,
            'zones' => $zones,
        ];
    }

    /**
     * The text of a fixture: JSON with its zones sorted, the same bytes for
     * the same rules.
     *
     * @param array{php: string, time_zone_data: string, from: string, until: string,
     *     zones: array<string, string>} $rules
     */
    public static function json(array $rules): string
    {
        ksort($rules['zones'], SORT_STRING);

        return json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * The zones whose fingerprints differ between a fixture and the running
     * PHP, and those only one of them has.
     *
     * @param array<string, string> $fixture the fingerprints of the fixture, by zone
     * @param array<string, string> $running those of the running PHP
     * @return array{changed: list<string>, added: list<string>, removed: list<string>}
     */
    public static function differences(array $fixture, array $running): array
    {
        $changed = [];
        foreach (array_intersect_key($running, $fixture) as $name => $fingerprint) {
            if ($fingerprint !== $fixture[$name]) {
                $changed[] = (string)$name;
            }
        }

        return [
            'changed' => $changed,
            'added' => array_map('strval', array_keys(array_diff_key($running, $fixture))),
            'removed' => array_map('strval', array_keys(array_diff_key($fixture, $running))),
        ];
    }

    /**
     * What the test says when the rules differ from the fixture's: the
     * zones, the versions of both, why it matters and what to do; or, when
     * the PHP of the run or its time zone data is older than the fixture's,
     * to build the images anew (OLDER_THAN_THE_FIXTURE).
     *
     * @param array{php: string, time_zone_data: string} $fixture
     * @param array{php: string, time_zone_data: string} $running
     * @param array{changed: list<string>, added: list<string>, removed: list<string>} $differences
     */
    public static function report(array $fixture, array $running, array $differences): string
    {
        $lines = [];
        foreach ($differences as $kind => $names) {
            if ($names !== []) {
                $lines[] = sprintf(self::REPORT_LINE, ucfirst($kind), implode(', ', $names));
            }
        }

        $older = version_compare($running['time_zone_data'], $fixture['time_zone_data'], '<')
            || version_compare($running['php'], $fixture['php'], '<');

        return sprintf(
            $older ? self::OLDER_THAN_THE_FIXTURE : self::REPORT,
            self::FIXTURE,
            self::FROM,
            self::UNTIL,
            implode("\n", $lines),
            $running['php'],
            $running['time_zone_data'],
            $fixture['php'],
            $fixture['time_zone_data'],
            $fixture['php'],
            implode(' ', array_merge(...array_values($differences))),
            self::WRITE
        );
    }

    /**
     * The rules of the named zones, a line per offset, to compare the
     * rules of two PHPs with diff.
     *
     * @param list<string> $names
     */
    public static function describe(array $names): string
    {
        $lines = [];
        foreach ($names as $name) {
            try {
                $zone = new \DateTimeZone($name);
            } catch (\DateInvalidTimeZoneException $e) {
                $lines[] = sprintf(self::UNKNOWN_LINE, $name);
                continue;
            }
            foreach (self::rulesOf($zone) as $rule) {
                $lines[] = sprintf(
                    self::RULE_LINE,
                    $name,
                    self::offsetText($rule['offset']),
                    gmdate('Y-m-d\TH:i:s\Z', $rule['at'])
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** An offset in seconds as text: +05:30, -04:00, and +00:00:30 for one with seconds. */
    private static function offsetText(int $offset): string
    {
        $seconds = abs($offset);
        $text = sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $seconds % 60 === 0 ? $text : sprintf('%s:%02d', $text, $seconds % 60);
    }

    private static function timestamp(string $moment): int
    {
        return (new \DateTimeImmutable($moment))->getTimestamp();
    }
}
