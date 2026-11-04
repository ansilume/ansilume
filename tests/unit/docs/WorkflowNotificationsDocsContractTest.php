<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\NotificationConfigSecrets;
use app\components\NotificationTestVariables;
use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\models\AuditLog;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\WorkflowStep;
use app\services\notification\ChannelRegistry;
use app\services\notification\DeliveryOutcome;
use app\services\notification\MattermostApi;
use app\services\notification\MattermostConfig;
use app\services\notification\MattermostStatusPost;
use app\services\notification\MattermostText;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationOutbox;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateFormView;
use app\services\notification\NotificationTemplatePage;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\NotificationVisibility;
use app\services\notification\PagerDutyChannel;
use app\services\notification\RunThread;
use app\services\runstate\Banner;
use app\services\runstate\Display;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/notifications.md says what the code of workflow notifications does:
 * the channels and their secrets, what every outbound request may do, the
 * events with their severities, the variables of each event, which events a
 * template gets (its level, its workflows, the user it delivers as), how a
 * delivery is sent, retried, swept and audited, the notification-worker,
 * the Mattermost channel, and the API; and README.md, docs/deployment.md,
 * docs/artifacts.md and docs/installation.md name what the feature changed
 * for them. The words the docs quote are the code's (its constants, the
 * values its classes give).
 *
 * Written with plan Task 14 of the workflow notifications: each test fails
 * on the docs of before it, which described only the webhook channel and
 * channels of one's own.
 */
