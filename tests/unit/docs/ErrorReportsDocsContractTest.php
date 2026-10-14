<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ErrorText;
use app\components\WorkflowRunOperationFailure;
use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\NotificationTemplateController;
use app\controllers\ProjectController;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\WorkflowJobStep;
use app\services\ApprovalSettlement;
use app\services\AuditService;
use app\services\JobCancellationService;
use app\services\NotificationDispatcher;
use app\services\runstate\Texts;
use app\services\ScheduleService;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowRunEvents;
use PHPUnit\Framework\TestCase;

/**
 * What the operator docs say about failures and their reports is what the
 * code writes: the answers and log lines of a workflow run cancel or resume
 * that failed on a database error, the error a failed notification, audit
 * target or schedule reports (never the message of a database error), and
 * how votes and the maintenance run end jobs that can never run.
 *
 * Regressions from the last review round of step 3, each test fails on the
 * docs as they were before: a database error of a run cancel or resume
 * answered a bare server error that no doc named; the docs quoted no
 * failure of a workflow notification and did not say that the older code
 * keeps database errors out of logs and audit entries too; they did not say
 * that a vote on a job of a deleted job template closes its request and
 * cancels it, that stranded jobs get the reason their run was canceled for,
 * that an approved approval step writes its step entry, nor what a sweep
 * prints when the approval timeouts used up the time budget.
 *
 * Regressions from wave 8 of step 3, each fails on the docs as they were
 * before it: the docs told operators to send a test notification, which
 * reported success whatever the channel answered, and quoted neither its
 * error nor the API's 502; they quoted the schedule's own format of a
 * database error, which ErrorText's replaced (C10); and they told API
 * callers to reload a page (C9).
 */
class ErrorReportsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const RUN_OPERATIONS_ENTRY = '## Canceling or resuming a workflow run fails with a database error';

    private const NOTIFICATIONS_ENTRY = '## A notification was not sent';

    /** A deadlock, as the docs show it: class, SQLSTATE, driver error code. */
    private const DEADLOCK = ['yii\db\Exception', '40001', '1213'];

    /** A lost connection, as the docs show it. */
    private const CONNECTION_LOST = ['yii\db\Exception', 'HY000', '2006'];

    /**
     * Regression: a database error of a run cancel or resume answers with a
     * message that says what to do (web: an error flash, API: 500), and the
     * log names the error without its message; the docs quote both.
     */
    public function testTheFailuresOfRunOperationsAreQuoted(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::RUN_OPERATIONS_ENTRY);
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        foreach ([WorkflowRunOperationFailure::CANCEL_FAILED, WorkflowRunOperationFailure::RESUME_FAILED] as $format) {
            $message = sprintf($format, 12);
            $this->assertMentions('`' . $message . '`', $entry, 'troubleshooting.md');
            $this->assertMentions('`' . self::firstSentence($message) . ' ...`', $updating, 'updating.md');
        }
        $logged = sprintf(WorkflowRunOperationFailure::LOG, 12, WorkflowRunOperationFailure::CANCEL, ...self::DEADLOCK);
        $this->assertMentions('`' . $logged . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('(`the ' . WorkflowRunOperationFailure::RESUME . '` for a resume)', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . self::upTo(WorkflowRunOperationFailure::LOG, 12, WorkflowRunOperationFailure::CANCEL) . '`', $updating, 'updating.md');
        $this->assertMentions(
            '[Canceling or resuming a workflow run fails with a database error](troubleshooting.md#canceling-or-resuming-a-workflow-run-fails-with-a-database-error)',
            $updating,
            'updating.md'
        );
        // Regression (wave 8, C9): the messages are the API's answers too, and
        // an API caller has no page to reload. The notes of a page whose live
        // updates stopped (2.11.0: a run's, a job's and a project's sync
        // output) ask for a reload of that page, rightly.
        $liveNotes = [Texts::LIVE_STOPPED_RUN, Texts::LIVE_STOPPED_JOB, ProjectController::SYNC_LIVE_STOPPED];
        foreach (['docs/troubleshooting.md', 'docs/updating.md', 'web/openapi.yaml'] as $file) {
            $this->assertStringNotContainsString('Reload the page', str_replace($liveNotes, '', $this->read($file)), "{$file} quotes the old wording.");
        }
        foreach ([WorkflowRunOperationFailure::CANCEL_FAILED, WorkflowRunOperationFailure::RESUME_FAILED] as $format) {
            $this->assertStringNotContainsString('Reload the page', $format);
        }
    }

    /**
     * Regression (last review round of step 3, substantive 2): a test
     * notification whose channel did not take it was reported as sent, so
     * the troubleshooting entry's advice to send a test showed nothing. The
     * web UI now shows the channel's error as a danger flash, and the API
     * answers 502 with it; the docs quote both and say what they said before.
     */
    public function testATestNotificationReportsTheChannelsError(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::NOTIFICATIONS_ENTRY);
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $web = '`' . self::prefix(NotificationTemplateController::TEST_FAILED) . '`';
        $api = '`' . self::prefix(NotificationTemplatesController::TEST_FAILED) . '`';
        foreach (['troubleshooting.md' => $entry, 'updating.md' => $updating] as $where => $text) {
            $this->assertMentions('**Send Test**', $text, $where);
            $this->assertMentions($web . ' and the channel\'s error as an error', $text, $where);
            $this->assertMentions('`POST /api/v1/notification-templates/{id}/test` answers `502` with ' . $api, $text, $where);
            $this->assertMentions('`' . NotificationTemplateController::TEST_SENT . '`', $text, $where . ', on what the test said before,');
        }
        $this->assertMentions(
            'the test writes its `' . AuditLog::ACTION_NOTIFICATION_DISPATCHED . '` or `' . AuditLog::ACTION_NOTIFICATION_FAILED . '` audit entry',
            $entry,
            'troubleshooting.md'
        );
        $this->assertMentions('`502` with `Test failed: ` and the channel\'s error when the channel did not take the test message, and `500`', $updating, 'updating.md');
        // The channel's error as the docs show it: the HTTP status, 0 when nothing answered.
        $channel = (string)file_get_contents(dirname(__DIR__, 3) . '/services/notification/WebhookChannel.php');
        $this->assertStringContainsString('"WebhookChannel: HTTP {$code} — "', $channel, 'The webhook channel words its error as the docs show it.');
        $this->assertMentions('`WebhookChannel: HTTP 404 — ...`', $entry, 'troubleshooting.md');
        $this->assertMentions('`WebhookChannel: HTTP 0 — ` when nothing answered', $entry, 'troubleshooting.md');
    }

    /**
     * Regression: a failed notification, audit target or schedule reports a
     * database error by its class, SQLSTATE and driver error code only, also
     * in the notification.failed audit entry; a workflow notification whose
     * templates cannot be read is logged and the run goes on.
     */
    public function testTheFailuresOfNotificationsAndOlderLogLinesAreQuoted(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::NOTIFICATIONS_ENTRY);
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $send = self::upTo(NotificationDispatcher::SEND_FAILED, 3, 'webhook', 'job.failed');
        $this->assertMentions('`' . $send . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . $send . '`', $updating, 'updating.md');
        $this->assertMentions('`' . sprintf(ErrorText::DATABASE_ERROR, ...self::CONNECTION_LOST) . '`', $entry, 'troubleshooting.md');
        $this->assertMentions(
            '`' . sprintf(WorkflowRunEvents::NOTIFY_FAILED, 12, 'workflow.step_failed', ...self::CONNECTION_LOST) . '`',
            $entry,
            'troubleshooting.md'
        );
        $this->assertMentions('`' . self::upTo(WorkflowRunEvents::NOTIFY_FAILED, 12, 'workflow.step_failed') . '`', $updating, 'updating.md');
        $this->assertMentions('(`' . self::upTo(AuditService::TARGET_FAILED, '...') . '`)', $updating, 'updating.md');
        // Regression (wave 8, C10): one format for a database error in every
        // text; the schedule's line no longer says "database error" first.
        $this->assertFalse((new \ReflectionClass(ScheduleService::class))->hasConstant('DATABASE_ERROR'), 'ErrorText words every database error.');
        $skipped = sprintf(ScheduleService::SKIPPED, 12, 'Nightly deploy', sprintf(ErrorText::DATABASE_ERROR, ...self::DEADLOCK));
        $this->assertMentions('`' . $skipped . '`', $updating, 'updating.md');
        $this->assertMentions(
            'the `error` of `notification.failed` without the message of a database error',
            $updating,
            'updating.md, in the audit changes,'
        );
        // Wave 8 of step 3: the older sites that were converted last.
        $this->assertMentions(
            'The same holds for the sync error of a project, which its page and the `' . NotificationTemplate::EVENT_PROJECT_SYNC_FAILED
                . '` notification show, and the log lines of project syncs, vault checks and vault scans, for the output of `php yii health/check`',
            $updating,
            'updating.md'
        );
    }

    /**
     * A vote or a timeout on the request of a job that can never run closes
     * the request and cancels the job: of an ended workflow step or run
     * with the reason its run was canceled for, of a deleted job template
     * with the reason the template's delete writes.
     */
    public function testTheVotesOnJobsThatCanNeverRunAreDocumented(): void
    {
        $this->assertSame(JobCancellationService::REASON_TEMPLATE_DELETED, ApprovalSettlement::REASON_TEMPLATE_DELETED);
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions(
            'the job\'s workflow step no longer runs, or its run has ended, whatever became of its job template: the request gets `reason: '
                . ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED . '`, and the job a `job.canceled` entry with `workflow_job_id` and the reason the run was canceled for',
            $updating,
            'updating.md'
        );
        $this->assertMentions(
            'the job\'s job template was deleted, and the vote or timeout came before the delete\'s own cancel of the job, or that cancel failed: the request and the job get `reason: '
                . ApprovalSettlement::REASON_TEMPLATE_DELETED . '`',
            $updating,
            'updating.md'
        );
        $this->assertMentions('or its job template had been deleted. Then the vote canceled the job too', $this->flat('docs/troubleshooting.md'), 'troubleshooting.md');

        // Regression (wave 8 of step 3): deleting a project removed its deleted
        // templates with their waiting jobs, which then waited for good and
        // could be approved; the project's delete cancels them now, and votes,
        // timeouts and the sweep cancel those an earlier release left.
        $this->assertMentions(
            'The same holds for a job whose job template is gone, removed with its project, unless it is the job of a workflow approval step',
            $updating,
            'updating.md'
        );
        $this->assertMentions(
            'Now the project\'s delete first cancels these waiting jobs as the delete of their template does, with `reason: '
                . JobCancellationService::REASON_TEMPLATE_DELETED . '` and their pending approval requests',
            $updating,
            'updating.md'
        );
        $this->assertSame(5 * 60, StrandedJobSweep::ORPHAN_MIN_AGE_SECONDS, 'The docs say five minutes.');
        $this->assertMentions('and the sweep below cancels it once it is five minutes old', $updating, 'updating.md');
        $this->assertMentions(
            'A job whose job template was removed with its project counts as one of a deleted template.',
            $this->flat('docs/troubleshooting.md', '## Approve or Reject is refused, or missing'),
            'troubleshooting.md'
        );
        $this->assertMentions(
            'the waiting jobs whose job template is gone, five minutes after they were created',
            $this->flat('docs/troubleshooting.md', '## A job was canceled without anyone canceling it'),
            'troubleshooting.md'
        );
    }

    /**
     * The jobs that ended runs left waiting get the reason their run was
     * canceled for: workflow_template_deleted for a template delete, else
     * workflow_canceled, as the run's cancel maps them.
     */
    public function testTheStrandedJobsGetTheReasonTheirRunWasCanceledFor(): void
    {
        $this->assertSame(
            JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED,
            WorkflowCancellationService::jobReason(JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED)
        );
        $this->assertSame(JobCancellationService::REASON_WORKFLOW_CANCELED, WorkflowCancellationService::jobReason(JobCancellationService::REASON_OPERATOR));
        $this->assertMentions(
            'with the reason the run was canceled for, and with no user: `' . JobCancellationService::REASON_WORKFLOW_TEMPLATE_DELETED
                . '` when the run was canceled because its workflow template was deleted (its newest `workflow.canceled` entry says so, or, without such an entry, the template is deleted), otherwise `'
                . JobCancellationService::REASON_WORKFLOW_CANCELED . '`.',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'updating.md'
        );
    }

    /**
     * An approved approval step writes its step entry, and a sweep that the
     * approval timeouts left no time prints zero cancels.
     */
    public function testTheStepEntryOfAnApprovalAndTheSweepWithoutTimeAreDocumented(): void
    {
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions(
            'an approval step that is approved writes `' . AuditLog::ACTION_WORKFLOW_STEP_COMPLETED . '` with `status: ' . WorkflowJobStep::STATUS_SUCCEEDED . '`',
            $updating,
            'updating.md'
        );
        $this->assertMentions(
            'When the approval timeouts used up the time budget, a sweep that is due starts no cancel, and its line shows zero cancels next to what remains, for example `canceled_jobs=0` and `remaining_jobs=3`',
            $updating,
            'updating.md'
        );
    }

    /**
     * $format up to its first %s that $values do not fill, filled with
     * $values, and "..." for the rest, as the docs show a line whose end
     * varies.
     */
    private static function upTo(string $format, int|string ...$values): string
    {
        $parts = preg_split('/(%[ds])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
        self::assertIsArray($parts);
        $text = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $text .= $part;
                continue;
            }
            if ($values === []) {
                return $text . '...';
            }
            $text .= (string)array_shift($values);
        }
        self::fail("{$format} has no placeholder left for the \"...\".");
    }

    /**
     * The first sentence of $message.
     */
    private static function firstSentence(string $message): string
    {
        $end = strpos($message, '. ');
        self::assertIsInt($end);

        return substr($message, 0, $end + 1);
    }

    /**
     * $format up to its first %s, as the docs show a message followed by
     * a part that varies.
     */
    private static function prefix(string $format): string
    {
        $end = strpos($format, '%s');
        self::assertIsInt($end, "{$format} has a %s.");

        return substr($format, 0, $end);
    }
}
