<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\ApiBodyValues;
use app\components\SurveySecrets;
use app\controllers\NotificationTemplateController;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateFormView;
use app\services\notification\NotificationTemplatePage;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\WebhookConfig;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\ConfiguredTestChannel;
use app\tests\integration\TeamScopeFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\Response;

/**
 * The web form of a notification template, rendered with its real view and
 * posted through the controller's access and verb filters (spec section 7,
 * answers 4 and 5): the Mattermost panel, the webhook's signing secret and
 * payload, the workflow filter with the workflows its editor may see (the
 * others kept and counted, never listed), the level, the hints, and the
 * JSON editor of a channel of the installation's own. The form takes the
 * fields the API takes, with the same rules and messages.
 */
class NotificationTemplateFormTest extends WebControllerTestCase
{
    use NotificationSecretFixtures;
    use RendersRealViews;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** A channel of one's own, as ChannelRegistryTest configures it. */
    private const ACME = [
        'label' => 'Acme Pager',
        'class' => ConfiguredTestChannel::class,
        'requiredKeys' => ['endpoint', 'api_key'],
        'secretKeys' => ['api_key'],
        'boundTo' => ['api_key' => 'endpoint'],
        'urlSchemes' => ['endpoint' => ['https']],
    ];

    private const ACME_KEY = 'acme-form-api-key-secret-5512';

    private const ACME_CONFIG = ['endpoint' => 'https://pager.example.com/alerts', 'api_key' => self::ACME_KEY];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keepComponent('notificationDispatcher');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- The channel panels -------------------------------------------------------

    /**
     * The Mattermost panel: the server URL, the bot token, the channel by
     * the names of its team and itself or by its ID, and a mention, each
     * filled by the form's script from the config the form holds, whose bot
     * token is the placeholder.
     */
    public function testTheMattermostPanelHasItsFields(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_MATTERMOST, ChannelSecretFixtures::MATTERMOST);
        $this->loginAs($this->createUserWithRole('ntform-mm', 'operator'));

        $html = $this->open('update', ['id' => $template->id]);
        $xpath = $this->xpath($html);

