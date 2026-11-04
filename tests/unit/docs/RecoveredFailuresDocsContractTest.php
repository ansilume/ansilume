<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\controllers\SiteController;
use app\models\JobSearchForm;
use app\services\RecoveredFailures;
use PHPUnit\Framework\TestCase;

/**
 * Contract: the troubleshooting entry for a failed job that the dashboard
 * leaves out, and the README's features, say what a recovered failure is
 * (RecoveredFailures: a later run of the same job template succeeded, that
 * started after the failure finished and was no dry run), where it shows,
 * and that MTTR follows the same rule, with the texts of the pages and the
 * names of the API (recovered_by_job_id, recovered=1|0).
 */
final class RecoveredFailuresDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const ENTRY = "## A failed job is missing from the dashboard's Failed Jobs";

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const README = 'README.md';

    /** The panel and the card of the dashboard, as views/site/index.php names them. */
    private const PANEL = 'Failed Jobs (last 24h)';
    private const CARD = 'Jobs Today';

    /**
     * The entry quotes what the pages show and names the filter and the
     * API field, as they are.
     */
    public function testTheEntryQuotesThePagesAndTheApi(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::ENTRY);
        foreach ([
            '**' . self::PANEL . '**',
            '**' . self::CARD . '**',
            '`' . sprintf(RecoveredFailures::DASHBOARD_LINE_MANY, 2) . '`',
            '`' . sprintf(RecoveredFailures::JOB_PAGE_NOTE, '#136') . '`',
            '`' . sprintf(RecoveredFailures::LIST_NOTE, '#136') . '`',
            '**' . RecoveredFailures::DASHBOARD_LINK . '**',
            '**Recovered**',
            '`/job/index?recovered=' . JobSearchForm::RECOVERED_YES . '`',
            '`GET /api/v1/jobs?recovered=' . JobSearchForm::RECOVERED_YES . '`',
            '`recovered=' . JobSearchForm::RECOVERED_NO . '`',
            '`recovered_by_job_id`',
        ] as $text) {
            $this->assertMentions($text, $entry, self::ENTRY);
        }
        $view = $this->read('views/site/index.php');
        $this->assertMentions('<strong>' . self::PANEL . '</strong>', $view, 'views/site/index.php');
        $this->assertMentions("Html::a('" . self::CARD . "'", $view, 'views/site/index.php');
    }

    /**
     * The entry says the rule: the same job template, a success that
     * started after the failure finished, no dry run; overlapping runs and
     * dry runs recover nothing; the window is the last 24 hours, which the
     * panel looks back (SiteController::FAILED_JOBS_WINDOW); the history
     * still counts every failure; MTTR follows the rule.
     */
    public function testTheEntrySaysTheRule(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::ENTRY);
        foreach ([
            'a job of the same job template succeeded that started after the failure finished and was no dry run',
            'A success that started before the failure finished does not recover it',
            'and neither does a dry run',
            // Review finding: the reason given was false where the overlapping success finished last.
            'even when it finished later, as it was already running when the failure ended',
            'A success that started in the second the failure finished counts too when it is the later job, of a higher ID',
            'with `finished_since`, the Unix time the 24 hours began',
            'of the last 24 hours',
            'Job Outcomes',
            'Status (7 days)',
            'MTTR',
        ] as $text) {
            $this->assertMentions($text, $entry, self::ENTRY);
        }
        $this->assertSame(86400, SiteController::FAILED_JOBS_WINDOW, 'the last 24 hours');
    }

    /**
     * The update notes of the release after 2.14.0 say what the dashboard,
     * the job page, the job list, the API and MTTR show now, with the texts
     * of the pages, and name the index of m000092.
     */
    public function testTheUpdateNotesSayWhatChanged(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/m000092_000000_add_job_recovery_index.php';
        $notes = $this->flat('docs/updating.md', Redis810UpdateDocsContractTest::UPDATE_SECTION);
        foreach ([
            '**Failed jobs that a later run recovered.**',
            'a job of the same job template succeeded that started after it finished and was no dry run',
            '**' . self::PANEL . '**',
            '**' . self::CARD . '**',
            '`' . sprintf(RecoveredFailures::DASHBOARD_LINE_MANY, 2) . '`',
            '**' . RecoveredFailures::DASHBOARD_LINK . '**',
            '`' . sprintf(RecoveredFailures::JOB_PAGE_NOTE, '#136') . '`',
            '`' . sprintf(RecoveredFailures::LIST_NOTE, '#136') . '`',
            '**Recovered**',
            '`recovered_by_job_id`',
            '`recovered=' . JobSearchForm::RECOVERED_YES . '`',
            '`recovered=' . JobSearchForm::RECOVERED_NO . '`',
            'Up to 2.14.0 a dry run that succeeded counted as the recovery',
            '`m000092`: the index `' . \m000092_000000_add_job_recovery_index::INDEX . '` of `job`',
            // Review findings: a retry that started in the second the failure finished recovered nothing, and Show them
            // listed the recovered failures of any age.
            'A job of a higher ID that started in the second the failure finished counts too',
            'It also takes `finished_since`, a Unix time',
            '`' . sprintf(JobSearchForm::FINISHED_SINCE_NOTE, '<time>') . '`',
            '**' . JobSearchForm::FINISHED_SINCE_CLEAR . '**',
        ] as $text) {
            $this->assertMentions($text, $notes, 'the update notes');
        }
    }

    /**
     * The README's features name the dashboard's rule, the API field and
     * the MTTR rule.
     */
    public function testTheReadmeNamesTheFeature(): void
    {
        $readme = $this->flat(self::README);
        foreach ([
            'no later run of the same job template recovered',
            '`recovered_by_job_id`',
            'MTTR runs from a failure to the first later successful run of the same job template that was no dry run',
        ] as $text) {
            $this->assertMentions($text, $readme, self::README);
        }
    }
}
