<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\ScheduleController;
use app\components\CronExpressionRules;
use app\components\ProjectFilesNote;
use app\helpers\ProjectTreeHelper;
use app\models\Schedule;
use app\services\ArtifactArchive;
use app\services\ArtifactService;
use PHPUnit\Framework\TestCase;

/**
 * Contract: what docs/updating.md, troubleshooting.md, artifacts.md and the
 * OpenAPI spec say about refused cron expressions, the runs on the nights
 * the clocks change, the project page's paths the web server cannot read,
 * and artifact files that are gone or cannot be read is what the code
 * writes (CronExpressionRules, Schedule, CronRuns, m000089, ProjectController,
 * ArtifactService, ArtifactArchive), so the docs move with the messages.
 */
class UnreadableFilesAndCronDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATING = 'docs/updating.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const CRON_SECTION = '## Saving a schedule is refused: the cron expression';

    private const CLOCKS_SECTION = '## A schedule ran twice, or an hour early, on the night the clocks went back';

    private const PROJECT_SECTION = '## Project page: the web server cannot read paths';

    private const ARTIFACT_SECTION = '## Artifact download answers 404';

    /**
     * The update notes name every kind of refused expression with the
     * messages of the rules, and how to find the schedules saved before.
     */
    public function testTheUpdateNotesQuoteTheCronMessages(): void
    {
        $notes = $this->flat(self::UPDATING, PhpUpdateDocsContractTest::UPDATE_SECTION);

        $this->assertMentions(self::upTo(sprintf(CronExpressionRules::RANGE_BACKWARDS, '5-1', 'minute', '0-1,5-59'), 'backwards.'), $notes, 'the update notes');
        $this->assertMentions(self::upTo(sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18'), 'hour field.'), $notes, 'the update notes');
        $this->assertMentions(self::upTo(CronExpressionRules::NEVER_RUNS, 'would never run.'), $notes, 'the update notes');
        $this->assertMentions(CronExpressionRules::INVALID, $notes, 'the update notes');
        foreach (['`*/90 * * * *`', '`MON-FRI/2`', '`7-7`', '`0-0`', '`SUN-SAT`', '`0-SAT`', '`SAT-SUN`', '`SAT-7`', '`0-7/2`', '`0-6/2`', '`5-0`', '`5-7`', '`0 18,6 * * *`', '`5-10,45 * * * *`', '`05,10-50`', '`0-2,22-23`', '`31W`', '`0 0 30 2 *`', '`1e1`'] as $example) {
            $this->assertMentions($example, $notes, 'the update notes');
        }
        $this->assertMentions('(troubleshooting.md#saving-a-schedule-is-refused-the-cron-expression)', $notes, 'the update notes');
    }

    /**
     * Review finding: the update notes found the schedules saved before the
     * rules with two queries that missed some kinds; they name
     * schedule/check now, with a line as it prints it.
     */
    public function testTheUpdateNotesNameTheScheduleCheck(): void
    {
        $notes = $this->flat(self::UPDATING, PhpUpdateDocsContractTest::UPDATE_SECTION);
        $line = sprintf(
            ScheduleController::CHECK_REFUSED,
            12,
            'Nightly backup',
            '0 18,6 * * *',
            '',
            sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18')
        );

        $this->assertMentions('docker compose exec -u www-data app php yii schedule/check', $notes, 'the update notes');
        $this->assertMentions('`' . $line . '`', $notes, 'the update notes');
        $this->assertStringNotContainsString('LIKE', $notes, 'no query that misses some kinds');
        $this->assertMentions('docker compose exec -u www-data app php yii schedule/check', $this->flat(self::TROUBLESHOOTING, self::CRON_SECTION), self::CRON_SECTION);
    }

    public function testTheUpdateNotesQuoteTheProjectAndArtifactMessages(): void
    {
        $notes = $this->flat(self::UPDATING, PhpUpdateDocsContractTest::UPDATE_SECTION);

        $this->assertMentions(self::upTo(ProjectFilesNote::UNREADABLE_PATHS, 'project directory,'), $notes, 'the update notes');
        $this->assertMentions('(troubleshooting.md#project-page-the-web-server-cannot-read-paths)', $notes, 'the update notes');
        $this->assertMentions('`' . ArtifactService::NOTHING_TO_DOWNLOAD . '`', $notes, 'the update notes');
        $this->assertMentions('`' . ArtifactService::FILE_UNREADABLE . '`', $notes, 'the update notes');
    }

    /**
     * The troubleshooting entry quotes each message as the rules write it.
     */
    public function testTheCronEntryQuotesTheMessages(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::CRON_SECTION);

        foreach ([
            sprintf(CronExpressionRules::RANGE_BACKWARDS, '22-2', 'hour', '0-2,22-23'),
            sprintf(CronExpressionRules::RANGE_BACKWARDS, '50-10/5', 'minute', '0-10/5,50-59/5'),
            sprintf(CronExpressionRules::RANGE_BACKWARDS_UNSPLIT, '50-5', 'minute'),
            sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18'),
            sprintf(CronExpressionRules::LIST_ORDER, '10-50,5', 'minute', '05,10-50'),
            self::upTo(sprintf(CronExpressionRules::LIST_TOO_LONG, '*/2,*/3', 'minute', 64), 'may have.'),
            self::upTo(sprintf(CronExpressionRules::ASTERISK_IN_LIST, '*,5'), 'holds *,'),
            sprintf(CronExpressionRules::SUNDAY_FIRST, 'SUN-SAT', '0-SAT'),
            self::upTo(sprintf(CronExpressionRules::SUNDAY_ALONE, '7-7'), 'Sunday alone,'),
            sprintf(CronExpressionRules::SUNDAY_LAST, 'SAT-SUN', 'SAT-7'),
            sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, '5-0', '5-7'),
            sprintf(CronExpressionRules::SUNDAY_STEP, '0-7/2', '0-6/2'),
            sprintf(CronExpressionRules::STEP_TOO_LARGE, '/90', 'minute', 59),
            sprintf(CronExpressionRules::STEP_AFTER_NAMES, 'MON-FRI/2', 'day-of-week', '1-5/2'),
            sprintf(CronExpressionRules::NEAREST_WEEKDAY_LATE, '31W'),
            self::upTo(CronExpressionRules::NEVER_RUNS, 'would never run.'),
            CronExpressionRules::INVALID,
        ] as $message) {
            $this->assertMentions($message, $entry, self::CRON_SECTION);
        }
        $this->assertMentions('(#a-schedule-ran-twice-or-an-hour-early-on-the-night-the-clocks-went-back)', $entry, self::CRON_SECTION);
        $this->assertStringNotContainsString('the library runs a schedule at the hour that', $entry, 'Ansilume runs such a time once now');
    }

    /**
     * Review follow-up: only schedule/check named the schedules saved with a
     * refused expression; the docs name the list's mark, the pages and the
     * API field that say why now.
     */
    public function testTheDocsNameWhereTheReasonOfAStoredRefusedExpressionShows(): void
    {
        foreach ([
            self::CRON_SECTION => $this->flat(self::TROUBLESHOOTING, self::CRON_SECTION),
            'the update notes' => $this->flat(self::UPDATING, PhpUpdateDocsContractTest::UPDATE_SECTION),
        ] as $where => $text) {
            $this->assertMentions('**' . Schedule::CRON_REFUSED_BADGE . '**', $text, $where);
            $this->assertMentions('`cron_expression_problem`', $text, $where);
            $this->assertMentions('its edit form say why', $text, $where);
        }
        $spec = (string)preg_replace('/\s+/', ' ', $this->read('web/openapi.yaml'));
        $this->assertMentions('cron_expression_problem: type: string nullable: true', $spec, 'web/openapi.yaml');
        $this->assertMentions("example: '" . sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18') . "'", $spec, 'web/openapi.yaml');
    }

    /**
     * Regression: the docs said the scheduler runs a schedule at the hour
     * that comes twice in both, as the library did; they say how the runs
     * fall now (CronRuns), and what the update did to the next runs
     * computed before (m000089).
     */
    public function testTheDocsSayHowTheRunsFallOnTheNightsTheClocksChange(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/m000089_000000_recompute_schedule_next_runs.php';
        $notes = $this->flat(self::UPDATING, PhpUpdateDocsContractTest::UPDATE_SECTION);
        $entry = $this->flat(self::TROUBLESHOOTING, self::CLOCKS_SECTION);

        $this->assertMentions('**Schedules run once on the night the clocks go back.**', $notes, 'the update notes');
        $this->assertMentions('`' . sprintf(\m000089_000000_recompute_schedule_next_runs::MOVED, 3) . '`', $notes, 'the update notes');
        $this->assertMentions('(troubleshooting.md#a-schedule-ran-twice-or-an-hour-early-on-the-night-the-clocks-went-back)', $notes, 'the update notes');
        $this->assertMentions('Going back to 2.12.2 needs nothing for the database: the next runs the update computed anew stay', $notes, 'the update notes');
        // Review finding: 2.12.2's migrate/down cannot load m000089, so it cannot revert m000088 either.
        $this->assertMentions('To go back further than 2.12.2, first revert the migration of this release with this release, `docker compose exec -u www-data app php yii migrate/down 1 --interactive=0`', $notes, 'the update notes');
        $rollback = $this->flat(self::UPDATING, '## Rollback');
        $this->assertMentions('(migration `m000089`, which computes the next runs of schedules anew)', $rollback, '## Rollback');
        $this->assertMentions('revert `m000089` first, with the release that has it; `migrate/down 2` there reverts `m000088` as well.', $rollback, '## Rollback');
        foreach ([$notes, $entry] as $text) {
            foreach (['`0 2 * * *`', '`0 3 * * *`', '`Africa/Casablanca`', '`Antarctica/Troll`', '`Australia/Lord_Howe`', '`*/15 * * * *`', '`0 * * * *`'] as $example) {
                $this->assertMentions($example, $text, 'the update notes and ' . self::CLOCKS_SECTION);
            }
            $this->assertMentions('a time that comes twice', strtolower($text), 'the update notes and ' . self::CLOCKS_SECTION);
            $this->assertMentions('when it would have come by the clock before the change', $text, 'the update notes and ' . self::CLOCKS_SECTION);
        }
        $this->assertMentions('The update computes anew the next run of every schedule that is not yet due', $entry, self::CLOCKS_SECTION);
        $this->assertMentions('(updating.md#after-updating-from-2122-or-older-php-83-cron-expressions-unreadable-files)', $entry, self::CLOCKS_SECTION);
        $spec = (string)preg_replace('/\s+/', ' ', $this->read('web/openapi.yaml'));
        $this->assertMentions('On the nights the clocks change, a time that comes twice runs once, the first time', $spec, 'web/openapi.yaml');
        $this->assertStringNotContainsString('runs in both of them', $spec, 'web/openapi.yaml');
    }

    public function testTheProjectEntryQuotesTheNotes(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::PROJECT_SECTION);

        $this->assertMentions(self::upTo(ProjectFilesNote::UNREADABLE_PATHS, 'may be incomplete:'), $entry, self::PROJECT_SECTION);
        $this->assertMentions(self::upTo(ProjectFilesNote::UNREADABLE_DIRECTORY, 'are not shown.'), $entry, self::PROJECT_SECTION);
        $this->assertMentions(self::upTo(sprintf(ProjectFilesNote::UNREACHABLE_DIRECTORY, '/srv/ansible/project'), 'directory above it.'), $entry, self::PROJECT_SECTION);
        $this->assertMentions('`' . ProjectFilesNote::ITSELF . '`', $entry, self::PROJECT_SECTION);
        $this->assertMentions('`' . trim(ProjectTreeHelper::NOT_READABLE) . '`', $entry, self::PROJECT_SECTION);
    }

    public function testTheArtifactEntryQuotesTheAnswersAndTheLog(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::ARTIFACT_SECTION);

        foreach ([ArtifactService::FILE_GONE, ArtifactService::FILE_UNREADABLE, ArtifactService::NOTHING_TO_DOWNLOAD] as $answer) {
            $this->assertMentions('`' . $answer . '`', $entry, self::ARTIFACT_SECTION);
        }
        $this->assertMentions(sprintf(ArtifactArchive::LEFT_OUT, 42, '7, 9'), $entry, self::ARTIFACT_SECTION);
        $this->assertMentions(self::upTo(sprintf(ArtifactService::UNREADABLE_LOGGED, 7, 42, ''), 'cannot be read:'), $entry, self::ARTIFACT_SECTION);
    }

    public function testTheArtifactGuideAndTheSpecQuoteTheAnswers(): void
    {
        $guide = $this->flat('docs/artifacts.md');
        $spec = (string)preg_replace('/\s+/', ' ', $this->read('web/openapi.yaml'));

        foreach ([ArtifactService::FILE_GONE, ArtifactService::FILE_UNREADABLE, ArtifactService::NOTHING_TO_DOWNLOAD] as $answer) {
            $this->assertMentions('`' . $answer . '`', $guide, 'docs/artifacts.md');
            $this->assertMentions('`' . $answer . '`', $spec, 'web/openapi.yaml');
        }
        $this->assertMentions('(troubleshooting.md#artifact-download-answers-404)', $guide, 'docs/artifacts.md');
    }

    /**
     * The anchors the docs link to belong to these headings.
     */
    public function testTheLinkedHeadingsExist(): void
    {
        $text = $this->read(self::TROUBLESHOOTING);

        foreach ([self::CRON_SECTION, self::CLOCKS_SECTION, self::PROJECT_SECTION, self::ARTIFACT_SECTION] as $heading) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($heading, '/') . '$/m', $text);
        }
    }

    /**
     * $message up to and with $marker, where the docs cut it off.
     */
    private static function upTo(string $message, string $marker): string
    {
        $at = strpos($message, $marker);
        self::assertNotFalse($at, "The message has no {$marker}: {$message}");

        return substr($message, 0, $at + strlen($marker));
    }
}