        foreach (['mattermost-server-url', 'mattermost-bot-token', 'mattermost-team', 'mattermost-channel', 'mattermost-channel-id', 'mattermost-mention'] as $id) {
            $this->assertSame(1, $this->nodeCount($xpath, "//div[@id='config-mattermost']//input[@id='{$id}']"), $id);
        }
        $this->assertSame(['name', 'id'], $this->values($xpath, "//select[@id='mattermost-channel-by']/option"));
        $this->assertStringContainsString(NotificationTemplateFormView::MATTERMOST_HINT, $this->textOf($xpath, "//div[@id='config-mattermost']"));
        $this->assertStringContainsString(NotificationTemplateFormView::MENTION_HINT, $this->textOf($xpath, "//div[@id='config-mattermost']"));
        $this->assertSame(
            ['server_url' => ChannelSecretFixtures::MATTERMOST_SERVER, 'bot_token' => SurveySecrets::PLACEHOLDER, 'team' => 'ops', 'channel' => 'deploys', 'mention' => '@ops-team'],
            json_decode($this->hiddenConfig($xpath), true)
        );
        $this->assertNoSecretIn($html, [ChannelSecretFixtures::MATTERMOST_TOKEN]);
    }

    /**
     * Regression: the webhook panel offered neither the signing secret nor
     * the payload, and its script wrote the URL and the headers only, so a
     * web save dropped both. The panel has a field for each now, and the
     * script keeps the keys it shows (PANEL_KEYS).
     */
    public function testRegressionTheWebhookPanelHasTheSigningSecretAndThePayload(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::SIGNED_WEBHOOK);
        $this->loginAs($this->createUserWithRole('ntform-webhook', 'operator'));

        $html = $this->open('update', ['id' => $template->id]);
        $xpath = $this->xpath($html);

        $this->assertSame(1, $this->nodeCount($xpath, "//div[@id='config-webhook']//input[@id='webhook-signing-secret']"));
        $this->assertSame(WebhookConfig::PAYLOADS, $this->values($xpath, "//div[@id='config-webhook']//select[@id='webhook-payload']/option"));
        $this->assertSame(array_values(NotificationTemplateFormView::PAYLOAD_LABELS), $this->texts($xpath, "//select[@id='webhook-payload']/option"));
        $this->assertStringContainsString(NotificationTemplateFormView::SIGNING_SECRET_HINT, $this->textOf($xpath, "//div[@id='config-webhook']"));
        $this->assertSame(1, $this->nodeCount($xpath, "//div[@id='config-webhook']//*[@id='webhook-headers-error']"));
        $this->assertSame(
            ['url' => SurveySecrets::PLACEHOLDER, 'headers' => ['Authorization' => SurveySecrets::PLACEHOLDER], 'signing_secret' => SurveySecrets::PLACEHOLDER, 'payload' => 'event'],
            json_decode($this->hiddenConfig($xpath), true)
        );
        $script = $this->script($html);
        $this->assertStringContainsString('"webhook":["url","headers","signing_secret","payload"]', $script, 'the script knows the keys the panel shows');
        $this->assertNoSecretIn($html, [ChannelSecretFixtures::SIGNING_SECRET, ChannelSecretFixtures::HEADER_TOKEN, ChannelSecretFixtures::WEBHOOK_URL]);
    }

    /**
     * Saving the form as the script posts it keeps the stored signing
     * secret, and a new URL needs it again (spec section 6).
     */
    public function testTheSigningSecretKeepsItsValueOnlyWhileTheUrlKeepsItsOwn(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::SIGNED_WEBHOOK);
        $this->loginAs($this->createUserWithRole('ntform-signed', 'operator'));
        $kept = ['url' => SurveySecrets::PLACEHOLDER, 'headers' => ['Authorization' => SurveySecrets::PLACEHOLDER], 'signing_secret' => SurveySecrets::PLACEHOLDER, 'payload' => 'event'];

        $this->assertInstanceOf(Response::class, $this->post('update', ['id' => $template->id], $this->fields($template, ['config' => (string)json_encode($kept)])));
        $this->assertSame(ChannelSecretFixtures::SIGNED_WEBHOOK, $this->reload($template)->getParsedConfig());

        $moved = ['url' => 'https://collector.example.net/hook'] + $kept;
        $html = $this->post('update', ['id' => $template->id], $this->fields($template, ['config' => (string)json_encode(['headers' => []] + $moved)]));
        $this->assertIsString($html);
        $this->assertContains('signing_secret: url changed; enter the real value.', $this->errors($this->xpath($html)));
        $this->assertSame(ChannelSecretFixtures::SIGNED_WEBHOOK, $this->reload($template)->getParsedConfig());
    }

    /**
     * Every error of the config is shown at once, since the fields of a
     * panel all write into it: an http server URL and an @all mention.
     * Nothing is stored.
     */
    public function testEveryErrorOfTheConfigIsShownAtOnce(): void
    {
        $this->loginAs($this->createUserWithRole('ntform-mm-errors', 'operator'));
        $name = 'ntform-mm-errors-' . uniqid('', true);

        $html = $this->post('create', [], [
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_MATTERMOST,
            'config' => (string)json_encode(['server_url' => 'http://chat.example.com', 'bot_token' => 'ntform-token', 'team' => 'ops', 'channel' => 'deploys', 'mention' => '@all']),
            'events' => 'workflow.failed',
        ]);

        $this->assertIsString($html);
        $this->assertSame(
            'server_url must be an https URL. mention may not be @all, @channel or @here, which notify everyone in the channel.',
            $this->textOf($this->xpath($html), "//div[contains(@class, 'field-nt-config')]//*[contains(@class, 'help-block')]")
        );
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function listInAFieldProvider(): array
    {
        $cases = [];
        foreach (NotificationTemplateBody::TEXT_FIELDS as $field) {
            $cases["a list for {$field}"] = [$field, ['x'], sprintf(ApiBodyValues::NOT_A_VALUE, $field)];
            $cases["an object for {$field}"] = [$field, ['a' => 'b'], sprintf(ApiBodyValues::NOT_A_VALUE, $field)];
        }
        $cases['events of another form'] = ['events', [['job.failed']], NotificationTemplateBody::EVENTS_FORM];

        return $cases;
    }

    /**
     * Regression: the web forms take a JSON body too (config/web.php), and a
     * list or an object in a field of the form answered 500 ("Array to
     * string conversion"), where the API refuses it. The form reads its
     * fields as the API reads a body (NotificationTemplateBody): the same
     * refusal, shown above the form, and nothing is written.
     */
    #[DataProvider('listInAFieldProvider')]
    public function testRegressionAListInAFieldOfTheFormWasAServerError(string $field, mixed $value, string $refusal): void
    {
        $user = $this->createUserWithRole('ntform-lists', 'operator');
        $template = $this->createNotificationTemplate((int)$user->id);
        $stored = $this->reload($template)->getAttributes();
        $this->loginAs($user);
        $name = 'ntform-lists-' . uniqid('', true);
        $fields = ['name' => $name, 'channel' => 'email', 'config' => '{"emails":["ops@example.com"]}', 'events' => 'job.failed'];

        foreach (['create' => [], 'update' => ['id' => $template->id]] as $action => $params) {
            $this->setPost(['NotificationTemplate' => [$field => $value] + $fields]);
            $html = $this->runForm($action, $params);
            $this->assertIsString($html, $action);
            $this->assertSame($refusal, $this->textOf($this->xpath($html), "//*[@data-testid='notification-template-refusal']"), $action);
        }
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
        $this->assertSame($stored, $this->reload($template)->getAttributes());
    }

    /**
     * The form takes the events and the config in every form the API takes
     * them: the events as a list of names, the config as an object.
     */
    public function testTheFormTakesTheEventsAndTheConfigAsTheApiDoes(): void
    {
        $user = $this->createUserWithRole('ntform-api-forms', 'operator');
        $this->loginAs($user);
        $name = 'ntform-api-forms-' . uniqid('', true);
        $this->setPost(['NotificationTemplate' => [
            'name' => $name,
            'channel' => 'email',
            'config' => ['emails' => ['ops@example.com']],
            'events' => ['job.failed', 'workflow.waiting'],
        ]]);

        $this->assertInstanceOf(Response::class, $this->runForm('create', []));
        $stored = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $this->assertSame(['job.failed', 'workflow.waiting'], $stored->getEventList());
        $this->assertSame(['emails' => ['ops@example.com']], $stored->getParsedConfig());
    }

    public function testThePagerDutyPanelNamesTheEventsThatNeverRaiseAnIncident(): void
    {
        $this->loginAs($this->createUserWithRole('ntform-pd', 'operator'));

        $xpath = $this->xpath($this->open('create'));

        $this->assertSame(NotificationTemplateFormView::pagerDutyNote(), $this->textOf($xpath, "//div[@id='config-pagerduty']//*[@data-testid='nt-pagerduty-note']"));
    }

    /**
     * The hint of the subject names the variables of the workflow events.
     */
    public function testTheVariablesHintNamesTheVariablesOfWorkflowEvents(): void
    {
        $this->loginAs($this->createUserWithRole('ntform-hint', 'operator'));

        $xpath = $this->xpath($this->open('create'));

        $hint = $this->textOf($xpath, "//div[contains(@class, 'field-notificationtemplate-subject_template')]//*[contains(@class, 'hint-block')]");
        $this->assertSame(NotificationTemplateFormView::VARIABLES_HINT, $hint);
        foreach (['{{ workflow.url }}', '{{ workflow.reason }}', '{{ step.name }}', '{{ approval.url }}', '{{ event_id }}'] as $variable) {
            $this->assertStringContainsString($variable, $hint);
        }
    }

    /**
     * The new workflow events are offered as checkboxes, and saved as
     * checked.
     */
    public function testTheNewWorkflowEventsAreOfferedAndSaved(): void
    {
        $user = $this->createUserWithRole('ntform-events', 'operator');
        $template = $this->createNotificationTemplate((int)$user->id);
        $this->loginAs($user);
        $events = [NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED, NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED, NotificationTemplate::EVENT_WORKFLOW_WAITING];

        $xpath = $this->xpath($this->open('update', ['id' => $template->id]));
        foreach ($events as $event) {
            $this->assertSame(1, $this->nodeCount($xpath, "//input[@id='event-{$event}' and contains(@class, 'event-checkbox')]"), $event);
        }

        $this->post('update', ['id' => $template->id], $this->fields($template, ['events' => implode(',', $events)]));
        $this->assertSame($events, $this->reload($template)->getEventList());
        $checked = $this->values($this->xpath($this->open('update', ['id' => $template->id])), "//input[contains(@class, 'event-checkbox') and @checked]");
        $this->assertSame($events, $checked);
    }

    // -- The workflow filter and the level ------------------------------------------

    /**
     * Spec answer 5: the form offers the workflows its editor may see; of
     * the filter, those are selected, and the others, of another team or
     * deleted, are counted and never listed.
     */
    public function testTheFilterOffersTheWorkflowsTheEditorMaySeeAndCountsTheOthers(): void
    {
        $world = $this->world();
        $template = $this->filtered($world, [$world['own'], $world['foreign']]);
        $this->loginAs($world['member']);

        $html = $this->open('update', ['id' => $template->id]);
        $xpath = $this->xpath($html);

        $offered = $this->values($xpath, "//select[@id='nt-workflow-templates']/option");
        $this->assertContains((string)$world['own']->id, $offered);
        $this->assertContains((string)$world['open']->id, $offered);
        $this->assertNotContains((string)$world['foreign']->id, $offered);
        $this->assertStringNotContainsString((string)$world['foreign']->name, $html, 'the name of a hidden workflow is not shown');
        $this->assertSame([(string)$world['own']->id], $this->values($xpath, "//select[@id='nt-workflow-templates']/option[@selected]"));
        $this->assertSame('workflow_template_ids[]', $this->attributeOf($xpath, "//select[@id='nt-workflow-templates']", 'name'));
        $this->assertSame('multiple', $this->attributeOf($xpath, "//select[@id='nt-workflow-templates']", 'multiple'));
        $this->assertSame(1, $this->nodeCount($xpath, "//input[@type='hidden' and @name='workflow_template_ids' and @value='']"), 'an empty selection posts the filter too');
        $this->assertSame(sprintf(NotificationTemplateFormView::HIDDEN_WORKFLOWS, 1), $this->textOf($xpath, "//*[@data-testid='nt-workflow-templates-hidden']"));
        $this->assertStringContainsString(NotificationTemplateFormView::MULTI_SELECT_HINT, $this->textOf($xpath, "//div[@id='nt-workflow-templates-box']"));
        $this->assertSame(['all', 'selected'], $this->values($xpath, "//input[@name='NotificationTemplate[workflow_scope]' and @type='radio']"));
        $this->assertSame(['selected'], $this->values($xpath, "//input[@name='NotificationTemplate[workflow_scope]' and @checked]"));
        $this->assertSame(array_values(NotificationTemplate::WORKFLOW_SCOPE_LABELS), $this->texts($xpath, "//div[@id='nt-workflow-scope']//label"));
        $this->assertSame(['all', 'essential'], $this->values($xpath, "//input[@name='NotificationTemplate[level]' and @type='radio']"));
        $this->assertSame(array_values(NotificationTemplate::LEVEL_LABELS), $this->texts($xpath, "//div[@id='nt-level']//label"));
        $this->assertStringContainsString(NotificationTemplateFormView::SCOPE_HINT, $html);
        $this->assertStringContainsString(NotificationTemplateFormView::LEVEL_HINT, str_replace('&quot;', '"', $html));
    }

    /**
     * An editor who may see no workflow template (without workflow.view)
     * is told so, and offered none; the filter they cannot see stays
     * counted.
     */
    public function testAnEditorWhoMaySeeNoWorkflowIsToldSo(): void
    {
        $world = $this->world();
        $template = $this->filtered($world, [$world['own']]);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('ntform-no-workflows-' . uniqid());
        $auth->add($role);
        $update = $auth->getPermission('notification-template.update');
        $this->assertNotNull($update);
        $auth->addChild($role, $update);
        $editor = $this->createUser('ntform-no-workflows');
        $auth->assign($role, (string)$editor->id);
        $this->loginAs($editor);

        $xpath = $this->xpath($this->open('update', ['id' => $template->id]));

        $this->assertSame([], $this->values($xpath, "//select[@id='nt-workflow-templates']/option"));
        $box = $this->textOf($xpath, "//div[@id='nt-workflow-templates-box']");
        $this->assertStringContainsString(NotificationTemplateFormView::NO_WORKFLOWS, $box);
        $this->assertStringNotContainsString(NotificationTemplateFormView::MULTI_SELECT_HINT, $box);
        $this->assertSame(sprintf(NotificationTemplateFormView::HIDDEN_WORKFLOWS, 1), $this->textOf($xpath, "//*[@data-testid='nt-workflow-templates-hidden']"));
    }

    /**
     * The form posts the part of the filter its editor may see; the others
     * stay. The hidden field alone empties that part.
     */
    public function testSavingTheFormKeepsTheWorkflowsTheEditorCannotSee(): void
    {
        $world = $this->world();
        $template = $this->filtered($world, [$world['own'], $world['foreign']]);
        $this->loginAs($world['member']);

        $this->assertInstanceOf(Response::class, $this->post('update', ['id' => $template->id], $this->fields($template), ['workflow_template_ids' => [(string)$world['open']->id]]));
        $stored = $this->reload($template);
        $this->assertSame($this->sorted([(int)$world['open']->id, (int)$world['foreign']->id]), $stored->getWorkflowTemplateIds());
        $this->assertSame((int)$world['member']->id, (int)$stored->delivers_as, 'a change of the filter makes the editor the user it delivers as');

        $this->post('update', ['id' => $template->id], $this->fields($stored), ['workflow_template_ids' => '']);
        $this->assertSame([(int)$world['foreign']->id], $this->reload($template)->getWorkflowTemplateIds());
    }

    /**
     * A workflow the editor may not see, or an ID of another form, is a
     * form error of the filter, with the API's message; nothing is stored,
     * and the form selects what was posted.
     */
    public function testAWorkflowTheEditorMayNotSetIsAFormError(): void
    {
        $world = $this->world();
        $template = $this->filtered($world, [$world['own']]);
        $this->loginAs($world['member']);
        $before = $this->reload($template)->getAttributes();

        $html = $this->post('update', ['id' => $template->id], $this->fields($template, ['description' => 'refused']), [
            'workflow_template_ids' => [(string)$world['open']->id, (string)$world['foreign']->id],
        ]);
        $this->assertIsString($html);
        $xpath = $this->xpath($html);
        $this->assertSame(sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . $world['foreign']->id), $this->textOf($xpath, "//*[@id='nt-workflow-templates-error']"));
        $this->assertSame([(string)$world['open']->id], $this->values($xpath, "//select[@id='nt-workflow-templates']/option[@selected]"), 'as posted');
        $this->assertSame($before, $this->reload($template)->getAttributes());
        $this->assertSame([(int)$world['own']->id], $this->reload($template)->getWorkflowTemplateIds());

        $malformed = $this->post('update', ['id' => $template->id], $this->fields($template), ['workflow_template_ids' => ['12.5']]);
        $this->assertIsString($malformed);
        $this->assertSame(NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM, $this->textOf($this->xpath($malformed), "//*[@id='nt-workflow-templates-error']"));
        $this->assertSame([(int)$world['own']->id], $this->reload($template)->getWorkflowTemplateIds());
    }

    /**
     * A new template is saved with its filter and level, as its creator
     * sets them, and its creator is the user it delivers as.
     */
    public function testACreateSavesTheFilterAndTheLevel(): void
    {
        $world = $this->world();
        $this->loginAs($world['member']);
        $name = 'ntform-create-' . uniqid('', true);

        $answer = $this->post('create', [], [
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["ops@example.com"]}',
            'events' => 'workflow.failed,workflow.waiting',
            'level' => NotificationTemplate::LEVEL_ESSENTIAL,
            'workflow_scope' => NotificationTemplate::WORKFLOW_SCOPE_SELECTED,
        ], ['workflow_template_ids' => [(string)$world['own']->id]]);

        $this->assertInstanceOf(Response::class, $answer);
        $stored = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $this->assertSame(NotificationTemplate::LEVEL_ESSENTIAL, $stored->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_SELECTED, $stored->workflow_scope);
        $this->assertSame([(int)$world['own']->id], $stored->getWorkflowTemplateIds());
        $this->assertSame((int)$world['member']->id, (int)$stored->delivers_as);
        $this->assertTrue(AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED, 'object_id' => $stored->id])->exists());

        $refusedName = 'ntform-create-refused-' . uniqid('', true);
        $html = $this->post('create', [], [
            'name' => $refusedName,
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'config' => '{"emails":["ops@example.com"]}',
            'events' => 'workflow.failed',
        ], ['workflow_template_ids' => [(string)$world['foreign']->id]]);
        $this->assertIsString($html);
        $xpath = $this->xpath($html);
        $this->assertSame(sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . $world['foreign']->id), $this->textOf($xpath, "//*[@id='nt-workflow-templates-error']"));
        $this->assertSame('Create', $this->textOf($xpath, "//button[@id='nt-submit']"), 'the form is a create again');
        $this->assertNull(NotificationTemplate::findOne(['name' => $refusedName]));
    }

    /**
     * A new template's form selects All workflows and Everything; a level
     * or a scope of another value is a form error with the API's message.
     */
    public function testTheDefaultsAndTheRefusalsOfTheLevelAndTheScope(): void
    {
        $user = $this->createUserWithRole('ntform-level', 'operator');
        $template = $this->createNotificationTemplate((int)$user->id);
        $this->loginAs($user);

        $create = $this->xpath($this->open('create'));
        $this->assertSame(['all'], $this->values($create, "//input[@name='NotificationTemplate[workflow_scope]' and @checked]"));
        $this->assertSame(['all'], $this->values($create, "//input[@name='NotificationTemplate[level]' and @checked]"));

        $html = $this->post('update', ['id' => $template->id], $this->fields($template, ['level' => 'loud', 'workflow_scope' => 'some']));
        $this->assertIsString($html);
        $errors = $this->errors($this->xpath($html));
        $this->assertContains('Level is invalid.', $errors);
        $this->assertContains('Workflow Scope is invalid.', $errors);
        $this->assertSame(NotificationTemplate::LEVEL_ALL, $this->reload($template)->level);
    }

    /**
     * The edit form says whom the template delivers as, and that a save
     * that changes what or where it delivers makes the editor that user;
     * for a user who is inactive, it warns that it delivers nothing.
     */
    public function testTheEditFormSaysWhomTheTemplateDeliversAs(): void
    {
        $owner = $this->createUserWithRole('ntform-owner', 'admin');
        $template = $this->createNotificationTemplate((int)$owner->id);
        $this->loginAs($this->createUserWithRole('ntform-editor', 'operator'));

        $active = $this->xpath($this->open('update', ['id' => $template->id]));
        $this->assertSame(sprintf(NotificationTemplateFormView::DELIVERS_AS_HINT, $owner->username), $this->textOf($active, "//*[@data-testid='notification-template-delivers-as-hint']"));
        $this->assertSame(0, $this->nodeCount($active, "//*[@data-testid='notification-template-inactive-identity']"));

        $owner->status = User::STATUS_INACTIVE;
        $this->assertTrue($owner->save(false));
        $inactive = $this->xpath($this->open('update', ['id' => $template->id]));
        $this->assertSame(sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, $owner->username), $this->textOf($inactive, "//*[@data-testid='notification-template-inactive-identity']"));
        $this->assertSame(0, $this->nodeCount($inactive, "//*[@data-testid='notification-template-delivers-as-hint']"));

        $create = $this->xpath($this->open('create'));
        $this->assertSame(0, $this->nodeCount($create, "//*[@data-testid='notification-template-delivers-as-hint' or @data-testid='notification-template-inactive-identity']"));
    }

    // -- A channel of one's own ------------------------------------------------------

    /**
     * Every built-in channel has a panel of its own; without a channel of
     * one's own the form has no JSON editor.
     */
    public function testTheBuiltInChannelsHaveTheirPanels(): void
    {
        $this->loginAs($this->createUserWithRole('ntform-panels', 'operator'));

        $xpath = $this->xpath($this->open('create'));

        foreach (array_keys(NotificationTemplateFormView::PANEL_KEYS) as $channel) {
            $this->assertSame(1, $this->nodeCount($xpath, "//div[@id='config-{$channel}' and contains(@class, 'channel-config') and not(@data-raw-config)]"), $channel);
        }
        $this->assertSame(0, $this->nodeCount($xpath, '//*[@data-raw-config]'));
    }

    /**
     * A channel of the installation's own (config/notification-channels.php)
     * has no panel: the form edits its config as JSON, with its secrets as
     * the placeholder, and its rules refuse what they refuse for the API.
     */
    public function testAChannelOfOnesOwnIsEditedAsJson(): void
    {
        $this->swapComponent('notificationDispatcher', ['class' => NotificationDispatcher::class, 'channels' => ['acme' => self::ACME]]);
        $user = $this->createUserWithRole('ntform-acme', 'operator');
        $this->loginAs($user);

        $create = $this->xpath($this->open('create'));
        $this->assertContains('acme', $this->values($create, "//select[@id='nt-channel']/option"));
        $this->assertSame(1, $this->nodeCount($create, "//div[@id='config-acme' and @data-raw-config]//textarea[@id='raw-config-acme']"));
        $this->assertSame(
            'The config of Acme Pager as a JSON object. Required: endpoint, api_key. Secrets, shown as $encrypted$: api_key.',
            $this->textOf($create, "//div[@id='config-acme']//div[contains(@class, 'form-text')]")
        );

        $name = 'ntform-acme-' . uniqid('', true);
        $created = $this->post('create', [], ['name' => $name, 'channel' => 'acme', 'config' => (string)json_encode(self::ACME_CONFIG), 'events' => 'job.failed']);
        $this->assertInstanceOf(Response::class, $created);
        $template = NotificationTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $this->assertSame(self::ACME_CONFIG, $template->getParsedConfig());

        $edit = $this->open('update', ['id' => $template->id]);
        $this->assertSame(['endpoint' => self::ACME_CONFIG['endpoint'], 'api_key' => SurveySecrets::PLACEHOLDER], json_decode($this->hiddenConfig($this->xpath($edit)), true));
        $this->assertNoSecretIn($edit, [self::ACME_KEY]);

        $refusals = [
            'invalid JSON' => ['{"endpoint": ', 'Config must be valid JSON.'],
            'a missing key' => ['{"endpoint": "https://pager.example.com/alerts"}', 'api_key is required for Acme Pager.'],
            'an http URL' => ['{"endpoint": "http://pager.example.com/alerts", "api_key": "acme-typed-key"}', 'endpoint must be an https URL.'],
            'a bound secret after a change' => ['{"endpoint": "https://other.example.com/alerts", "api_key": "$encrypted$"}', 'api_key: endpoint changed; enter the real value.'],
        ];
        foreach ($refusals as $case => [$config, $error]) {
            $html = $this->post('update', ['id' => $template->id], $this->fields($template, ['config' => $config]));
            $this->assertIsString($html, $case);
            $this->assertContains($error, $this->errors($this->xpath($html)), $case);
            $this->assertNoSecretIn($html, [self::ACME_KEY]);
            $this->assertSame(self::ACME_CONFIG, $this->reload($template)->getParsedConfig(), $case);
        }

        $kept = $this->post('update', ['id' => $template->id], $this->fields($template, ['config' => '{"endpoint": "https://pager.example.com/alerts", "api_key": "$encrypted$"}', 'description' => 'kept']));
        $this->assertInstanceOf(Response::class, $kept);
        $this->assertSame(self::ACME_CONFIG, $this->reload($template)->getParsedConfig());
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * Teams for the filter: the member's team operates own, another team
     * foreign; open has no team.
     *
     * @return array{admin: User, member: User, own: \app\models\WorkflowTemplate, foreign: \app\models\WorkflowTemplate, open: \app\models\WorkflowTemplate}
     */
    private function world(): array
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;

        return [
            'admin' => $scope['admin'],
            'member' => $scope['member'],
            'own' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id),
            'foreign' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id),
            'open' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id),
        ];
    }

    /**
     * An admin's template, scope selected, whose filter holds $workflows.
     *
     * @param array{admin: User} $world
     * @param list<\app\models\WorkflowTemplate> $workflows
     */
    private function filtered(array $world, array $workflows): NotificationTemplate
    {
        $adminId = (int)$world['admin']->id;
        $template = $this->createNotificationTemplate($adminId, NotificationTemplate::CHANNEL_EMAIL, 'workflow.failed');
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertTrue($template->save(false));
        $this->assertSame([], $template->setWorkflowTemplateIds(array_map(static fn ($w): int => (int)$w->id, $workflows), $adminId));

        return $this->reload($template);
    }

    /**
     * The fields of the form for $template, as its script posts them, with
     * $changes on top.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function fields(NotificationTemplate $template, array $changes = []): array
    {
        return $changes + [
            'name' => (string)$template->name,
            'description' => (string)$template->description,
            'channel' => (string)$template->channel,
            'config' => $template->getRedactedConfigJson(),
            'events' => (string)$template->events,
            'level' => (string)($template->level ?? NotificationTemplate::LEVEL_ALL),
            'workflow_scope' => (string)($template->workflow_scope ?? NotificationTemplate::WORKFLOW_SCOPE_ALL),
            'subject_template' => (string)$template->subject_template,
            'body_template' => (string)$template->body_template,
        ];
    }

    /**
     * Opens $action with $params, as the signed-in user, with the real view.
     *
     * @param array<string, mixed> $params
     */
    private function open(string $action, array $params = []): string
    {
        $this->setPost([]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $result = $this->runForm($action, $params);
        $this->assertIsString($result);

        return $result;
    }

    /**
     * Posts the form's $fields (NotificationTemplate[...]) and $extra to
     * $action: the redirect of a save, else the form shown again.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $fields
     * @param array<string, mixed> $extra
     */
    private function post(string $action, array $params, array $fields, array $extra = []): Response|string
    {
        $this->setPost(['NotificationTemplate' => $fields] + $extra);
        $result = $this->runForm($action, $params);
        $this->assertTrue($result instanceof Response || is_string($result));

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runForm(string $action, array $params): mixed
    {
        $ctrl = new class ('notification-template', $this->app()) extends NotificationTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }

            /**
             * @param string|array<int|string, mixed> $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
        $query = http_build_query($params);

        return $this->withRealViews($ctrl, '/notification-template/' . $action . ($query === '' ? '' : '?' . $query), static fn (): mixed => $ctrl->runAction($action, $params));
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }

    private function nodeCount(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * The value attributes of the elements $query finds.
     *
     * @return list<string>
     */
    private function values(\DOMXPath $xpath, string $query): array
    {
        $values = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $values[] = $node instanceof \DOMElement ? $node->getAttribute('value') : '';
        }

        return $values;
    }

    /**
     * The texts of the elements $query finds, trimmed.
     *
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }

    /** The text of the only element $query finds, its spaces collapsed. */
    private function textOf(\DOMXPath $xpath, string $query): string
    {
        $texts = $this->texts($xpath, $query);
        $this->assertCount(1, $texts, $query);

        return $texts[0];
    }

    private function attributeOf(\DOMXPath $xpath, string $query, string $attribute): string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node, $query);

        return $node->getAttribute($attribute);
    }

    /** The config the form holds in its hidden input, which its script reads and writes. */
    private function hiddenConfig(\DOMXPath $xpath): string
    {
        return $this->attributeOf($xpath, "//input[@id='nt-config']", 'value');
    }

    /**
     * The errors the form shows: those of its fields.
     *
     * @return list<string>
     */
    private function errors(\DOMXPath $xpath): array
    {
        return array_values(array_filter($this->texts($xpath, "//*[contains(@class, 'help-block')]"), static fn (string $text): bool => $text !== ''));
    }

    /** The form's inline script. */
    private function script(string $html): string
    {
        $this->assertSame(1, preg_match('#<script>\s*\(function \(\) \{(.*?)</script>#s', $html, $match));

        return (string)($match[1] ?? '');
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
