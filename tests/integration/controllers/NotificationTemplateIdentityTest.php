<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\NotificationTemplateController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\notification\NotificationTemplateFormPost;
use app\tests\integration\TeamScopeFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\web\Response;

/**
 * The save paths of a notification template apply whom it delivers as
 * (delivers_as, NotificationIdentity): a new template delivers as its
 * creator; an update that changes what or where it delivers makes the
 * editor the user it delivers as, recorded in its
 * notification-template.updated audit entry as delivers_as {from, to}.
 *
 * Regression (Review focus 5 of the workflow notifications plan): an
 * operator who edited the destination of an admin's template, in the web
 * form or with PUT /api/v1/notification-templates/{id}, kept the admin as
 * the only user named on it, so the template delivered what the admin may
 * see, the events of every team, wherever the operator sent it.
 */
class NotificationTemplateIdentityTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const ADMIN_HOOK = 'https://hooks.slack.com/services/ADMIN';
    private const OPERATOR_HOOK = 'https://hooks.slack.com/services/OPERATOR';

    private User $admin;
    private User $operator;
    private NotificationTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('nt-identity-admin', 'admin');
        $this->operator = $this->createUserWithRole('nt-identity-operator', 'operator');
        $this->template = $this->createNotificationTemplate(
            (int)$this->admin->id,
            NotificationTemplate::CHANNEL_SLACK,
            'job.failed,workflow.failed',
            (string)json_encode(['webhook_url' => self::ADMIN_HOOK], JSON_UNESCAPED_SLASHES)
        );
    }

    public function testRegressionAnOperatorWhoEditsTheDestinationOfAnAdminsTemplateInTheFormBecomesItsIdentity(): void
    {
        $this->loginAs($this->operator);
        $this->setPost(['NotificationTemplate' => $this->formFields(['config' => '{"webhook_url":"' . self::OPERATOR_HOOK . '"}'])]);

        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate((int)$this->template->id));

        $stored = $this->reload();
        $this->assertSame(['webhook_url' => self::OPERATOR_HOOK], $stored->getParsedConfig());
        $this->assertSame((int)$this->operator->id, (int)$stored->delivers_as);
        $this->assertSame((int)$this->admin->id, (int)$stored->created_by);
        $this->assertSame(
            ['name' => $stored->name, 'delivers_as' => ['from' => (int)$this->admin->id, 'to' => (int)$this->operator->id]],
            $this->updatedAudit()
        );
    }

    public function testRegressionAnOperatorWhoEditsTheDestinationOfAnAdminsTemplateThroughTheApiBecomesItsIdentity(): void
    {
        $this->authenticate($this->operator);
        $this->setBody(['config' => ['webhook_url' => self::OPERATOR_HOOK]]);

        $answer = $this->apiController()->runAction('update', ['id' => $this->template->id]);

        $this->assertIsArray($answer);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $stored = $this->reload();
        $this->assertSame((int)$this->operator->id, (int)$stored->delivers_as);
        $this->assertSame(
            ['name' => $stored->name, 'delivers_as' => ['from' => (int)$this->admin->id, 'to' => (int)$this->operator->id]],
            $this->updatedAudit()
        );
    }

    /**
     * The events decide what the template delivers: a change through
     * either path makes the editor the user it delivers as.
     */
    public function testChangingTheEventsInEitherPathMakesTheEditorTheIdentity(): void
    {
        $this->loginAs($this->operator);
        $this->setPost(['NotificationTemplate' => $this->formFields(['events' => 'job.failed'])]);
        $this->webController()->actionUpdate((int)$this->template->id);
        $this->assertSame((int)$this->operator->id, (int)$this->reload()->delivers_as);

        $this->authenticate($this->admin);
        $this->setBody(['events' => 'job.failed,job.succeeded']);
        $this->apiController()->runAction('update', ['id' => $this->template->id]);
        $this->assertSame((int)$this->admin->id, (int)$this->reload()->delivers_as);
    }

    /**
     * A rename keeps the user it delivers as, with the webhook URL sent
     * back as the placeholder the form shows, and records no change of it.
     */
    public function testARenameInTheFormKeepsTheIdentity(): void
    {
        $this->loginAs($this->operator);
        $this->setPost(['NotificationTemplate' => $this->formFields([
            'name' => 'renamed in the form',
            'config' => '{"webhook_url":"' . SurveySecrets::PLACEHOLDER . '"}',
        ])]);

        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate((int)$this->template->id));

        $stored = $this->reload();
        $this->assertSame('renamed in the form', $stored->name);
        $this->assertSame(['webhook_url' => self::ADMIN_HOOK], $stored->getParsedConfig());
        $this->assertSame((int)$this->admin->id, (int)$stored->delivers_as);
        $this->assertSame(['name' => 'renamed in the form'], $this->updatedAudit());
    }

    public function testARenameThroughTheApiKeepsTheIdentity(): void
    {
        $this->authenticate($this->operator);
        $this->setBody(['name' => 'renamed by the api', 'config' => ['webhook_url' => SurveySecrets::PLACEHOLDER]]);

        $this->apiController()->runAction('update', ['id' => $this->template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $stored = $this->reload();
        $this->assertSame('renamed by the api', $stored->name);
        $this->assertSame((int)$this->admin->id, (int)$stored->delivers_as);
        $this->assertSame(['name' => 'renamed by the api'], $this->updatedAudit());
    }

    /**
     * A change the validation refuses changes nothing, the user it
     * delivers as included, and is not audited.
     */
    public function testARefusedChangeKeepsEverything(): void
    {
        $this->loginAs($this->operator);
        $this->setPost(['NotificationTemplate' => $this->formFields(['config' => '{"webhook_url":"file:///etc/hostname"}'])]);
        $this->assertSame('rendered:form', $this->webController()->actionUpdate((int)$this->template->id));

        $this->authenticate($this->operator);
        $this->setBody(['config' => ['webhook_url' => 'gopher://127.0.0.1/']]);
        $answer = $this->apiController()->runAction('update', ['id' => $this->template->id]);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(sprintf(NotificationTemplate::URL_SCHEME_REFUSED, 'webhook_url'), $answer['error']['message'] ?? null);

        $stored = $this->reload();
        $this->assertSame((int)$this->admin->id, (int)$stored->delivers_as);
        $this->assertSame(['webhook_url' => self::ADMIN_HOOK], $stored->getParsedConfig());
        $this->assertNull($this->updatedAudit());
    }

    /**
     * A save that a handler vetoes after the validation is neither audited
     * nor reported as saved: the form is shown again, the API answers 422.
     */
    public function testAVetoedSaveIsNotAudited(): void
    {
        $veto = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(NotificationTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $veto);
        try {
            $this->loginAs($this->operator);
            $this->setPost(['NotificationTemplate' => $this->formFields(['config' => '{"webhook_url":"' . self::OPERATOR_HOOK . '"}'])]);
            $this->assertSame('rendered:form', $this->webController()->actionUpdate((int)$this->template->id));

            $this->authenticate($this->operator);
            $this->setBody(['config' => ['webhook_url' => self::OPERATOR_HOOK]]);
            $answer = $this->apiController()->runAction('update', ['id' => $this->template->id]);
            $this->assertSame(422, \Yii::$app->response->statusCode);
            $this->assertIsArray($answer);
        } finally {
            Event::off(NotificationTemplate::class, ActiveRecord::EVENT_BEFORE_UPDATE, $veto);
        }

        $this->assertSame((int)$this->admin->id, (int)$this->reload()->delivers_as);
        $this->assertNull($this->updatedAudit());
    }

    /**
     * The stored configs that the edit form posts back in another shape
     * when nothing but the name changes, each with what the form's script
     * (views/notification-template/form.php) posts for it: the template
     * page shows a stored {} as [], fill.webhook writes "[]" into the
     * headers field, webhookHeaders() reads it as {} and build.webhook
     * leaves out headers without a name; fill.telegram shows a number as
     * its text, which build.telegram posts.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function formShapes(): array
    {
        $shown = SurveySecrets::PLACEHOLDER;

        return [
            // The edit form of 2.14 wrote "headers": {} into every webhook template it saved.
            'a webhook template that the form of 2.14 saved' => [
                NotificationTemplate::CHANNEL_WEBHOOK,
                '{"url":"https://hooks.example.com/a","headers":{}}',
                '{"url":"' . $shown . '"}',
            ],
            'a telegram template whose chat the API stored as a number' => [
                NotificationTemplate::CHANNEL_TELEGRAM,
                '{"bot_token":"123456789:ABCdefGHI","chat_id":-1001234567890}',
                '{"bot_token":"' . $shown . '","chat_id":"-1001234567890"}',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function formShapesByEditor(): array
    {
        $cases = [];
        foreach (self::formShapes() as $what => [$channel, $stored, $posted]) {
            $cases[$what . ', renamed by an admin'] = [$channel, $stored, $posted, true];
            $cases[$what . ', renamed by an operator'] = [$channel, $stored, $posted, false];
        }

        return $cases;
    }

    /**
     * Regression (review of the workflow notifications, access finding 1):
     * a rename in the edit form made the saver the user a template delivers
     * as, because the form posts the same destination in another shape for
     * a webhook template that the form of 2.14 saved and for a Telegram chat
     * stored as a number. An admin's rename turned an operator's template
     * into a feed of every team's events; an operator's rename of an
     * admin's template narrowed it to the operator's teams. The post here is
     * exactly what the form's script posts when only the name changes; it
     * keeps the user the template delivers as, and the audit entry records
     * the rename only.
     */
    #[DataProvider('formShapesByEditor')]
    public function testRegressionARenameInTheFormKeepsTheIdentityOfATemplateItPostsInAnotherShape(string $channel, string $stored, string $posted, bool $byAdmin): void
    {
        [$owner, $editor] = $byAdmin ? [$this->operator, $this->admin] : [$this->admin, $this->operator];
        $template = $this->templateOf($owner, $channel, $stored);
        $this->loginAs($editor);
        $this->setPost($this->formPost($template, 'renamed in the form', $posted));

        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate((int)$template->id));

        $saved = $this->reloadTemplate($template);
        $this->assertSame('renamed in the form', $saved->name);
        $this->assertSame((int)$owner->id, (int)$saved->delivers_as, 'the user it delivers as stays');
        $secret = $channel === NotificationTemplate::CHANNEL_WEBHOOK ? 'url' : 'bot_token';
        $this->assertSame(json_decode($stored, true)[$secret], $saved->getParsedConfig()[$secret] ?? null, 'the secret sent back as the placeholder is kept');
        $this->assertSame(['name' => 'renamed in the form'], $this->updateAuditOf($template, $editor));
    }

    /**
     * The same through the API: a PUT that sends the config back as GET
     * answered it, with the secrets as the placeholder and the headers {}
     * as [] (a JSON client reads either as an empty array), keeps the user
     * the template delivers as.
     */
    #[DataProvider('formShapes')]
    public function testARenameThroughTheApiWithTheConfigAsGetAnsweredItKeepsTheIdentity(string $channel, string $stored): void
    {
        $template = $this->templateOf($this->operator, $channel, $stored);
        $this->authenticate($this->admin);
        $answer = $this->apiController()->runAction('view', ['id' => $template->id]);
        $this->assertIsArray($answer);
        $config = json_decode((string)json_encode($answer['data']['config'] ?? null), true);
        $this->assertIsArray($config);
        $this->setBody(['name' => 'renamed by the api', 'config' => $config]);

        $this->apiController()->runAction('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $saved = $this->reloadTemplate($template);
        $this->assertSame('renamed by the api', $saved->name);
        $this->assertSame((int)$this->operator->id, (int)$saved->delivers_as);
        $this->assertSame(['name' => 'renamed by the api'], $this->updateAuditOf($template, $this->admin));
    }

    public function testTheFormCreatesATemplateThatDeliversAsItsCreator(): void
    {
        $this->loginAs($this->operator);
        $name = 'nt-identity-form-' . uniqid('', true);
        $this->setPost(['NotificationTemplate' => [
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["ops@example.com"]}',
            'events' => 'job.failed',
            'delivers_as' => (string)$this->admin->id,
        ]]);

        $this->assertInstanceOf(Response::class, $this->webController()->actionCreate());

        $created = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $created);
        $this->assertSame((int)$this->operator->id, (int)$created->delivers_as);
        $this->assertSame((int)$this->operator->id, (int)$created->created_by);
    }

    public function testTheApiCreatesATemplateThatDeliversAsTheCaller(): void
    {
        $this->authenticate($this->operator);
        $name = 'nt-identity-api-' . uniqid('', true);
        $this->setBody([
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => ['emails' => ['ops@example.com']],
            'events' => 'job.failed',
            'delivers_as' => (int)$this->admin->id,
        ]);

        $this->apiController()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $created = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $created);
        $this->assertSame((int)$this->operator->id, (int)$created->delivers_as);
    }

    /**
     * The form's fields of the template, with $changes.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function formFields(array $changes): array
    {
        return $changes + [
            'name' => $this->template->name,
            'description' => '',
            'channel' => $this->template->channel,
            'config' => '{"webhook_url":"' . SurveySecrets::PLACEHOLDER . '"}',
            'events' => $this->template->events,
            'subject_template' => (string)$this->template->subject_template,
            'body_template' => (string)$this->template->body_template,
        ];
    }

    /**
     * A template on $channel with $config, as stored, that delivers as $owner, its creator.
     */
    private function templateOf(User $owner, string $channel, string $config): NotificationTemplate
    {
        return $this->reloadTemplate($this->createNotificationTemplate((int)$owner->id, $channel, 'job.failed,workflow.failed', $config));
    }

    /**
     * What the edit form posts for $template when its name is changed to
     * $name and nothing else: every field as the form shows it, the config
     * as its script builds it ($config), the events as its checked boxes
     * name them, and '' for a workflow filter without a selection (the
     * multi-select's hidden field).
     *
     * @return array<string, mixed>
     */
    private function formPost(NotificationTemplate $template, string $name, string $config): array
    {
        return [
            'NotificationTemplate' => [
                'name' => $name,
                'description' => (string)$template->description,
                'channel' => (string)$template->channel,
                'config' => $config,
                'events' => implode(',', $template->getEventList()),
                'workflow_scope' => (string)$template->workflow_scope,
                'level' => (string)$template->level,
                'subject_template' => (string)$template->subject_template,
                'body_template' => (string)$template->body_template,
            ],
            NotificationTemplateFormPost::FILTER_FIELD => '',
        ];
    }

    /**
     * The context of the notification-template.updated audit entry of
     * $template, which $editor wrote; it fails without one.
     *
     * @return array<string, mixed>
     */
    private function updateAuditOf(NotificationTemplate $template, User $editor): array
    {
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, 'object_type' => 'notification_template', 'object_id' => $template->id])
            ->all();
        $this->assertCount(1, $entries, 'one entry for the update');
        $this->assertSame((int)$editor->id, (int)$entries[0]->user_id);
        $context = json_decode((string)$entries[0]->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    private function reloadTemplate(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    /**
     * The context of the template's notification-template.updated audit
     * entry; null without one.
     *
     * @return array<string, mixed>|null
     */
    private function updatedAudit(): ?array
    {
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, 'object_type' => 'notification_template', 'object_id' => $this->template->id])
            ->all();
        $this->assertLessThanOrEqual(1, count($entries), 'one entry per update');
        if ($entries === []) {
            return null;
        }
        $this->assertSame((int)$this->operator->id, (int)$entries[0]->user_id);
        $context = json_decode((string)$entries[0]->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    private function reload(): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($this->template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-identity-token');
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
