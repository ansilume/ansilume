<?php

declare(strict_types=1);

namespace app\commands;

use app\models\NotificationTemplate;
use app\services\notification\PagerDutyChannel;

/**
 * The fixture of the E2E spec for a test notification of events its
 * channel sends nothing for
 * (tests/e2e/tests/notification-templates/send-test.spec.ts):
 *
 * - e2e-silent-notification: a PagerDuty template that listens only to the
 *   info events of a workflow step (EVENTS), which never trigger an
 *   incident (PagerDutyChannel::NEVER_TRIGGERED). Its Send Test sends
 *   nothing and says why, and the steps of the E2E workflows give it no
 *   delivery, so its routing key, a placeholder, never reaches PagerDuty.
 *
 * Restored by name on every seed.
 */
final class E2eSilentNotificationSeeder
{
    public const NAME = 'e2e-silent-notification';

    /** A placeholder, never a key of PagerDuty: nothing is ever sent with it. */
    public const ROUTING_KEY = 'e2e-routing-key-never-sent';

    public const EVENTS = PagerDutyChannel::NEVER_TRIGGERED;

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
        $template->description = 'E2E fixture: PagerDuty sends nothing for its events';
        $template->channel = NotificationTemplate::CHANNEL_PAGERDUTY;
        $template->config = (string)json_encode(['routing_key' => self::ROUTING_KEY]);
        $template->events = implode(',', self::EVENTS);
        $template->subject_template = 'E2E {{event}}';
        $template->body_template = 'E2E notification for {{event}}.';
        $template->created_by = $userId;
        $template->delivers_as = $userId;
        $template->save(false);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d): a PagerDuty template of events PagerDuty sends nothing for.\n",
            self::NAME,
            $template->id
        ));
    }
}
