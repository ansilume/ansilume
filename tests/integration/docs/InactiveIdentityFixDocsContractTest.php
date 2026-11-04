<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\notification\NotificationIdentity;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * What docs/troubleshooting.md tells an operator to do with a notification
 * template that delivers nothing, because the user it delivers as is
 * inactive, works: an empty PUT of the template through the API makes the
 * caller the user it delivers as, with the change in its audit entry, and
 * keeps everything else of the template.
 *
 * Written with plan Task 14 of the workflow notifications, with the entry.
 */
final class InactiveIdentityFixDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;
    use TeamScopeFixtures;

    private const ENTRY = '## A notification template delivers nothing: the user it delivers as is inactive';

    public function testAnEmptyPutMakesTheCallerTheUserTheTemplateDeliversAs(): void
    {
        $this->assertMentions("-d '{}'", $this->flat('docs/troubleshooting.md', self::ENTRY), 'The entry\'s fix');
        $gone = $this->createUserWithRole('nt-fix-gone', 'operator');
        $template = $this->createNotificationTemplate((int)$gone->id);
        $gone->status = User::STATUS_INACTIVE;
        $this->assertTrue($gone->save(false));
        $this->assertTrue($template->isDeliveringAsInactive());
        $operator = $this->createUserWithRole('nt-fix-operator', 'operator');
        ['raw' => $raw] = ApiToken::generate((int)$operator->id, 'nt-fix-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        \Yii::$app->user->setIdentity(null);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams([]);
        $template->refresh();
        $before = $template->getAttributes(['name', 'channel', 'config', 'events', 'level', 'workflow_scope', 'subject_template', 'body_template']);

        $answer = (new NotificationTemplatesController('api/v1/notification-templates', $this->app()))->runAction('update', ['id' => $template->id]);

        $this->assertIsArray($answer);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $this->assertSame((int)$operator->id, (int)$stored->delivers_as);
        $this->assertFalse($stored->isDeliveringAsInactive());
        $this->assertSame($before, $stored->getAttributes(array_keys($before)), 'Nothing else changed.');
        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, 'object_id' => $template->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);
        $this->assertSame(['from' => (int)$gone->id, 'to' => (int)$operator->id], $context[NotificationIdentity::AUDIT_KEY] ?? null);
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);

        return $app;
    }
}
