<?php

declare(strict_types=1);

namespace app\commands;

use app\models\NotificationTemplate;

/**
 * The fixture of the E2E spec for a test notification whose channel fails
 * (tests/e2e/tests/notification-templates/send-test.spec.ts):
 *
 * - e2e-failing-notification: a webhook to a port nothing listens on in the
 *   app container, so every delivery fails at once (HTTP 0). It listens only
 *   to EVENT, which `yii health/check-runners` dispatches, and the E2E stack
 *   never runs it: only Send Test delivers it.
 *
 * Restored by name on every seed.
 */
final class E2eFailingNotificationSeeder
{
    public const NAME = 'e2e-failing-notification';

    /** Port 1 of the app container: nothing listens there, the connection is refused at once. */
    public const URL = 'http://127.0.0.1:1/e2e-failing-notification';

    public const EVENT = NotificationTemplate::EVENT_RUNNER_RECOVERED;

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore the notification template, which $userId (e2e-admin) created.
     */
    public function seed(int $userId): void
    {
        $template = NotificationTemplate::findOne(['name' => self::NAME]) ?? new NotificationTemplate();
        $template->name = self::NAME;
        $template->description = 'E2E fixture: every delivery fails';
        $template->channel = NotificationTemplate::CHANNEL_WEBHOOK;
        $template->config = (string)json_encode(['url' => self::URL], JSON_UNESCAPED_SLASHES);
        $template->events = self::EVENT;
        $template->subject_template = 'E2E {{event}}';
        $template->body_template = 'E2E notification for {{event}}.';
        $template->created_by = $userId;
        $template->save(false);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d): a webhook to %s, which refuses every delivery.\n",
            self::NAME,
            $template->id,
            self::URL
        ));
    }
}
