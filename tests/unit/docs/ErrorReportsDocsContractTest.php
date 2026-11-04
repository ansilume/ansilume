<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ErrorText;
use app\components\OutboundHttpException;
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
use app\services\notification\MattermostApi;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationTemplatePage;
use app\services\notification\PagerDutyChannel;
use app\services\notification\ThreadedSend;
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
        // The channel's error as the docs show it: the outbound HTTP client's
        // error named after the channel, the HTTP status or why nothing
        // answered. Regression (outbound HTTP security fix): the error held
        // the start of the endpoint's answer, and the docs said so.
        $answered = sprintf(OutboundHttpException::FROM, 'WebhookChannel', sprintf(OutboundHttpException::HTTP_STATUS, 404));
        $unanswered = sprintf(OutboundHttpException::FROM, 'WebhookChannel', sprintf(OutboundHttpException::CONNECT_FAILED, 7));
        $this->assertMentions('`' . $answered . '` when the endpoint answered 404', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . $unanswered . '` when nothing answered', $entry, 'troubleshooting.md');
        $this->assertMentions('The error never holds the endpoint\'s answer, its URL or a header.', $entry, 'troubleshooting.md');
        $this->assertStringNotContainsString('start of the endpoint\'s answer', $entry, 'troubleshooting.md quotes the old error.');
        // A channel that sends nothing for an event (SelectiveChannelInterface):
        // no delivery of it, and a test of none of its events fails with why.
        $this->assertMentions('or when its channel sends nothing for the event, as PagerDuty for `' . implode('` and `', PagerDutyChannel::NEVER_TRIGGERED) . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . sprintf(NotificationDispatcher::SENDS_NOTHING, NotificationTemplate::CHANNEL_PAGERDUTY, PagerDutyChannel::NEVER_TRIGGERED[0]) . '`', $entry, 'troubleshooting.md');
    }

    /**
     * Regression (workflow notifications plan, Task 7): the entry said that
     * the container that caused an event sends its notification, and that a
     * notification that failed is not sent again. The notification-worker
     * sends every delivery and tries a transient error again; the entry says
     * so, with the worker's log line, its retry steps, which failures are
     * final, and when a template gets no delivery at all.
     */
    public function testTheNotificationEntrySaysTheWorkerSendsAndRetries(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::NOTIFICATIONS_ENTRY);
        $service = new NotificationDeliveryService();
        $error = sprintf(OutboundHttpException::FROM, 'WebhookChannel', sprintf(OutboundHttpException::HTTP_STATUS, 404));

        $this->assertMentions('the notification-worker sends it', $entry, 'troubleshooting.md');
        $this->assertMentions('`docker compose logs notification-worker`', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . sprintf(NotificationDeliveryService::FAILED, 41, 3, 'webhook', 'job.failed', 1, $error) . '`', $entry, 'troubleshooting.md');
        $this->assertSame(8, $service->maxAttempts);
        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400], $service->backoffSeconds, 'the steps the entry names');
        $this->assertMentions('up to 8 attempts, after 30 seconds, 2, 10 and 30 minutes, then 1, 2 and 4 hours', $entry, 'troubleshooting.md');
        $this->assertSame(3600, NotificationDeliveryService::RETRY_AFTER_MAX_SECONDS);
        $this->assertMentions('with `Retry-After`, up to an hour', $entry, 'troubleshooting.md');
        $this->assertMentions('the answer `408`, `425`, `429` or `5xx`', $entry, 'troubleshooting.md');
        $this->assertMentions('such as `400`, `401`, `403`, `404` or `413`, fails it at once', $entry, 'troubleshooting.md');
        $this->assertMentions(
            '`' . AuditLog::ACTION_NOTIFICATION_DISPATCHED . '`, `' . AuditLog::ACTION_NOTIFICATION_FAILED . '` with the `error` of the channel',
            $entry,
            'troubleshooting.md'
        );
        $this->assertMentions('`' . AuditLog::ACTION_NOTIFICATION_SKIPPED . '` with the reason', $entry, 'troubleshooting.md');
        $this->assertMentions('may not see the event in Ansilume or is inactive', $entry, 'troubleshooting.md');
        $this->assertMentions('`docker compose ps notification-worker`', $entry, 'troubleshooting.md');
        $this->assertStringNotContainsString('A notification that failed is not sent again.', $entry, 'troubleshooting.md says what 2.14 did');
        $this->assertStringNotContainsString('The application log of the container that sent it', $entry, 'troubleshooting.md says what 2.14 did');
        // Regression (plan Task 14): the entry sent operators to `docker
        // compose logs notification-worker` for the error, which shows only
        // the worker's start: the console application logs to
        // runtime/logs/app.log alone, the prebuilt notification-worker to its
        // own inside its container.
        $this->assertSame([\app\components\RedactingFileTarget::class], self::consoleLogTargets(), 'The console application logs to a file only.');
        $this->assertMentions('The application log of the notification-worker has the same error', $entry, 'troubleshooting.md');
        $this->assertMentions('`docker compose exec notification-worker tail -n 200 /var/www/runtime/logs/app.log`', $entry, 'troubleshooting.md');
        $this->assertMentions('`docker compose logs notification-worker` shows only that it started', $entry, 'troubleshooting.md');
        $this->assertStringNotContainsString('(`docker compose logs notification-worker`) has the same error', $entry, 'troubleshooting.md: docker logs never have it');
    }

    /**
     * The entry of a delivery that waits for its next attempt quotes what
     * the deliveries and the worker's log show, and the retry steps.
     */
    public function testTheRetryEntryQuotesWhatTheDeliveriesAndTheLogShow(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## A notification waits for its next attempt, or failed after 8 attempts');
        $error = sprintf(OutboundHttpException::FROM, 'WebhookChannel', sprintf(OutboundHttpException::HTTP_STATUS, 503));

        $this->assertMentions('`' . rtrim(self::prefix(NotificationTemplatePage::NEXT_ATTEMPT)) . '` and when', $entry, 'troubleshooting.md, on the next attempt');
        $this->assertMentions('`' . sprintf(NotificationDeliveryService::RETRYING, 41, 3, 'webhook', 'job.failed', 3, 8, 600, $error) . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('after 30 seconds, 2, 10 and 30 minutes, then 1, 2 and 4 hours, at most 8 attempts', $entry, 'troubleshooting.md');
        $this->assertMentions('`GET /api/v1/notification-templates/{id}/deliveries`', $entry, 'troubleshooting.md');
        $this->assertMentions('A delivery that failed for good is not sent again; the next event is.', $entry, 'troubleshooting.md');
        $this->assertMentions('The later deliveries of the same workflow run to the same template wait behind it', $entry, 'troubleshooting.md');
    }

    /**
     * Without the notification-worker nothing is sent: the entry quotes
     * what bin/diagnose says, what a worker of an older image prints, and
     * says that the deliveries wait in the database.
     */
    public function testTheWorkerEntrySaysWhatHappensWithoutTheNotificationWorker(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## Notifications are not sent: the notification-worker does not run');

        $this->assertMentions('`notification-worker: not running`', $entry, 'troubleshooting.md');
        // Review finding: a worker that restarts without end has a container, for which bin/diagnose prints its state.
        $this->assertMentions('`notification-worker: stopped (state=restarting)`', $entry, 'troubleshooting.md');
        $this->assertStringContainsString('fail "${svc}: stopped (state=${state})"', $this->read('bin/diagnose'), 'The line bin/diagnose prints for a container that does not run.');
        $this->assertMentions(
            '`notification-worker: not in the compose file — the app image queues the notifications for it, and no container sends them: take the compose file of this release`',
            $entry,
            'troubleshooting.md'
        );
        $this->assertStringContainsString(
            'fail "notification-worker: not in the compose file — the app image queues the notifications for it, and no container sends them: take the compose file of this release"',
            $this->read('bin/diagnose'),
            'The line bin/diagnose prints.'
        );
        $this->assertMentions('`Error: Unknown option: --queue`', $entry, 'troubleshooting.md');
        $this->assertMentions('docker compose up -d notification-worker', $entry, 'troubleshooting.md');
        $this->assertMentions('The deliveries wait in the database: none is lost.', $entry, 'troubleshooting.md');
        $this->assertMentions('`docker compose rm -sf notification-worker`', $entry, 'troubleshooting.md');
    }

    /**
     * A template whose user is inactive: the entry quotes the page, the API,
     * bin/diagnose and the skip, and says how to fix it.
     */
    public function testTheInactiveUserEntryQuotesWhereItShowsAndHowToFixIt(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## A notification template delivers nothing: the user it delivers as is inactive');

        $this->assertMentions('`' . sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, 'alice') . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('`"delivers_as_inactive": true`', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, 12) . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('any save of such a template makes you the user it delivers as', $entry, 'troubleshooting.md');
        $this->assertMentions("-d '{}' https://ansilume.example.com/api/v1/notification-templates/3", $entry, 'troubleshooting.md');
    }

    /**
     * The entry of a refused URL scheme names Mattermost's https too.
     */
    public function testTheUrlSchemeEntryNamesTheHttpsOfMattermost(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## Saving a notification template: `webhook_url must be an http or https URL.`');

        $this->assertMentions('`' . sprintf(NotificationTemplate::URL_SCHEME_REFUSED, 'webhook_url') . '`', $entry, 'troubleshooting.md');
        $this->assertMentions('`' . sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url') . '`', $entry, 'troubleshooting.md');
        $this->assertSame(['server_url' => ['https']], MattermostConfig::URL_KEYS);
    }

    /**
     * The Mattermost errors as the channel reports them: each message of
     * MattermostApiException with the detail of an answer (its status, the
     * error id and the request ID), those of the status post and its
     * thread (ThreadedSend), and a failure that can pass, as the
     * troubleshooting entry quotes them.
     */
    public function testTheMattermostErrorsAreQuotedAsTheChannelReportsThem(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## A Mattermost notification fails');
        $channel = 'h8kc6rz1ytgq3mbxd9wfs5ea4n';
        $names = 'ops/deployments';
        $detail = static fn (int $status, string $errorId): string => implode(', ', array_filter([
            sprintf(MattermostApiException::DETAIL_STATUS, $status),
            $errorId === '' ? '' : sprintf(MattermostApiException::DETAIL_ERROR_ID, $errorId),
            sprintf(MattermostApiException::DETAIL_REQUEST_ID, '7q1ba8jxifgk7ghx9xnj1p4yhe'),
        ]));
        $quoted = [
            sprintf(MattermostApiException::TOKEN_REFUSED, $detail(401, 'api.context.session_expired.app_error')),
            MattermostApiException::PERSONAL_TOKEN,
            sprintf(MattermostApiException::CHANNEL_NOT_FOUND, $names, $detail(404, 'app.channel.get_by_name.missing.app_error')),
            sprintf(MattermostApiException::NOT_A_MEMBER, $channel, $detail(404, 'app.channel.get_member.missing.app_error')),
            sprintf(MattermostApiException::POST_REFUSED, $channel, $detail(403, 'api.context.permissions.app_error')),
            sprintf(MattermostApiException::CHANNEL_ARCHIVED, $channel, $detail(400, 'api.post.create_post.can_not_post_to_deleted.error')),
            sprintf(MattermostApiException::ARCHIVED, $names),
            sprintf(MattermostApiException::UNEXPECTED_ANSWER, MattermostApi::OP_ME, 200),
            sprintf(MattermostApiException::CONFIG_UNUSABLE, sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url')),
            sprintf(MattermostApiException::TOO_MANY_REQUESTS, MattermostApi::MAX_REQUESTS),
            // Review finding: a server whose permission scheme takes Edit Posts from members got a new status post on
            // every change of a run; the bot now looks the post up and fails with what to allow.
            sprintf(MattermostApiException::EDIT_NOT_ALLOWED, '1q8ytn3ixjbkzmd5cpf7rk4w1r', $detail(403, 'api.context.permissions.app_error')),
            sprintf(MattermostApiException::FAILED, MattermostApi::OP_LOOKUP, $detail(403, 'api.context.permissions.app_error')),
            sprintf(MattermostApiException::FAILED, MattermostApi::OP_POST, $detail(503, '')),
            sprintf(MattermostApiException::FAILED, MattermostApi::OP_ME, rtrim(sprintf(OutboundHttpException::CONNECT_FAILED, 7), '.')),
            ThreadedSend::NO_STATUS_POST,
            ThreadedSend::RUN_GONE,
            ThreadedSend::THREAD_NOT_SAVED,
        ];
        foreach ($quoted as $message) {
            $this->assertMentions('`' . $message . '`', $entry, 'troubleshooting.md, on Mattermost');
        }
        // The archived channel is refused as its own error id says.
        $this->assertStringContainsString('can_not_post_to_deleted', 'api.post.create_post.can_not_post_to_deleted.error');
        $this->assertMentions('`Test notification failed: `', $entry, 'troubleshooting.md, on Mattermost');
        $this->assertMentions('(`ServiceSettings.PostEditTimeLimit`)', $entry, 'troubleshooting.md, on Mattermost');
    }

    /**
     * The targets of the console application's log, as config/console.php
     * defines them.
     *
     * @return list<string>
     */
    private static function consoleLogTargets(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/console.php';
        $targets = [];
        foreach ((array)($config['components']['log']['targets'] ?? []) as $target) {
            $targets[] = (string)(is_array($target) ? ($target['class'] ?? '') : '');
        }

        return $targets;
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
