<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\NotificationTemplateController;
use app\models\ApiToken;
use app\models\NotificationTemplate;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * created_by of a notification template is the user who created it: set by
 * the create action, never taken from a form or a request body.
 *
 * Regression: the web form's model took created_by from the post (a safe
 * attribute), so whoever could edit a template could name any user as its
 * creator, an admin included.
 */
class NotificationTemplateCreatedByTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testRegressionTheWebFormCannotSetCreatedBy(): void
    {
        $admin = $this->createUserWithRole('nt-created-by-admin', 'admin');
        $operator = $this->createUserWithRole('nt-created-by-operator', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->loginAs($operator);
        $this->setPost(['NotificationTemplate' => $this->formFields($template, 'renamed') + ['created_by' => (string)$admin->id]]);

        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate((int)$template->id));

        $stored = $this->reload($template);
        $this->assertSame($template->name . '-renamed', $stored->name, 'the rest of the form was saved');
        $this->assertSame((int)$operator->id, (int)$stored->created_by);
    }

    /**
     * On create, the creator is the signed-in user, whatever the form says.
     */
    public function testTheWebFormCreatesTheTemplateAsTheSignedInUser(): void
    {
        $admin = $this->createUserWithRole('nt-created-by-admin-c', 'admin');
        $operator = $this->createUserWithRole('nt-created-by-operator-c', 'operator');
        $this->loginAs($operator);
        $name = 'nt-created-by-new-' . uniqid('', true);
        $this->setPost(['NotificationTemplate' => [
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["ops@example.com"]}',
            'events' => 'job.failed',
            'created_by' => (string)$admin->id,
        ]]);

        $this->assertInstanceOf(Response::class, $this->webController()->actionCreate());

        $created = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $created);
        $this->assertSame((int)$operator->id, (int)$created->created_by);
    }

    /**
     * PUT /api/v1/notification-templates/{id} with created_by keeps the
     * stored creator, and the answer says so.
     */
    public function testTheApiCannotSetCreatedBy(): void
    {
        $admin = $this->createUserWithRole('nt-created-by-admin-api', 'admin');
        $operator = $this->createUserWithRole('nt-created-by-operator-api', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);
        $this->setBody(['name' => $template->name . '-api', 'created_by' => (int)$admin->id]);

        $answer = $this->apiController()->runAction('update', ['id' => $template->id]);

        $this->assertIsArray($answer);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame((int)$operator->id, $answer['data']['created_by'] ?? null);
        $stored = $this->reload($template);
        $this->assertSame($template->name . '-api', $stored->name);
        $this->assertSame((int)$operator->id, (int)$stored->created_by);
    }

    /**
     * POST /api/v1/notification-templates with created_by creates the
     * template as the caller.
     */
    public function testTheApiCreatesTheTemplateAsTheCaller(): void
    {
        $admin = $this->createUserWithRole('nt-created-by-admin-apic', 'admin');
        $operator = $this->createUserWithRole('nt-created-by-operator-apic', 'operator');
        $this->authenticate($operator);
        $name = 'nt-created-by-api-' . uniqid('', true);
        $this->setBody([
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => ['emails' => ['ops@example.com']],
            'events' => 'job.failed',
            'created_by' => (int)$admin->id,
        ]);

        $answer = $this->apiController()->runAction('create');

        $this->assertIsArray($answer);
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $created = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $created);
        $this->assertSame((int)$operator->id, (int)$created->created_by);
    }

    /**
     * The form's fields of $template, its name with $suffix.
     *
     * @return array<string, string>
     */
    private function formFields(NotificationTemplate $template, string $suffix): array
    {
        return [
            'name' => $template->name . '-' . $suffix,
            'description' => '',
            'channel' => $template->channel,
            'config' => (string)$template->config,
            'events' => $template->events,
            'subject_template' => (string)$template->subject_template,
            'body_template' => (string)$template->body_template,
        ];
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-created-by-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function webController(): NotificationTemplateController
    {
        return new class ('notification-template', \Yii::$app) extends NotificationTemplateController {
            use CapturesParams;
        };
    }

    private function apiController(): NotificationTemplatesController
    {
        /** @var \yii\base\Module $app */
        $app = \Yii::$app;

        return new NotificationTemplatesController('api/v1/notification-templates', $app);
    }
}
