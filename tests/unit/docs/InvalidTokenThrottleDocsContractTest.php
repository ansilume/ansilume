<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\notification\NotificationOutbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The trigger's 404 for an unknown token sends webhook.invalid_token to a
 * notification template at most once every 5 minutes, and audits every
 * further call as notification.throttled (NotificationOutbox); the
 * troubleshooting entry and the API spec of the 404 say so.
 *
 * Regression (workflow notifications plan, Task 7): both said that every
 * call with an unknown token sends the notification.
 */
class InvalidTokenThrottleDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const TRIGGER_ENTRY = '## Trigger answers 400, 404, 405 or 422';

    /** What both texts say, after the notification is named. */
    private const THROTTLE = 'A notification template gets it at most once every 5 minutes; every further call is in the audit log as `notification.throttled`.';

    public function testTheThrottleIsTheOneTheTextsName(): void
    {
        $this->assertSame(300, NotificationOutbox::THROTTLE_SECONDS[NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN]);
        $this->assertSame('notification.throttled', AuditLog::ACTION_NOTIFICATION_THROTTLED);
    }

    public function testTheTroubleshootingEntrySaysItIsThrottled(): void
    {
        $this->assertMentions(self::THROTTLE, $this->flat('docs/troubleshooting.md', self::TRIGGER_ENTRY), 'troubleshooting.md');
    }

    public function testTheSpecOfTheTriggers404SaysItIsThrottled(): void
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $description = $spec['components']['responses']['TriggerNotFound']['description'] ?? null;
        $this->assertIsString($description, 'web/openapi.yaml describes the TriggerNotFound response');

        $this->assertMentions(self::THROTTLE, trim((string)preg_replace('/\s+/', ' ', $description)), 'web/openapi.yaml');
    }
}
