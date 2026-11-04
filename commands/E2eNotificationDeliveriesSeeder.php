<?php

declare(strict_types=1);

namespace app\commands;

use app\models\JobTemplate;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationWorkflowFilter;

/**
 * The fixture of the E2E specs of a notification template's page
 * (tests/e2e/tests/notification-templates/deliveries.spec.ts, rbac.spec.ts):
 *
 * - NAME: a Mattermost template that e2e-admin created and that delivers as
 *   IDENTITY, a user who is inactive, so the page warns that it delivers
 *   nothing, and it truly delivers nothing: neither an event nor a due
 *   delivery reaches its server, whose address (SERVER_URL) is a port
 *   nothing listens on, with a token (BOT_TOKEN) that is no Mattermost's.
 *   It keeps a passed check of its config (channel_state), so the page shows
 *   the bot (BOT_USERNAME) and the channel (CHANNEL_ID). Its workflow filter
 *   holds WORKFLOWS (E2eWorkflowScopingSeeder): e2e-operator's team may see
 *   e2e-alpha-wf, e2e-viewer's team e2e-beta-wf; its level is essential.
 * - Its deliveries, all finished, so no sweep sends them again: a runner
 *   event delivered and one that failed for good, which every role may see;
 *   a waiting run of e2e-alpha-wf, which e2e-viewer may not see; and a
 *   schedule event of e2e-alpha-tmpl that was skipped, which e2e-viewer,
 *   who holds no job.launch, may not see either.
 *
 * Restored by name on every seed; its deliveries are written again. The
 * teardown removes the template, its deliveries (foreign key) and IDENTITY
 * with every e2e- row. Runs after E2eWorkflowScopingSeeder, whose runs it
 * names; without them, it writes no filter and no delivery of a run.
 */
final class E2eNotificationDeliveriesSeeder
{
    public const NAME = 'e2e-mattermost-deliveries';

    /** The inactive user the template delivers as. */
    public const IDENTITY = 'e2e-nt-dormant';

    /** Port 1 of the app container: nothing listens there. */
    public const SERVER_URL = 'https://127.0.0.1:1';

    /** A placeholder, never a token of Mattermost: nothing is ever sent with it. */
    public const BOT_TOKEN = 'e2e-mattermost-token-never-sent';

    public const TEAM = 'e2e-team';

    public const CHANNEL = 'e2e-deploys';

    public const MENTION = '@e2e-ops';

    /** The channel and the bot of the kept check, as Mattermost names them: 26 lower-case letters and digits. */
    public const CHANNEL_ID = 'e2echannelid00000000000001';

    public const BOT_USER_ID = 'e2ebotuserid00000000000001';

    public const BOT_USERNAME = 'e2e-bot';

    /** The workflow templates of its filter (E2eWorkflowScopingSeeder). */
    public const WORKFLOWS = ['e2e-alpha-wf', 'e2e-beta-wf'];

    /** The job template of its schedule delivery (E2eTeamScopingSeeder). */
    public const SCHEDULE_TEMPLATE = 'e2e-alpha-tmpl';

    public const EVENTS = [
        NotificationTemplate::EVENT_WORKFLOW_WAITING,
        NotificationTemplate::EVENT_WORKFLOW_FAILED,
        NotificationTemplate::EVENT_RUNNER_OFFLINE,
        NotificationTemplate::EVENT_RUNNER_RECOVERED,
        NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH,
    ];

    /** The detail of the failed delivery's error: a 401, as Mattermost answers a revoked token. */
    public const TOKEN_DETAIL = 'HTTP 401';

    /** What a seed says; sprintf(): the name, its ID, the user it delivers as, how many deliveries. */
    public const RESTORED = "  Restored %s (ID %d): a Mattermost template that delivers as the inactive %s, with %d deliveries.\n";

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore the template, which $userId (e2e-admin) created, its filter,
     * the user it delivers as and its deliveries.
     */
    public function seed(int $userId): void
    {
        $identity = $this->identity();
        $template = $this->template($userId, (int)$identity->id);
        $workflows = WorkflowTemplate::find()->where(['name' => self::WORKFLOWS])->orderBy(['id' => SORT_ASC])->all();
        NotificationWorkflowFilter::write($template, array_map(static fn (WorkflowTemplate $w): int => (int)$w->id, $workflows), $userId);
        $count = $this->deliveries($template, (int)$identity->id, $workflows);

        ($this->logger)(sprintf(self::RESTORED, self::NAME, (int)$template->id, self::IDENTITY, $count));
    }

    /**
     * IDENTITY: inactive, no superadmin, no role; it never signs in, so its
     * password is random.
     */
    private function identity(): User
    {
        $user = User::findOne(['username' => self::IDENTITY]) ?? new User();
        $user->username = self::IDENTITY;
        $user->email = self::IDENTITY . '@example.com';
        $user->status = User::STATUS_INACTIVE;
        $user->is_superadmin = false;
        if ($user->isNewRecord) {
            $user->generateAuthKey();
            $user->setPassword(\Yii::$app->security->generateRandomString(24));
        }
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $auth?->revokeAll((int)$user->id);

        return $user;
    }

