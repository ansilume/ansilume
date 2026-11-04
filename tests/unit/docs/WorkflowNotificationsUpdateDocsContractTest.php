<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ApiBodyValues;
use app\components\NotificationConfigSecrets;
use app\components\OutboundHttpClient;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\JobCompletionService;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\PagerDutyChannel;
use app\services\notification\WebhookChannel;
use app\services\notification\WebhookConfig;
use PHPUnit\Framework\TestCase;

/**
 * Contract: the update notes of the release after 2.14.0 (docs/updating.md)
 * say what workflow notifications change, in the words of the code: the
 * security fix of the outbound HTTP channels as a class of problem, the new
 * events, options and identity of templates, the notification-worker with
 * what each deployment path must do, the new audit actions, the Mattermost
 * channel, the two headers every webhook request now carries, the
 * migrations, and the way back to 2.14.0, which reverts the migrations
 * first and keeps the notification-worker off in an override (checked in a
 * throwaway compose project). Redis810UpdateDocsContractTest pins the Redis
 * parts of the same section, LintQueueDocsContractTest and
 * RecoveredFailuresDocsContractTest the lint queue and the recovered
 * failures.
 */
final class WorkflowNotificationsUpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const SECTION = Redis810UpdateDocsContractTest::UPDATE_SECTION;

    /** The migrations of the release, which `migrate/down 4` reverts. */
    private const MIGRATIONS = ['m000091', 'm000092', 'm000093', 'm000094'];

    /** The override that keeps the notification-worker of this release's compose file off on 2.14.0. */
    private const OVERRIDE = "```yaml\n# docker-compose.override.yml\nservices:\n  notification-worker:\n    profiles: [\"off\"]\n```";

    /**
     * The class of the migration of the feature, which no autoloader knows.
     *
     * @return class-string
     */
    private static function migration(): string
    {
        require_once dirname(__DIR__, 3) . '/migrations/m000091_000000_notification_delivery_and_options.php';

        return \m000091_000000_notification_delivery_and_options::class;
    }

    public function testTheSecurityFixIsNamedAsAClassOfProblemWithWhatToDo(): void
    {
        $notes = $this->flat('docs/updating.md', self::SECTION);
        $client = new OutboundHttpClient();

        foreach (
            [
                '**Security fix: notification channels sent to more than http and https.**',
                'a user who may create or change notification templates, operators by default, could make the app container read local resources',
                sprintf('within %d seconds for the connection and %d for the whole request', $client->connectTimeout, $client->timeout),
                sprintf('reading at most %d KiB of an answer', intdiv($client->maxBodyBytes, 1024)),
                'never the URL, a header or what the endpoint answered',
                '`' . sprintf(NotificationTemplate::URL_SCHEME_REFUSED, 'webhook_url') . '`',
                'rotate the incoming-webhook URL of every channel whose ID or token appears there',
                'the creator of a notification template (`created_by`) could be set through a field of the edit form',
                '`ansilume_outbound_ca_bundle`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
    }

    public function testTheNotesNameTheEventsTheOptionsAndTheIdentity(): void
    {
        $notes = $this->flat('docs/updating.md', self::SECTION);
        foreach (
            [
                NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED,
                NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED,
                NotificationTemplate::EVENT_WORKFLOW_WAITING,
            ] as $event
        ) {
            $this->assertMentions('`' . $event . '`', $notes, 'the update notes, on the new events');
        }
        foreach (['`{{ event_id }}`', '`{{ occurred_at }}`', '`{{ workflow.reason }}`', '`{{ trigger.note }}`', '`workflow_scope` `selected`', '`essential`'] as $needle) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
        $this->assertMentions(
            '`' . trim(sprintf(self::migration()::INACTIVE_IDENTITIES, '#3, #9'), " >\n") . '`',
            $notes,
            'the update notes, on the templates of inactive creators'
        );
        $this->assertMentions('`delivers_as_inactive: true`', $notes, 'the update notes');
        $this->assertMentions('an empty `PUT /api/v1/notification-templates/{id}` does it', $notes, 'the update notes');
    }

    public function testTheNotesSayWhatEachDeploymentPathDoesForTheNotificationWorker(): void
    {
        $notes = $this->flat('docs/updating.md', self::SECTION);
        $service = new NotificationDeliveryService();
        $this->assertSame([30, 120, 600, 1800, 3600, 7200, 14400], $service->backoffSeconds);
        foreach (
            [
                '`notification-worker` (container `ansilume_notification_worker`, `php yii worker/start --queue=notificationQueue`)',
                'after 30 seconds, 2, 10 and 30 minutes, then 1, 2 and 4 hours, at most 8 attempts',
                '`notification-worker: not in the compose file — the app image queues the notifications for it, and no container sends them: take the compose file of this release`',
                '**Prebuilt images**',
                '**Source installations** started from `docker-compose.yml`',
                '**The deploy role**',
                '`ansilume_app_url`',
                '`docker compose exec notification-worker tail -n 200 /var/www/runtime/logs/app.log`; an installation the quickstart made from source adds `-f docker-compose.prebuilt.yml`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
    }

    public function testTheNotesNameTheAuditActionsTheHeadersAndTheFixes(): void
    {
        $notes = $this->flat('docs/updating.md', self::SECTION);
        foreach (
            [
                '`' . AuditLog::ACTION_NOTIFICATION_SKIPPED . '`',
                '`' . AuditLog::ACTION_NOTIFICATION_THROTTLED . '`',
                'these entries carry no user',
                '`' . sprintf(NotificationDeliveryService::INACTIVE_IDENTITY, 12) . '`',
                '`' . WebhookChannel::EVENT_HEADER . ': <event>`',
                '`' . WebhookChannel::DELIVERY_HEADER . ': <delivery ID>`',
                'A receiver whose proxy or firewall lets only known headers through sees two new ones.',
                '`' . sprintf(WebhookConfig::HEADER_RESERVED, WebhookChannel::EVENT_HEADER) . '`',
                '`' . sprintf(NotificationConfigSecrets::URL_CHANGED, 'signing_secret', 'url') . '`',
                '`' . sprintf(NotificationConfigSecrets::URL_CHANGED, 'bot_token', 'server_url') . '`',
                '`' . sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#41') . '`',
                '`' . sprintf(ApiBodyValues::NOT_A_VALUE, 'name') . '`',
                '`' . sprintf(JobCompletionService::ANNOUNCE_FAILED, 'notification', 42, '<error>', '<state>', '<code>') . '`',
                '`Test failed: ' . sprintf(NotificationDispatcher::SENDS_NOTHING, NotificationTemplate::CHANNEL_PAGERDUTY, PagerDutyChannel::NEVER_TRIGGERED[0]) . '`',
                'Mattermost 10.11 ESR or later',
                '`' . WebhookConfig::HEADERS_FORM . '`',
                'the card of a succeeded job was grey, as its colour went with `successful`, a status no job has',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
        $channelDocs = $this->flat('docs/notifications.md', '## The webhook channel');
        foreach (
            [
                '`' . WebhookConfig::HEADERS_FORM . '`',
                '`' . sprintf(WebhookConfig::HEADER_NAME_REFUSED, 'X A') . '`',
                '`' . sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A') . '`',
                // Review finding: receivers were told to drop delivery IDs they had seen, which start again after going back.
                'A delivery ID counts up within one installation and starts again when its outbox does',
            ] as $needle
        ) {
            $this->assertMentions($needle, $channelDocs, 'docs/notifications.md, The webhook channel');
        }
    }

    /**
     * The way back reverts the four migrations of the release with the
     * release, then keeps its compose file with the images of 2.14.0 and
     * the notification-worker off in an override, in every deployment path:
     * the quickstart's source installation names both files with -f, the
     * deploy role takes the override from its install directory, and a
     * source installation started from docker-compose.yml reads it beside
     * that file. Review finding: the Redis notes' way back pinned 2.14.0 on
     * the compose file of this release, whose notification-worker then ran
     * the `worker/start` of 2.14.0 and restarted without end, and the
     * troubleshooting entry offered the compose file of 2.14.0, whose Redis
     * 7.2 cannot load the data of 8.10.
     */
    public function testTheNotesSayHowToGoBackTo2140(): void
    {
        $raw = $this->section('docs/updating.md', self::SECTION);
        $notes = $this->flat('docs/updating.md', self::SECTION);
        $this->assertCount(count(self::MIGRATIONS), glob(dirname(__DIR__, 3) . '/migrations/m00009[1-9]_*.php') ?: [], 'migrate/down 4 reverts every migration of the release');
        foreach (self::MIGRATIONS as $migration) {
            $this->assertMentions('`' . $migration . '`', $notes, 'the update notes, on the migrations');
        }
        $this->assertStringContainsString(self::OVERRIDE, $raw, 'the update notes give the override');
        foreach (
            [
                '`migrate/down 4` reverts `m000094`, `m000093`, `m000092` and `m000091`',
                'docker compose exec -u www-data app php yii migrate/down 4 --interactive=0',
                '`' . trim(sprintf(self::migration()::UNSENT_DROPPED, 3), " >\n") . '`',
                '`' . trim(sprintf(self::migration()::OPTIONS_REMOVED, '#3'), " >\n") . '`',
                'once it runs it cannot revert them, as it has not their code',
                '`Error: Unknown option: --queue`',
                'docker compose rm -sf notification-worker',
                '`docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml up -d`',
                'put the override into the install directory, where the role uses it, and run the role with `ansilume_version: "v2.14.0"`',
                'put the override beside `docker-compose.yml`, where `docker compose` reads it',
                'Remove the override when you update again.',
                // Review finding: going back named only the way back, not what 2.14.0 brings back.
                'Going back also brings back what this release fixes.',
                'so delete those templates before you go back',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes, on going back');
        }
        $this->assertMentions('docker-compose.override.yml', $this->read('deploy/roles/ansilume/tasks/main.yaml'), 'the deploy role, which looks for the override');
        $this->assertMentions('docker-compose.override.yml', $this->read('.gitignore'), '.gitignore, so that `git checkout main` keeps the override');

        $rollback = $this->flat('docs/updating.md', '## Rollback');
        $this->assertMentions('revert its migrations with that release before you start 2.14.0 (`migrate/down 4`: once 2.14.0 runs, it cannot revert them), delete the Mattermost templates and those of channels of your own, keep the compose file of the newer release with its notification-worker off', $rollback, 'the rollback');

        $entry = $this->flat('docs/troubleshooting.md', '## Notifications are not sent: the notification-worker does not run');
        $this->assertMentions('keep the service off in an override and remove its container once (`docker compose rm -sf notification-worker`)', $entry, 'troubleshooting.md');
        $this->assertStringNotContainsString('take the compose file of that release', $entry, 'troubleshooting.md: the compose file of 2.14.0 runs Redis 7.2');
    }
}
