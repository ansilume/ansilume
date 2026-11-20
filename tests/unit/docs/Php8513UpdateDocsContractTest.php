<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\AuditLog;
use app\services\ScheduleRunAs;
use app\tests\unit\TimeZoneRules;
use app\tests\unit\TimeZoneRulesTest;
use PHPUnit\Framework\TestCase;

/**
 * Contract: the update notes for PHP 8.5.13 (docs/updating.md) name the
 * flaws of PHP 8.5.12 that concern Ansilume and where each installation
 * gets the fix, say what the migration of this release does to the
 * schedules in Cuba, quote what it prints, tell whoever followed the
 * workaround of the update from 2.13.1 to move their schedules back to
 * America/Havana, and say how to go back; the rollback links to them.
 *
 * The update notes are history: what this release changed stays true when a
 * later one changes the code again. So they are checked against fixed text
 * where later work changes what they quote: the flaws of PHP 8.5.12 (the
 * changelog of PHP 8.5.13 of 2026-11-19, judged against the code of this
 * release: PHP-FPM behind nginx without a status page, sessions in Redis,
 * a runner that hands API_URL as written to PHP's HTTP stream wrapper in
 * RunnerController::actionStart() and RunnerHttpClient::httpPost(), the
 * notification channels on curl), the time zone data of 2.15.1 and the
 * migrations a rollback to 2.14.0 reverts. The lines the migration prints
 * are built from its constants, and the one for older time zone data is
 * also asked of the migration itself; the offsets of Cuba and Puerto Rico
 * the section gives are asked of the PHP that runs the test, which has the
 * time zone data the section names, or newer; and the rule that a change of
 * a schedule's time zone makes the saver the user it runs as is read from
 * ScheduleRunAs.
 */