final class WorkflowNotificationsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const DOC = 'docs/notifications.md';

    /** The user of the samples of the docs. */
    private const USER = 'alice';

    /** The ID of a channel in the samples of the docs: 26 lower-case letters and digits, as Mattermost gives them. */
    private const CHANNEL_ID = 'h8kc6rz1ytgq3mbxd9wfs5ea4n';

    /**
     * The table of the channels has a row for each built-in channel, by its
     * label and name, with every key of its config the definition names and
     * every secret of it; the paragraphs under it say what keeps a secret,
     * and what PagerDuty raises no incident for.
     */
    public function testTheChannelsTableNamesEveryBuiltInChannelWithItsKeysAndSecrets(): void
    {
        $rows = [];
        foreach ($this->units(self::DOC, '## Channels') as $unit) {
            if (preg_match('/^\| ([A-Za-z ]+) \(`([a-z]+)`\) \| (.*) \| (.*) \|$/', $unit, $match) === 1) {
                $rows[$match[2]] = ['label' => $match[1], 'config' => $match[3], 'secrets' => $match[4]];
            }
        }

        $this->assertSame(array_keys(ChannelRegistry::BUILT_IN), array_keys($rows), 'A row for each built-in channel, in the form\'s order.');
        foreach (ChannelRegistry::BUILT_IN as $name => $definition) {
            $this->assertSame($definition['label'], $rows[$name]['label'], "The label of {$name}.");
            $keys = array_merge(self::setting($definition, 'requiredKeys'), array_keys(self::setting($definition, 'urlSchemes')), self::setting($definition, 'destinationKeys'));
            foreach (array_unique($keys) as $key) {
                $this->assertMentions('`' . $key . '`', $rows[$name]['config'], "The config of {$name}");
            }
            $secrets = array_merge(self::setting($definition, 'secretKeys'), self::setting($definition, 'secretMaps'));
            foreach ($secrets as $secret) {
                $this->assertMentions('`' . $secret . '`', $rows[$name]['secrets'], "The secrets of {$name}");
            }
            if ($secrets === []) {
                $this->assertSame('none', $rows[$name]['secrets'], "{$name} has no secret.");
            }
        }
        $section = $this->flat(self::DOC, '## Channels');
        $this->assertMentions('`' . sprintf(NotificationConfigSecrets::URL_CHANGED, 'bot_token', 'server_url') . '`', $section, 'Channels');
        foreach (ChannelRegistry::BUILT_IN as $definition) {
            foreach (self::setting($definition, 'boundTo') as $secret => $key) {
                $this->assertMentions('`' . $secret . '`', $section, 'Channels, on the bound secrets');
                $this->assertMentions('`' . $key . '`', $section, 'Channels, on the bound secrets');
            }
        }
        $this->assertMentions(
            'It raises none for `' . implode('` and `', PagerDutyChannel::NEVER_TRIGGERED) . '`, so a PagerDuty template gets no delivery of them.',
            $section,
            'Channels, on PagerDuty'
        );
    }

    /**
     * What every outbound request may do, as the client does it: the
     * schemes, no redirect, its timeouts and the size of the answer it
     * reads, TLS always verified with OUTBOUND_CA_BUNDLE for more CAs, and
     * errors that never name the URL or the answer; with where the CA file
     * goes in each deployment path.
     */
    public function testOutboundHttpSaysWhatEveryRequestMayDo(): void
    {
        $section = $this->flat(self::DOC, '### Outbound HTTP');
        $client = new OutboundHttpClient();

        foreach (
            [
                'It sends only to absolute `http` and `https` URLs, and a template is saved only with such URLs (`'
                    . sprintf(NotificationTemplate::URL_SCHEME_REFUSED, 'webhook_url') . '`).',
                'Mattermost\'s `server_url` must be an https URL (`' . sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url') . '`)',
                'It follows no redirect: a `3xx` answer is an error.',
                sprintf(
                    'It waits %d seconds for the connection, the TLS handshake included, and %d seconds for the whole request, and reads at most %d bytes of the answer.',
                    $client->connectTimeout,
                    $client->timeout,
                    $client->maxBodyBytes
                ),
                'It always verifies TLS, and no setting turns that off.',
                '`OUTBOUND_CA_BUNDLE` names a PEM file of CAs that it trusts on top of the system\'s',
                '`' . sprintf(OutboundHttpException::CONNECT_FAILED, 7) . '`',
                '`' . sprintf(OutboundHttpException::TIMED_OUT, $client->timeout) . '`',
                '`' . sprintf(OutboundHttpException::TLS_FAILED, 60) . '`',
                '`' . OutboundHttpException::SCHEME_REFUSED . '`',
                'never the URL, a header, a token or what the endpoint answered.',
                'the app, the queue-worker, the notification-worker and the schedule-runner.',
                'A source installation started from `docker-compose.yml` sees its checkout at `/var/www`: put the file into the checkout and name it at `/var/www/<its path>`.',
                // Review finding: `docker compose up -d` recreates no service of docker-compose.yml for a changed .env,
                // so the notification-worker kept failing with curl error 60 while Send Test worked.
                '(`docker compose restart app queue-worker notification-worker schedule-runner`)',
                '`docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml up -d`',
                'With the deploy role, set `ansilume_outbound_ca_bundle`.',
                'Then recreate the containers with `docker compose up -d`, which also recreates them for a changed `.env`, as each names it as its `env_file`.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'Outbound HTTP');
        }
        $override = $this->codeBlock($this->section(self::DOC, '### Outbound HTTP'), 'yaml');
        $parsed = Yaml::parse($override);
        $this->assertIsArray($parsed);
        $services = $parsed['services'] ?? null;
        $this->assertIsArray($services);
        $this->assertSame(['app', 'queue-worker', 'notification-worker', 'schedule-runner'], array_keys($services), 'The override mounts the file into every service that sends.');
    }

    /**
     * The events table has a row for each event of the catalogue, with its
     * severity; the runner events say which command makes them.
     */
    public function testTheEventsTableNamesEveryEventWithItsSeverity(): void
    {
        $rows = [];
        foreach ($this->units(self::DOC, '## Events') as $unit) {
            if (preg_match('/^\| `([a-z_.]+)` \| ([a-z]+) \|/', $unit, $match) === 1) {
                $rows[$match[1]] = $match[2];
            }
        }
        $expected = [];
        foreach (array_keys(NotificationTemplate::eventLabels()) as $event) {
            $expected[$event] = NotificationTemplate::eventSeverity($event);
        }
        ksort($rows);
        ksort($expected);

        $this->assertSame($expected, $rows);
        $section = $this->flat(self::DOC, '## Events');
        $this->assertMentions('`' . AuditLog::ACTION_NOTIFICATION_THROTTLED . '`', $section, 'Events, on webhook.invalid_token');
        $this->assertSame(300, NotificationOutbox::THROTTLE_SECONDS[NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN]);
        $this->assertMentions('at most once per template every 5 minutes', $section, 'Events, on webhook.invalid_token');
        $this->assertMentions('docker compose exec -T -u www-data app php yii health/check-runners', $section, 'Events, on the runner events');
    }

    /**
     * The variables section names every variable that notifications carry
     * (the samples of Send Test are those of every event family), with the
     * reason of a waiting step as the run page words it, and how long a
     * value may be.
     */
    public function testTheVariablesSectionNamesEveryVariable(): void
    {
        $section = $this->flat(self::DOC, '## Variables');
        $variables = array_keys(NotificationTestVariables::of(NotificationTemplate::EVENT_WORKFLOW_WAITING, self::USER));
        $variables[] = 'trigger.kind';

        foreach ($variables as $variable) {
            $this->assertMentions('`' . $variable . '`', $section, 'Variables');
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)NotificationTestVariables::of('job.failed', self::USER)['event_id']);
        $this->assertMentions('| `event_id` | 32 hexadecimal digits that name the event', $section, 'Variables');
        $this->assertMentions('| `occurred_at` | when it happened, as a Unix time |', $section, 'Variables');
        $this->assertMentions(
            '`' . (string)Banner::title(Display::WAITING_FOR_APPROVAL, WorkflowStep::TYPE_JOB, 'Deploy', 42) . '`',
            $section,
            'Variables, on workflow.reason'
        );
        $this->assertMentions(
            '(`' . WorkflowStep::TYPE_JOB . '`, `' . WorkflowStep::TYPE_APPROVAL . '` or `' . WorkflowStep::TYPE_PAUSE . '`)',
            $section,
            'Variables, on step.type'
        );
        $this->assertMentions(
            '(`' . Display::WAITING_FOR_APPROVAL . '`, `' . Display::WAITING_FOR_RESUME . '` or `' . Display::WAITING_FOR_RUNNER . '`',
            $section,
            'Variables, on step.waiting_for'
        );
        $this->assertMentions('A value is kept to at most ' . NotificationOutbox::VALUE_MAX_BYTES . ' bytes, cut with `' . NotificationOutbox::CUT . '`', $section, 'Variables');
    }

    /**
     * The level: the essential events by name, and the labels of the form.
     */
    public function testTheLevelNamesTheWorkflowEventsOfEachLevel(): void
    {
        $rows = $this->rowsByFirstCell($this->units(self::DOC, '### The level'));

        $this->assertSame(NotificationTemplate::LEVELS, array_keys($rows));
        foreach (NotificationTemplate::LEVEL_LABELS as $level => $label) {
            $this->assertStringStartsWith('| `' . $level . '` | ' . $label . ' |', $rows[$level]);
        }
        $this->assertMentions(
            'only `' . implode('`, `', array_slice(NotificationTemplate::ESSENTIAL_WORKFLOW_EVENTS, 0, -1)) . '` and `'
                . NotificationTemplate::ESSENTIAL_WORKFLOW_EVENTS[count(NotificationTemplate::ESSENTIAL_WORKFLOW_EVENTS) - 1] . '`',
            $rows[NotificationTemplate::LEVEL_ESSENTIAL],
            'The level essential'
        );
        $this->assertMentions('The level applies to workflow events only', $this->flat(self::DOC, '### The level'), 'The level');
    }

    /**
     * The workflows: the scopes with the labels of the form, that a
     * selection of deleted workflow templates reaches no run, and what the
     * form says of those its editor may not see.
     */
    public function testTheWorkflowsNameTheScopesAndWhatADeletedSelectionDoes(): void
    {
        $rows = $this->rowsByFirstCell($this->units(self::DOC, '### The workflows'));
        $section = $this->flat(self::DOC, '### The workflows');

        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPES, array_keys($rows));
        foreach (NotificationTemplate::WORKFLOW_SCOPE_LABELS as $scope => $label) {
            $this->assertStringStartsWith('| `' . $scope . '` | ' . $label . ' |', $rows[$scope]);
        }
        $this->assertMentions('(`' . NotificationTemplateSave::FILTER_ATTRIBUTE . '`)', $rows[NotificationTemplate::WORKFLOW_SCOPE_SELECTED], 'The scope selected');
        $this->assertMentions('their workflow events, and the job and approval events of their steps', $section, 'The workflows');
        $this->assertMentions('A selection whose workflow templates were all deleted reaches no run: it never turns into all workflows.', $section, 'The workflows');
        $this->assertMentions('A deleted workflow template stays in the selection', $section, 'The workflows');
        $this->assertMentions('`' . sprintf(NotificationTemplateFormView::HIDDEN_WORKFLOWS, 2) . '`', $section, 'The workflows');
    }

    /**
     * Delivers as: who the user is (what changes it, what keeps it), the
     * permission each group of events needs, the checks at the event and at
     * the delivery, and what a template of an inactive user does.
     */
    public function testDeliversAsNamesTheRulesAndThePermissionOfEveryGroup(): void
    {
        $section = $this->flat(self::DOC, '### Delivers as');
        foreach (ChannelRegistry::BUILT_IN as $name => $definition) {
            foreach (self::setting($definition, 'destinationKeys') as $key) {
                $this->assertMentions('`' . $key . '`', $section, "Delivers as, on the destination of {$name}");
            }
        }
        foreach (
            [
                'It is the user who last changed what the template delivers or where to:',
                'its events, its level, its workflow scope or its workflows.',
                'A new template delivers as its creator, and any save of a template whose user is inactive makes whoever saves it its user.',
                '`notification-template.updated` audit entry of a change records it as `delivers_as` `{from, to}`.',
                '`' . sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, 12) . '`',
                'the API answers `delivers_as_inactive: true`, and `bin/diagnose` lists it',
                'A user whom a template delivers as cannot be deleted',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'Delivers as');
        }

        $labels = [
            'jobs' => 'Job events',
            'workflows' => 'Workflow events',
            'approvals' => 'Approval events',
            'schedules' => '`' . NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH . '`',
            'runners' => 'Runner events',
            'projects' => 'Project events',
            'webhooks' => '`' . NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN . '`',
        ];
        $this->assertSame(array_keys(NotificationVisibility::PERMISSIONS), array_keys($labels), 'A row for each group.');
        $units = $this->units(self::DOC, '### Delivers as');
        foreach (NotificationVisibility::PERMISSIONS as $group => $permission) {
            $row = '| ' . $labels[$group] . ' | ' . ($permission === null ? 'none' : '`' . $permission . '`') . ' |';
            $this->assertNotEmpty(
                array_filter($units, static fn (string $unit): bool => str_starts_with($unit, $row)),
                "Delivers as has the row {$row}"
            );
        }
    }

    /**
     * Delivery: the queue, the order of a run's deliveries, the retry budget
     * as the delivery service has it, the statuses with their audit
     * entries, the sweep and its limits, and the notification-worker with
     * what it needs and where its log is.
     */
    public function testDeliveryNamesTheQueueTheRetriesTheStatusesAndTheSweep(): void
    {
        $service = new NotificationDeliveryService();
        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400], $service->backoffSeconds, 'The steps the docs name.');
        $this->assertSame(8, $service->maxAttempts);
        $this->assertSame([408, 425, 429], DeliveryOutcome::TRANSIENT_STATUSES);
        $this->assertSame(3600, NotificationDeliveryService::RETRY_AFTER_MAX_SECONDS);
        $this->assertSame(120, NotificationDeliveryService::REPUSH_AFTER_SECONDS);
        $this->assertSame(180, NotificationDeliveryService::STALE_SENDING_SECONDS);
        $this->assertSame(30 * 86400, NotificationDeliveryService::RETENTION_SECONDS);
        $this->assertSame(27750, array_sum($service->backoffSeconds), 'About 7.7 hours.');
        $section = $this->flat(self::DOC, '## Delivery');

        foreach (
            [
                'a Redis queue of its own (`ansilume-notifications`)',
                'the answer `408`, `425`, `429` or `5xx`, is tried again: after 30 seconds, 2, 10 and 30 minutes, then 1, 2 and 4 hours, at most 8 attempts, the last about 7.7 hours after the first.',
                'with `Retry-After`, in seconds or as an HTTP date, the next attempt waits that long, up to an hour, and never less than its step.',
                'such as `400`, `401`, `403`, `404` or `413`',
                'it queues a pending delivery again that has been due for more than 2 minutes',
                'go out in the order of their events, on every channel: each waits until the earlier ones of the same template and run have ended',
                'While a delivery of a run waits for its next attempt, the later deliveries of the same template and run wait behind it',
                'it gives back a delivery that a worker claimed more than 3 minutes ago and did not finish',
                '`' . NotificationDeliveryService::WORKER_STOPPED . '`',
                'it removes the deliveries that finished more than 30 days ago.',
                '`[maintenance] ran: notification-sweep (repushed=1, reset=0, purged=0)`',
                'Each delivery writes one audit entry, for its outcome, never one per attempt. The entry carries no user',
                'runs `php yii worker/start --queue=notificationQueue` on the app image',
                'The service `notification-worker` (container `ansilume_notification_worker`)',
                'Redis and the database;',
                'outbound connections to every endpoint of the templates',
                'the file of `OUTBOUND_CA_BUNDLE`, when it is set',
                'a delivery takes at most the queue\'s 120 seconds',
                '`docker compose ps notification-worker` shows whether it runs, and `docker compose logs notification-worker` only that it started',
                '`' . sprintf(NotificationDeliveryService::RETRYING, 41, 3, 'webhook', 'job.failed', 1, 8, 30, sprintf(OutboundHttpException::FROM, 'WebhookChannel', sprintf(OutboundHttpException::HTTP_STATUS, 503))) . '`',
                'docker compose exec notification-worker tail -n 200 /var/www/runtime/logs/app.log',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'Delivery');
        }

        $statuses = [];
        foreach ($this->units(self::DOC, '### What a delivery ends as') as $unit) {
            if (preg_match('/^\| `([a-z]+)` \| .* \| (.*) \|$/', $unit, $match) === 1) {
                $statuses[$match[1]] = $match[2];
            }
        }
        $this->assertSame(NotificationDelivery::STATUSES, array_keys($statuses));
        $this->assertStringStartsWith('`notification.dispatched`', $statuses[NotificationDelivery::STATUS_DELIVERED]);
        $this->assertStringStartsWith('`notification.failed`', $statuses[NotificationDelivery::STATUS_FAILED]);
        $this->assertStringStartsWith('`notification.skipped`', $statuses[NotificationDelivery::STATUS_SKIPPED]);
        $this->assertSame('none', $statuses[NotificationDelivery::STATUS_SUPERSEDED]);
    }

    /**
     * The page of a template and Send Test, in the words the page and the
     * flash use.
     */
    public function testTheTemplatesPageAndSendTestAreDescribedInTheirWords(): void
    {
        $page = $this->flat(self::DOC, '### The template\'s page');
        foreach (
            [
                '`' . sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, self::USER) . '`',
                '`' . NotificationTemplatePage::ALL_WORKFLOWS . '`',
                '`' . sprintf(NotificationTemplatePage::HIDDEN_WORKFLOWS, 2) . '`',
                '`' . sprintf(NotificationTemplatePage::HIDDEN_DELIVERIES, 3) . '`',
                '`' . sprintf(NotificationTemplatePage::CHECK_KEPT, '2026-11-04 10:15', 'ansilume', self::CHANNEL_ID) . '`',
                'newest first, ' . NotificationTemplatePage::DELIVERIES_PER_PAGE . ' per page',
                '(`' . implode('`, `', array_slice(NotificationTemplatePage::KIND_LABELS, 0, -1)) . '` or `' . NotificationTemplatePage::KIND_LABELS[NotificationDelivery::KIND_REPLY] . '`)',
            ] as $needle
        ) {
            $this->assertMentions($needle, $page, 'The template\'s page');
        }
        foreach (NotificationTemplate::LEVEL_LABELS as $label) {
            $this->assertMentions('`' . $label . '`', $page, 'The template\'s page, on the level');
        }
        $test = $this->flat(self::DOC, '### Send Test');
        $this->assertMentions('`Test notification sent successfully.`', $test, 'Send Test');
        $this->assertMentions('`Test notification failed: `', $test, 'Send Test');
        $this->assertMentions('`502` with `Test failed: `', $test, 'Send Test');
    }

    /**
     * The Mattermost channel: the version, the setup steps, its config with
     * the refusals of its rules, the checks, the status post and which
     * events reply or mention, the escaping, the cut and the limits.
     */
    public function testTheMattermostChannelIsDescribedAsItWorks(): void
    {
        $setup = $this->flat(self::DOC, '### Setting it up');
        foreach (['Enable Bot Account Creation', 'Add Bot Account', 'the role **Member**', 'A personal access token does not work', '/invite @ansilume', '**Send Test**'] as $step) {
            $this->assertMentions($step, $setup, 'Setting it up');
        }
        $this->assertMentions('Mattermost 10.11 ESR or later', $this->flat(self::DOC, '## The Mattermost channel'), 'The Mattermost channel');

        $config = $this->rowsByFirstCell($this->units(self::DOC, '### Its config'));
        foreach (array_merge(MattermostConfig::DESTINATION_KEYS, ['mention']) as $key) {
            $this->assertNotEmpty(array_filter(array_keys($config), static fn (string $cell): bool => str_contains($cell, $key)), "Its config names {$key}.");
        }
        $configText = $this->flat(self::DOC, '### Its config');
        foreach (
            [
                sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url'),
                sprintf(NotificationConfigSecrets::URL_CHANGED, 'bot_token', 'server_url'),
                MattermostConfig::CHANNEL_TWICE,
                MattermostConfig::MENTION_EVERYONE,
            ] as $message
        ) {
            $this->assertMentions('`' . $message . '`', $configText, 'Its config');
        }
        $this->assertMentions('Saving checks the form of these only, and never asks Mattermost', $configText, 'Its config');

        $checks = $this->flat(self::DOC, '### The checks');
        $this->assertMentions('`GET ' . MattermostApi::PATH_ME . '`', $checks, 'The checks');
        $this->assertMentions('`' . sprintf(NotificationTemplatePage::CHECK_KEPT, '2026-11-04 10:15', 'ansilume', self::CHANNEL_ID) . '`', $checks, 'The checks');
        $this->assertMentions('A check that failed is not kept, so the next delivery asks again', $checks, 'The checks');

        $runs = $this->flat(self::DOC, '### Workflow runs: the status post and its replies');
        $this->assertMentions('at level `all`: `' . implode('`, `', array_slice(RunThread::REPLY_EVENTS, 0, -1)) . '` and `' . RunThread::REPLY_EVENTS[count(RunThread::REPLY_EVENTS) - 1] . '`;', $runs, 'Workflow runs');
        $this->assertMentions('the replies of `' . implode('`, `', array_slice(RunThread::ATTENTION_EVENTS, 0, -1)) . '` and `' . RunThread::ATTENTION_EVENTS[count(RunThread::ATTENTION_EVENTS) - 1] . '` start with the template\'s `mention`', $runs, 'Workflow runs');
        $this->assertMentions('the last ' . MattermostStatusPost::MAX_STEP_LINES . ' of a run that ran more', $runs, 'Workflow runs');
        $this->assertMentions('Every later change of the run edits it, whatever the template\'s events and level say', $runs, 'Workflow runs');
        $this->assertMentions('`workflow.launched` only creates the post.', $runs, 'Workflow runs');
        $this->assertNotContains(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, RunThread::REPLY_EVENTS);
        $this->assertMentions('(`ServiceSettings.PostEditTimeLimit`)', $runs, 'Workflow runs');

        $names = $this->flat(self::DOC, '### Names, Markdown and mentions');
        $this->assertSame('a\\_b \\#1 @' . "\u{200B}" . 'all', MattermostText::escape("a_b #1\n@all"), 'The escaping the docs describe.');
        $this->assertMentions('each character that Markdown reads is escaped with a backslash, a line break becomes a space, and each `@` is followed by a zero-width space', $names, 'Names, Markdown and mentions');
        $this->assertMentions('A text is cut at ' . MattermostText::MAX_LENGTH . ' characters, with a link to the rest in Ansilume, well below the ' . MattermostText::MATTERMOST_LIMIT . ' that Mattermost takes.', $names, 'Names, Markdown and mentions');
        $this->assertMentions('the link of a reply may get a preview of the page it opens, which is mostly Ansilume\'s login page', $names, 'Names, Markdown and mentions');

        $limits = $this->flat(self::DOC, '### Limits');
        $this->assertMentions('A delivery makes at most ' . MattermostApi::MAX_REQUESTS . ' requests to Mattermost', $limits, 'Limits');
        $this->assertMentions('which Mattermost keeps for 30 seconds', $limits, 'Limits');
        $this->assertMentions('may be posted twice', $limits, 'Limits');
    }

    /**
     * The API section names every path of web/openapi.yaml under
     * /api/v1/notification-templates with its method, every field of a
     * template and of a delivery, and the refusals of the body.
     */
    public function testTheApiSectionNamesEveryEndpointAndField(): void
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $section = $this->flat(self::DOC, '## The API');
        $named = 0;
        foreach ((array)$spec['paths'] as $path => $operations) {
            if (!str_starts_with((string)$path, '/api/v1/notification-templates')) {
                continue;
            }
            foreach (array_keys((array)$operations) as $method) {
                if ($method !== 'parameters') {
                    $this->assertMentions('`' . strtoupper((string)$method) . ' ' . $path . '`', $section, 'The API');
                    $named++;
                }
            }
        }
        $this->assertSame(7, $named, 'The seven endpoints of notification templates.');
        foreach (['NotificationTemplate', 'NotificationDelivery'] as $schema) {
            foreach (array_keys((array)($spec['components']['schemas'][$schema]['properties'] ?? [])) as $field) {
                $this->assertMentions('`' . $field . '`', $section, "The API, on the fields of {$schema}");
            }
        }
        foreach (
            [
                sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#41'),
                NotificationTemplateBody::EVENTS_FORM,
                NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM,
                MattermostConfig::MENTION_EVERYONE,
            ] as $refusal
        ) {
            $this->assertMentions('`' . $refusal . '`', $section, 'The API, on its refusals');
        }
        foreach (NotificationDelivery::KINDS as $kind) {
            $this->assertMentions('`' . $kind . '`', $section, 'The API, on the kinds of a delivery');
        }
        $this->assertMentions('`meta.hidden`', $section, 'The API');
    }

    /**
     * README.md names the feature: notifications of workflow runs and their
     * steps, the Mattermost status post, signed webhooks, channels of one's
     * own, the notification-worker, and lint runs in the queue; its docs
     * table says what the guide covers, and its architecture names the
     * notification worker.
     */
    public function testTheReadmeNamesTheFeature(): void
    {
        $readme = $this->flat('README.md', '## Features');
        foreach (
            [
                '**Notifications** — email, Slack, Microsoft Teams, webhook, Telegram, PagerDuty and Mattermost',
                'workflow runs and each of their steps (started, succeeded, failed, waiting for an approval or a resume)',
                'delivers only what the user it delivers as may see',
                'the notification-worker sends every notification from a queue of its own, with retries',
                'Mattermost keeps one status post per workflow run',
                'webhooks can be signed (HMAC-SHA256)',
                'channels of your own',
                '([guide](docs/notifications.md))',
                '**ansible-lint in the background**',
            ] as $needle
        ) {
            $this->assertMentions($needle, $readme, 'README.md, Features');
        }
        $this->assertMentions('[Notifications](docs/notifications.md) | Channels, events and variables', $this->flat('README.md', '## Docs'), 'README.md, Docs');
        $this->assertMentions('Notification Worker (notifications, retries)', $this->section('README.md', '## Architecture'), 'README.md, Architecture');
    }

    /**
     * deployment.md says what the notification-worker needs and names the
     * role's settings for its links and its CAs; artifacts.md names the
     * sweep among the maintenance tasks; installation.md says what APP_URL
     * defaults to, and names OUTBOUND_CA_BUNDLE.
     */
    public function testDeploymentArtifactsAndInstallationNameWhatTheFeatureNeeds(): void
    {
        $architecture = $this->flat('docs/deployment.md', '## Architecture');
        foreach (['needs Redis, the database and outbound connections to the endpoints of the notification templates', '[notifications.md](notifications.md#the-notification-worker)', 'the notification deliveries'] as $needle) {
            $this->assertMentions($needle, $architecture, 'deployment.md, Architecture');
        }
        $reference = $this->flat('docs/deployment.md', '## Configuration reference');
        $this->assertMentions('| `ansilume_app_url` | `""` |', $reference, 'deployment.md, Configuration reference');
        $this->assertMentions('| `ansilume_outbound_ca_bundle` | `""` |', $reference, 'deployment.md, Configuration reference');
        $this->assertMentions('The queue messages of lint runs and of notifications need none either', $this->flat('docs/deployment.md', '## Backup'), 'deployment.md, Backup');

        $artifacts = $this->flat('docs/artifacts.md');
        $this->assertMentions('sweeps the notification deliveries (`notification-sweep`', $artifacts, 'artifacts.md');
        $this->assertMentions('so that slow webhook endpoints do not hold up the schedules', $artifacts, 'artifacts.md');
        $this->assertStringNotContainsString('slow notification endpoints', $artifacts, 'artifacts.md: a notification only queues a delivery now.');

        $installation = $this->flat('docs/installation.md', '## Configuration reference');
        $this->assertMentions('Unset, it is `http://localhost:8080`, which only the host itself reaches', $installation, 'installation.md, APP_URL');
        $this->assertMentions('`ansilume_app_url`', $installation, 'installation.md, APP_URL');
        $this->assertStringNotContainsString('fall back to the internal docker hostname', $installation, 'installation.md said what no release did.');
        $this->assertMentions('| `OUTBOUND_CA_BUNDLE` |', $installation, 'installation.md, Email / notifications');
        // Regression (plan Task 14): both said that ADMIN_EMAIL receives
        // alerts like runner-offline, which nothing sends it: the events go
        // to the notification templates that listen to them.
        $this->assertMentions('Ansilume sends no alert to it: alerts such as `runner.offline` go to the notification templates that listen to them', $installation, 'installation.md, ADMIN_EMAIL');
        $this->assertMentions('Ansilume sends no alert to it: alerts go to the notification templates that listen to them', $reference, 'deployment.md, ansilume_admin_email');
        $this->assertStringNotContainsString('Destination for system-level alerts', $installation);
        $this->assertStringNotContainsString('Admin notification address', $reference);
    }

    /**
     * The setting $name of the channel definition $definition (of
     * ChannelRegistry::BUILT_IN); [] when it has none.
     *
     * @param array<string, mixed> $definition
     * @return array<int|string, mixed>
     */
    private static function setting(array $definition, string $name): array
    {
        return (array)($definition[$name] ?? []);
    }

    /**
     * The rows of a table of $units by their first cell, without its
     * backticks: the whole row for each.
     *
     * @param list<string> $units
     * @return array<string, string>
     */
    private function rowsByFirstCell(array $units): array
    {
        $rows = [];
        foreach ($units as $unit) {
            if (preg_match('/^\| ([^|]+?) \|/', $unit, $match) === 1 && !in_array(trim($match[1], '-'), ['', 'Level', 'Workflow scope', 'Key'], true)) {
                $rows[trim($match[1], '`')] = $unit;
            }
        }

        return $rows;
    }

    /**
     * The first code block of $language in $text.
     */
    private function codeBlock(string $text, string $language): string
    {
        $this->assertSame(1, preg_match('/^\s*```' . $language . '\n(.*?)^\s*```$/ms', $text, $match), "A {$language} block.");

        return (string)preg_replace('/^  /m', '', $match[1]);
    }
}