    /**
     * The template, with the check of its config kept.
     */
    private function template(int $userId, int $identityId): NotificationTemplate
    {
        $template = NotificationTemplate::findOne(['name' => self::NAME]) ?? new NotificationTemplate();
        $template->name = self::NAME;
        $template->description = 'E2E fixture: delivers as an inactive user, with deliveries';
        $template->channel = NotificationTemplate::CHANNEL_MATTERMOST;
        $template->config = (string)json_encode([
            'server_url' => self::SERVER_URL,
            'bot_token' => self::BOT_TOKEN,
            'team' => self::TEAM,
            'channel' => self::CHANNEL,
            'mention' => self::MENTION,
        ], JSON_UNESCAPED_SLASHES);
        $template->events = implode(',', self::EVENTS);
        $template->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $template->subject_template = 'E2E {{ event }}';
        $template->body_template = 'E2E notification for {{ event }}.';
        $template->channel_state = (string)json_encode([
            'fingerprint' => MattermostConfig::of($template)->fingerprint(),
            'channel_id' => self::CHANNEL_ID,
            'bot_user_id' => self::BOT_USER_ID,
            'bot_username' => self::BOT_USERNAME,
            'checked_at' => time() - 3600,
        ]);
        $template->created_by = $userId;
        $template->delivers_as = $identityId;
        $template->save(false);

        return $template;
    }

    /**
     * The deliveries, written again, oldest first: how many.
     *
     * @param list<WorkflowTemplate> $workflows
     */
    private function deliveries(NotificationTemplate $template, int $identityId, array $workflows): int
    {
        NotificationDelivery::deleteAll(['notification_template_id' => (int)$template->id]);
        $now = time();
        $rows = [
            ['event' => NotificationTemplate::EVENT_RUNNER_OFFLINE, 'status' => NotificationDelivery::STATUS_DELIVERED, 'attempts' => 1, 'finished_at' => $now - 14400],
            [
                'event' => NotificationTemplate::EVENT_RUNNER_RECOVERED,
                'status' => NotificationDelivery::STATUS_FAILED,
                'attempts' => 8,
                'last_error' => sprintf(MattermostApiException::TOKEN_REFUSED, self::TOKEN_DETAIL),
                'finished_at' => $now - 10800,
            ],
        ];
        $run = $this->runOf($workflows);
        if ($run !== null) {
            $rows[] = [
                'event' => NotificationTemplate::EVENT_WORKFLOW_WAITING,
                'kind' => NotificationDelivery::KIND_STATUS,
                'status' => NotificationDelivery::STATUS_DELIVERED,
                'attempts' => 1,
                'refs' => ['workflow_job_id' => $run],
                'finished_at' => $now - 7200,
            ];
        }
        $jobTemplateId = JobTemplate::find()->select('id')->where(['name' => self::SCHEDULE_TEMPLATE])->scalar();
        if ($jobTemplateId !== false && $jobTemplateId !== null) {
            $rows[] = [
                'event' => NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH,
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'attempts' => 0,
                'last_error' => sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, $identityId),
                'refs' => ['job_template_id' => (int)$jobTemplateId],
                'finished_at' => $now - 3600,
            ];
        }
        foreach ($rows as $row) {
            $this->delivery($template, $row);
        }

        return count($rows);
    }

    /**
     * A finished delivery as $row says, created a minute before it
     * finished; a message unless $row names another kind.
     *
     * @param array{event: string, kind?: string, status: string, attempts: int, last_error?: string, refs?: array{workflow_job_id?: int, job_template_id?: int}, finished_at: int} $row
     */
    private function delivery(NotificationTemplate $template, array $row): void
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = $row['event'];
        $delivery->kind = $row['kind'] ?? NotificationDelivery::KIND_MESSAGE;
        $delivery->status = $row['status'];
        $delivery->attempts = $row['attempts'];
        $delivery->last_error = $row['last_error'] ?? null;
        $delivery->setVariableMap(['event' => $row['event']]);
        $delivery->setRefs($row['refs'] ?? []);
        $delivery->next_attempt_at = $row['finished_at'] - 60;
        $delivery->save(false);
        $delivery->updateAttributes(['created_at' => $row['finished_at'] - 60, 'updated_at' => $row['finished_at'], 'finished_at' => $row['finished_at']]);
    }

    /**
     * The newest run of the first of $workflows, e2e-alpha-wf; null without
     * one.
     *
     * @param list<WorkflowTemplate> $workflows
     */
    private function runOf(array $workflows): ?int
    {
        foreach ($workflows as $workflow) {
            if ($workflow->name !== self::WORKFLOWS[0]) {
                continue;
            }
            $run = WorkflowJob::find()->select('id')->where(['workflow_template_id' => (int)$workflow->id])->orderBy(['id' => SORT_DESC])->scalar();

            return $run === false || $run === null ? null : (int)$run;
        }

        return null;
    }
}