class Php8513UpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const UPDATE_SECTION = '## After updating from 2.15.1 or older: PHP 8.5.13, schedules in Cuba';

    private const SECTION_ANCHOR = 'after-updating-from-2151-or-older-php-8513-schedules-in-cuba';

    private const UPDATING = 'docs/updating.md';

    private const RUNNERS = 'docs/runners.md';

    /** The migration of this release: the next runs of the schedules in Cuba, computed anew. */
    private const MIGRATION = 'm000095_000000_recompute_schedule_next_runs_for_cuba';

    /** The PHP of this release's images, and the one of the images of 2.15.1. */
    private const PHP = '8.5.13';

    private const PHP_OF_2151 = '8.5.12';

    /** The time zone data of PHP 8.5.13, and the one of PHP 8.5.12 (2.15.1). */
    private const TIME_ZONE_DATA = '2026.6';

    private const TIME_ZONE_DATA_OF_2151 = '2026.5';

    /**
     * A line the migration prints (its MOVED message), as the section
     * quotes it: 2 next runs moved, 1 of them to the moment of the update.
     * When MOVED changes, the section and this line change together.
     */
    private const MIGRATION_LINE = 'Computed anew the next runs of the schedules in America/Havana and Cuba that are not yet due,'
        . ' with time zone data 2026.6: 2 moved, 1 of them to now for a run that passed in the past hour.';

    /** What the migration prints with the time zone data of PHP 8.5.12 (its OLD_TIME_ZONE_DATA), as the section quotes it. */
    private const OLD_DATA_LINE = 'Moved no next run: the time zone data 2026.5 of this PHP turns the clocks of America/Havana'
        . ' and Cuba back on 2026-11-01, which 2026.6 and later do not.';

    /** How the section checks the PHP and its time zone data, run as www-data like every exec of the docs. */
    private const CHECK = 'docker compose exec -u www-data app php -r \'echo PHP_VERSION, " ", timezone_version_get(), PHP_EOL;\'';

    /**
     * The flaws of PHP 8.5.12 that the section names, as it describes them.
     * CVE-2026-129292 and CVE-2026-126714 are named as flaws that do not
     * reach Ansilume.
     */
    private const FLAWS = [
        'CVE-2026-129289' => 'request smuggling through a request body that the script does not read',
        'CVE-2026-129291' => 'could raise its rights to those of PHP-FPM\'s master process, which runs as root',
        'CVE-2026-129292' => 'Its status page, whose fields 8.5.12 did not escape',
        'CVE-2026-129481' => 'a use-after-free when the end of a session is interrupted',
        'CVE-2026-115560' => 'a mismatch of session serializers, which PHP had not fixed when 2.14.0 turned off upload progress against it',
        'CVE-2026-126714' => 'The flaw of session files',
        'CVE-2026-129474' => 'use-after-frees in the backtraces of magic methods',
        'CVE-2026-129182' => 'in the backtrace of a fatal error after shutdown',
        'CVE-2026-129496' => 'PHP\'s cache of time zones',
        'CVE-2026-128064' => 'whose scheme is not written in lowercase, such as `HTTPS://ansilume.example.com`, in plain text',
        'CVE-2026-127960' => 'a server that answers with folded headers',
    ];

    /**
     * Offsets the section gives: zone, local time, offset. Cuba at UTC-4
     * through the winter of 2026, where 2.15.1 had UTC-5, and at UTC-5 from
     * 2027-11-07, where Puerto Rico stays at UTC-4.
     */
    private const OFFSETS = [
        ['America/Havana', '2026-12-01 09:00', '-04:00'],
        ['Cuba', '2027-01-15 09:00', '-04:00'],
        ['America/Havana', '2027-11-08 09:00', '-05:00'],
        ['America/Puerto_Rico', '2027-11-08 09:00', '-04:00'],
    ];

    /**
     * The section is the newest, the one of the update to Redis 8.10
     * follows, and the rollback links to it under the anchor GitHub gives
     * its heading. A failed build of the manual source update goes back to
     * the release it updated from, 2.15.1 first.
     */
    public function testTheSectionIsTheNewest(): void
    {
        $this->assertUpdateSectionBetween(null, self::UPDATE_SECTION, Redis810UpdateDocsContractTest::UPDATE_SECTION);
        $this->assertSame(self::SECTION_ANCHOR, self::anchor(self::UPDATE_SECTION), 'The anchor GitHub gives the heading.');
        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $this->flat(self::UPDATING, '## Rollback'), 'the rollback');

        $update = $this->flat(self::UPDATING, '## Manual update — source (git)');
        $this->assertMentions(
            'go back to the release you updated from until a build gets through (`git checkout v2.15.1` from 2.15.1,',
            $update,
            'the manual source update'
        );
    }

    /**
     * The flaws of PHP 8.5.12 that concern Ansilume, each with its CVE ID,
     * those that do not reach it said so, and the parts of the other flaws
     * that Ansilume does not use. The section links to the item of the
     * update from 2.13.1 that turned off upload progress against
     * CVE-2026-115560, which PHP had not fixed then.
     */
    public function testTheSectionNamesTheFlawsThatConcernAnsilume(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $this->assertMentions(
            '**PHP 8.5.13 fixes security flaws.** The app and runner images run PHP ' . self::PHP
                . ' of 2026-11-19 instead of PHP ' . self::PHP_OF_2151 . ', the PHP of the images of 2.15.1.',
            $section,
            'the update notes'
        );
        foreach (self::FLAWS as $cve => $flaw) {
            $this->assertMentions($cve, $section, 'the update notes');
            $this->assertMentions($flaw, $section, "the update notes, on {$cve}");
        }
        foreach (
            [
                'is off in the images: they set no `pm.status_path`.',
                'does not reach Ansilume, which keeps no session in a file.',
                '`session.upload_progress.enabled = Off` stays, as Ansilume shows no upload progress.',
                'a runner sends its requests to the server with PHP\'s HTTP stream wrapper (`file_get_contents()` with a'
                    . ' stream context), and hands it `API_URL` as written.',
                'The notification channels and the outbound webhooks send with curl, which these two do not concern.',
                'The other flaws that 8.5.13 fixes are in parts that Ansilume does not use: PHP-CGI, SOAP, the signatures'
                    . ' of Phar archives, the DOM serializer and the URI extension.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $this->assertMentions(
            '(see **PHP-FPM tracks no upload progress** under [After updating from 2.13.1 or older](#'
                . self::anchor(Php85UpdateDocsContractTest::UPDATE_SECTION) . ')).',
            $section,
            'the update notes'
        );
        $notesOf2131 = $this->flat(self::UPDATING, Php85UpdateDocsContractTest::UPDATE_SECTION);
        $this->assertMentions('**PHP-FPM tracks no upload progress.**', $notesOf2131, 'the update notes of 2.13.1');
        $this->assertMentions('CVE-2026-115560, which PHP has not fixed yet', $notesOf2131, 'the update notes of 2.13.1');
    }

    /**
     * Where each installation gets PHP 8.5.13, how to check it, and what a
     * runner installed without Docker does until its host has it: it hands
     * API_URL to PHP as written, so its scheme must be lowercase, and one
     * that was not may have sent its token in plain text. The PHP the tests
     * run on, built as the images are, is the one the section names, or
     * newer.
     */
    public function testTheSectionSaysWhereEachInstallationGetsIt(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $this->assertMentions(self::CHECK . ' # ' . self::PHP . ' ' . self::TIME_ZONE_DATA, $section, 'the update notes');
        $this->assertMentions(
            str_replace(' app ', ' runner-1 ', self::CHECK) . ' # the same for each runner',
            $section,
            'the update notes'
        );
        $this->assertTrue(version_compare(PHP_VERSION, self::PHP, '>='), 'The PHP the tests run on has the fixes the section names.');
        $this->assertTrue(
            version_compare(timezone_version_get(), self::TIME_ZONE_DATA, '>='),
            'The PHP the tests run on has the time zone data the section names, or newer.'
        );
        foreach (
            [
                '**Prebuilt images:** the update pulls the images of this release; there is nothing else to do.',
                'Pin no server or runner to 2.15.1 or older (`ANSILUME_IMAGE_TAG`).',
                'builds the images on the current `php:8.5-fpm`, as every build pulls its base image (`pull: true`)',
                'A `docker compose up -d` without `--build` keeps the images of 2.15.1, with PHP ' . self::PHP_OF_2151 . '.',
                '**The deploy role** builds the images at each run (`build: always`) on the current base images (`pull: true`)',
                '**Images of your own:** one built `FROM` the Ansilume images gets PHP ' . self::PHP . ' with its next build',
                '**A runner installed without Docker** (see [runners.md](runners.md#running-without-docker)) runs the PHP of'
                    . ' its host, which the update does not change: update that PHP to ' . self::PHP,
                // Regression (review): the runner keeps the PHP and the .env it started with.
                'and restart the runner (`sudo systemctl restart ansilume-runner`): the runner that runs keeps the PHP it'
                    . ' started with.',
                'Until then write the scheme of its `API_URL` in lowercase in its `.env`, `https://`, not `HTTPS://`, and'
                    . ' restart it too: the runner reads `.env` only when it starts.',
                // Regression (review): every runner PHP before 8.5.13 has the flaw, and a runner that registers
                // itself sends the bootstrap secret to the same URL.
                'A runner on PHP older than ' . self::PHP . ' (the runner images of 2.15.1 and every release before it,'
                    . ' those of 2.13.1 on PHP 8.3 too, and a runner without Docker on an older PHP of its host) whose'
                    . ' `API_URL` starts with `HTTPS://`, or with `https` in another mix of cases, may have sent its secrets'
                    . ' in plain text and received the credentials of its jobs so: its runner token, and, when it registers'
                    . ' itself, `RUNNER_BOOTSTRAP_SECRET`.',
                'regenerate its token and rotate those credentials, as for a runner on plain HTTP (see'
                    . ' [runners.md](runners.md#plain-http-warning)).',
                'set a new `RUNNER_BOOTSTRAP_SECRET` on the server and on every runner that uses it',
                'check the audit log for `' . AuditLog::ACTION_RUNNER_CREATED . '` and `'
                    . AuditLog::ACTION_RUNNER_REREGISTERED . '` entries you cannot account for.',
                '`runner` in [runners.md](runners.md#docker-compose-standalone-runner)',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['## Running Without Docker', '## Plain HTTP warning', '## Docker Compose (Standalone Runner)'] as $heading) {
            $this->assertNotSame('', $this->section(self::RUNNERS, $heading), "runners.md has {$heading}");
        }
    }

    /**
     * Regression: 2.15.1 (PHP 8.5.12, time zone data 2026.5) stores and runs
     * the schedules in Cuba an hour late from 2026-11-01 to 2027-03-14. The
     * section says why, names the migration that moves their next runs, the
     * zones it moves them in, the runs it keeps, and quotes the line it
     * prints, built from its MOVED message with the time zone data of PHP
     * 8.5.13.
     */
    public function testTheSectionSaysWhatTheUpdateDoesToTheSchedulesInCuba(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $this->assertSame(self::TIME_ZONE_DATA, \m000095_000000_recompute_schedule_next_runs_for_cuba::TIME_ZONE_DATA);
        $this->assertSame(['America/Havana', 'Cuba'], \m000095_000000_recompute_schedule_next_runs_for_cuba::ZONES);
        $this->assertSame(
            sprintf(\m000095_000000_recompute_schedule_next_runs_for_cuba::MOVED, self::TIME_ZONE_DATA, 2, 1),
            self::MIGRATION_LINE,
            'The section quotes a line the migration prints: quote its MOVED message anew when it changes.'
        );
        $this->assertMentions('`' . self::MIGRATION_LINE . '`', $section, 'the update notes');
        foreach (
            [
                '**Schedules in Cuba run at their time again.**',
                'PHP ' . self::PHP_OF_2151 . ', which 2.15.1 runs on, has ' . self::TIME_ZONE_DATA_OF_2151 . ' (IANA\'s 2026e),'
                    . ' and PHP ' . self::PHP . ' has ' . self::TIME_ZONE_DATA . ' (IANA\'s 2026f, of 2026-10-27).',
                'the clocks of Cuba, `America/Havana` and its alias `Cuba`, back to UTC-5 on 2026-11-01 and forward to'
                    . ' UTC-4 again on 2027-03-14',
                'By ' . self::TIME_ZONE_DATA . ' Cuba stays at UTC-4 from 2026-03-08 until 2027-11-07 and turns its clocks'
                    . ' back to UTC-5 then, as before',
                'Between 2020 and 2040 they differ in no other zone.',
                '2.15.1 stores and runs the schedules in Cuba an hour late from 2026-11-01 to 2027-03-14: 9:00 at 14:00'
                    . ' UTC, where 9:00 in Havana is 13:00 UTC.',
                '(migration `' . self::MIGRATION . '`)',
                'when its time came after the schedule\'s last run and its last change, the schedule\'s next run becomes'
                    . ' the moment of the update, so the scheduler launches it as soon as the migrations have run',
                'The same holds for a run that 2.15.1 stored after a run it launched an hour late, as 9:00 of'
                    . ' `0 8,9 * * *` after 8:00.',
                'A disabled schedule launches nothing and owes no run: it gets the first run after the update, and'
                    . ' enabling it computes its next run from then on.',
                'A schedule that is due keeps its next run, which the scheduler launches at once, one run for all the'
                    . ' runs that passed',
                'Schedules in other zones keep their next runs, those in `America/Puerto_Rico` too.',
                'An update from 2027-03-14 06:00 UTC on (2:00 in Havana) moves nothing',
                'An update from 2.13.1 or older runs `m000090` first',
                'The notes of the update from 2.13.1 warned of it (see [After updating from 2.13.1 or older](#'
                    . self::anchor(Php85UpdateDocsContractTest::UPDATE_SECTION) . ')).',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertOffsets(array_slice(self::OFFSETS, 0, 3));
        if (self::hasTheRulesOfTheSection()) {
            $nine = new \DateTimeImmutable('2026-12-01 09:00', new \DateTimeZone('America/Havana'));
            $this->assertSame('13:00', $nine->setTimezone(new \DateTimeZone('UTC'))->format('H:i'), '9:00 in Havana is 13:00 UTC.');
        }
    }

    /**
     * Regression: an app container that starts the new code on PHP 8.5.12,
     * as one of a source installation that starts again before its build
     * got through, runs the migration with the time zone data 2026.5, which
     * moves nothing and counts as applied. The section quotes what it prints
     * then, as the migration prints it, and says to run it again once the
     * container runs PHP 8.5.13.
     */
    public function testTheSectionQuotesWhatTheMigrationPrintsOnOlderTimeZoneData(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        // Built without its constructor, so that no database is resolved:
        // with older time zone data the summary moves nothing.
        $migration = (new \ReflectionClass(\m000095_000000_recompute_schedule_next_runs_for_cuba::class))->newInstanceWithoutConstructor();

        $this->assertSame(
            sprintf(
                \m000095_000000_recompute_schedule_next_runs_for_cuba::OLD_TIME_ZONE_DATA,
                self::TIME_ZONE_DATA_OF_2151,
                self::TIME_ZONE_DATA
            ),
            self::OLD_DATA_LINE
        );
        $this->assertSame(self::OLD_DATA_LINE, $migration->summaryAt(self::TIME_ZONE_DATA_OF_2151, (int)strtotime('2026-11-20 12:00 UTC')));
        $this->assertMentions(
            'With time zone data older than ' . self::TIME_ZONE_DATA . ', as that of PHP ' . self::PHP_OF_2151
                . ', the migration moves nothing and says so: `' . self::OLD_DATA_LINE . '`',
            $section,
            'the update notes'
        );
        foreach (
            [
                'in a source installation whose app container starts again after `git pull` before step 3 has built the images',
                'The migration counts as applied all the same, and each next run stored for a time from 2026-11-01 to'
                    . ' 2027-03-14 stays an hour late until its schedule has run.',
                'Once the app container runs PHP ' . self::PHP . ', run the migration once more, as **Going back** below says',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
    }

    /**
     * The update from 2.13.1 suggested America/Puerto_Rico, at UTC-4 all
     * year, for Cuba's times until a release brings IANA's 2026f: this is
     * that release, so the section says to move such schedules back, why
     * (Cuba goes back to UTC-5 on 2027-11-07, Puerto Rico does not), and
     * that the change makes the saver the user the schedule runs as, as
     * ScheduleRunAs counts a change of the time zone.
     */
    public function testTheSectionTellsToMoveSchedulesBackFromPuertoRico(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $this->assertMentions(
            'until an Ansilume release brings a PHP with 2026f and computes their next runs anew. Until then a schedule'
                . ' in `America/Puerto_Rico`, at UTC-4 all year, runs at Cuba\'s times',
            $this->flat(self::UPDATING, Php85UpdateDocsContractTest::UPDATE_SECTION),
            'the update notes of 2.13.1'
        );
        foreach (
            [
                'change such schedules back to `America/Havana` now.',
                'Puerto Rico stays at UTC-4, while Cuba turns its clocks back to UTC-5 on 2027-11-07',
                'The update does not change their zone.',
                'Changing a schedule\'s time zone makes the user who saves it the user it runs as, who then never'
                    . ' approves its runs; the schedule runs from that save on.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertStringContainsString('timezone', ScheduleRunAs::VIEW_NOTE, 'A change of the time zone makes the saver the user it runs as.');
        $this->assertStringContainsString('becomes the user it runs as', ScheduleRunAs::VIEW_NOTE);
        $this->assertOffsets(array_slice(self::OFFSETS, 2));
    }

    /**
     * The offsets of $offsets (zone, local time, offset) by the PHP the tests
     * run on, while its time zone data has the rules for Cuba the section
     * describes (hasTheRulesOfTheSection()).
     *
     * @param list<array{0: string, 1: string, 2: string}> $offsets
     */
    private function assertOffsets(array $offsets): void
    {
        if (!self::hasTheRulesOfTheSection()) {
            return;
        }
        foreach ($offsets as [$zone, $time, $offset]) {
            $this->assertSame($offset, (new \DateTimeImmutable($time, new \DateTimeZone($zone)))->format('P'), "{$zone} on {$time}");
        }
    }

    /**
     * Whether the PHP the tests run on has the rules of America/Havana that
     * the section describes, those of 2026.6 (TimeZoneRulesTest::HAVANA_2026_6).
     * Its offsets are history: they hold for 2026.6 whatever later data says
     * of Cuba. Review finding: they were checked against the data of any PHP,
     * and dates such as 2027-11-08 are forecasts that later data can revise,
     * as 2026f revised 2026e's for 2026-11-01, which would have failed a
     * section that stays true.
     */
    private static function hasTheRulesOfTheSection(): bool
    {
        return TimeZoneRules::fingerprint(new \DateTimeZone('America/Havana')) === TimeZoneRulesTest::HAVANA_2026_6;
    }

    /**
     * Going back needs nothing for the database, as the migration's down
     * changes nothing; going back further reverts it first with this
     * release; updating again after going back runs it once more while it
     * is the newest; every command as www-data. The rollback names the
     * migrations that a way back to 2.14.0 reverts with it: those of
     * 2.15.0, right before it.
     */
    public function testTheSectionAndTheRollbackSayHowToGoBack(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $migration = (new \ReflectionClass(\m000095_000000_recompute_schedule_next_runs_for_cuba::class))->newInstanceWithoutConstructor();

        $this->assertTrue($migration->safeDown(), 'Its down changes nothing and succeeds.');
        foreach (
            [
                '**Going back.** Going back to 2.15.1 needs nothing for the database: `m000095` changes no schema, its'
                    . ' `migrate/down` changes nothing',
                'The prebuilt images of 2.15.1 run PHP ' . self::PHP_OF_2151 . ', with the flaws that ' . self::PHP . ' fixes',
                'compute the runs in Cuba with ' . self::TIME_ZONE_DATA_OF_2151 . ' again',
                '(`ANSILUME_IMAGE_TAG=2.15.1`, see [Rollback](#rollback)) only for as long as you must.',
                'both build 2.15.1 on the current `php:8.5-fpm`, that is on PHP ' . self::PHP,
                'To go back further than 2.15.1, first revert the migration of this release with this release,'
                    . ' `docker compose exec -u www-data app php yii migrate/down 1 --interactive=0`',
                // Regression (review): going back to 2.13.1 left m000090 applied, so its notes' advice to run it
                // again after the next update never applied, as m000095 is the newest migration then.
                'Going back to 2.14.0, `migrate/down 5` with this release reverts it and the four migrations of 2.15.0'
                    . ' together',
                'Going back to 2.13.1 or older, run `migrate/down 6` instead, which reverts `m000090` too, whose down'
                    . ' changes nothing either: the next update then runs it again',
                'when `docker compose exec -u www-data app php yii migrate/history 1` names `' . self::MIGRATION . '`,'
                    . ' `docker compose exec -u www-data app php yii migrate/redo 1 --interactive=0` reverts it, which'
                    . ' changes nothing, and runs it again.',
                'An installation the quickstart made from source adds `-f docker-compose.prebuilt.yml` to these commands',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $rollback = $this->flat(self::UPDATING, '## Rollback');
        $this->assertMentions(
            'To roll back from the update after 2.15.1 (PHP ' . self::PHP . ', and the migration `m000095`, which computes'
                . ' the next runs of the schedules in Cuba anew), see **Going back** at the end of'
                . ' [After updating from 2.15.1 or older](#' . self::SECTION_ANCHOR . ')',
            $rollback,
            '## Rollback'
        );
        $this->assertMentions(
            'revert `m000095` first, with the release that has it; `migrate/down 5` there reverts `m000094` to `m000091` as'
                . ' well, as going back to 2.14.0 needs, and `migrate/down 6` `m000090` too, as going back to 2.13.1 or older'
                . ' needs.',
            $rollback,
            '## Rollback'
        );
        $between = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob(dirname(__DIR__, 3) . '/migrations/m00009[0-5]_*.php') ?: []
        );
        sort($between);
        $this->assertSame(
            [
                'm000090_000000_recompute_schedule_next_runs_for_time_zone_data',
                'm000091_000000_notification_delivery_and_options',
                'm000092_000000_add_job_recovery_index',
                'm000093_000000_add_notification_delivery_refs',
                'm000094_000000_add_lint_queued_at',
                self::MIGRATION,
            ],
            $between,
            '`migrate/down 5` from m000095 reverts the four migrations of 2.15.0 too, and `migrate/down 6` m000090 as well.'
        );
    }

    /**
     * Regression: the release scan stopped 2.15.2, as the nginx image carried
     * the OpenSSL and zlib of nginx:1.30-alpine-slim of 2026-11-18, which
     * Alpine 3.24 had fixed. The section says that the image takes the fixes
     * of its Alpine branch now, that 2.15.2 has no release and must not be
     * pinned, and which installations run the upstream image as published;
     * docs/releasing.md says why the image upgrades its packages.
     */
    public function testTheSectionSaysThatTheNginxImageTakesTheFixesOfAlpine(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        foreach (
            [
                '**The nginx image takes the fixes of its Alpine branch.** The prebuilt nginx image installs the updates'
                    . ' of its Alpine branch when it is built (`apk upgrade --no-cache`).',
                'The release scan stopped 2.15.2 for them: its images were built, but it has no GitHub release, and'
                    . ' `latest` stayed on 2.15.1. Do not install or pin 2.15.2; this release has its changes.',
                'run `nginx:1.30-alpine-slim` as Docker Hub publishes it',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $root = dirname(__DIR__, 3);
        $this->assertStringContainsString("\nRUN apk upgrade --no-cache\n", (string)file_get_contents($root . '/docker/nginx/Dockerfile.prod'));
        $this->assertMentions(
            'so the nginx image installs the updates of its Alpine branch when it is built (`apk upgrade --no-cache`',
            $this->flat('docs/releasing.md', '### Vulnerability scan'),
            'docs/releasing.md'
        );
    }

    /**
     * The anchor GitHub gives a heading.
     */
    private static function anchor(string $heading): string
    {
        return str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(ltrim($heading, '# '))));
    }
}
