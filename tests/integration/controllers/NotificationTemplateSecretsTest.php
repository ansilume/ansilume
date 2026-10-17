<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\controllers\NotificationTemplateController;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\RecordingChannel;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\rbac\BaseManager;
use yii\web\Response;

/**
 * The secrets of a notification template's channel config on its page and
 * in its edit form: telegram bot_token; slack and teams webhook_url; webhook
 * url and every header value; pagerduty routing_key
 * (NotificationTemplate::SECRET_CONFIG_KEYS and SECRET_CONFIG_MAPS).
 *
 * regression: every signed-in user holds the default role viewer, which
 * holds notification-template.view, and the page printed the stored config
 * as it was: a viewer, or any user without a role, read the bot tokens,
 * webhook URLs, header tokens and routing keys of every template. Now the
 * page and the edit form show SurveySecrets::PLACEHOLDER for every secret,
 * and submitting the form unchanged keeps the stored values.
 */
class NotificationTemplateSecretsTest extends WebControllerTestCase
{
    use NotificationSecretFixtures;
    use RendersRealViews;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** @var string[] */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Tests replace it; tearDown() puts back only what setUp() kept.
        $this->keepComponent('notificationDispatcher');
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $this->savedDefaultRoles = $auth->getDefaultRoles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $auth->setDefaultRoles($this->savedDefaultRoles);
        parent::tearDown();
    }

    // -- The page ---------------------------------------------------------------

    /**
     * regression: a viewer read every channel secret on the page.
     *
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     * @param list<string> $secrets
     * @param array<string, mixed> $shown
     */
    public function testRegressionAViewerReadTheChannelSecretsOnThePage(string $channel, array $config, array $secrets, array $shown): void
    {
        $template = $this->secretTemplate($channel, $config);
        $this->loginAs($this->createUserWithRole('nt-secret-viewer', 'viewer'));

        $html = $this->openPage($template);

        $this->assertNoSecretIn($html, $secrets);
        $this->assertStringContainsString(Html::encode((string)json_encode($shown, JSON_UNESCAPED_SLASHES)), $html);
    }

    /**
     * regression: a signed-in user without any role holds the default role
     * viewer (config/web.php) and read the secrets as well.
     */
    public function testRegressionAUserWithoutARoleReadTheChannelSecretsOnThePage(): void
    {
        $this->useTheDefaultRoleOfTheWebApplication();
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_TELEGRAM, ChannelSecretFixtures::TELEGRAM);
        $user = $this->createUser('nt-secret-roleless');
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $this->assertSame([], $auth->getAssignments((string)$user->id), 'precondition: the user holds no role');
        $this->loginAs($user);

        $html = $this->openPage($template);

        $this->assertNoSecretIn($html, [ChannelSecretFixtures::BOT_TOKEN]);
        $this->assertStringContainsString('&quot;bot_token&quot;:&quot;$encrypted$&quot;', $html);
        $this->assertStringContainsString('&quot;chat_id&quot;:&quot;' . ChannelSecretFixtures::CHAT_ID . '&quot;', $html, 'what is no secret is shown');
    }

    public function testThePageNamesThePlaceholderOfTheSecrets(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->loginAs($this->createUserWithRole('nt-secret-page', 'viewer'));

        $html = $this->openPage($template);

        $this->assertStringContainsString(
            '&quot;headers&quot;:{&quot;Authorization&quot;:&quot;$encrypted$&quot;,&quot;X-Env&quot;:&quot;$encrypted$&quot;}',
            $html,
            'the header names are shown, their values are not'
        );
        $this->assertStringContainsString(sprintf(NotificationConfigSecrets::PAGE_NOTE, '<code>$encrypted$</code>'), $html);
    }

    /**
     * A template without a secret (email) says nothing about secrets.
     */
    public function testThePageOfATemplateWithoutASecretHasNoNote(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_EMAIL, ['emails' => ['ops@example.com']]);
        $this->loginAs($this->createUserWithRole('nt-secret-email', 'viewer'));

        $html = $this->openPage($template);

        $this->assertStringContainsString('{&quot;emails&quot;:[&quot;ops@example.com&quot;]}', $html);
        $this->assertStringNotContainsString(SurveySecrets::PLACEHOLDER, $html);
    }

    // -- The edit form ----------------------------------------------------------

    /**
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     * @param list<string> $secrets
     * @param array<string, mixed> $shown
     */
    public function testTheEditFormShowsThePlaceholder(string $channel, array $config, array $secrets, array $shown): void
    {
        $template = $this->secretTemplate($channel, $config);
        $this->loginAs($this->createUserWithRole('nt-secret-form', 'operator'));

        $html = $this->openForm($template);

        $this->assertNoSecretIn($html, $secrets);
        $this->assertSame($shown, json_decode($this->shownConfig($html), true));
        $this->assertStringContainsString(
            Html::encode(sprintf(NotificationConfigSecrets::FORM_HINT, SurveySecrets::PLACEHOLDER)),
            str_replace(['<code>', '</code>'], '', $html)
        );
    }

    /**
     * The round trip: the form submitted as shown keeps every stored secret.
     *
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     */
    public function testSubmittingTheEditFormUnchangedKeepsTheSecrets(string $channel, array $config): void
    {
        $template = $this->secretTemplate($channel, $config);
        $this->loginAs($this->createUserWithRole('nt-secret-keep', 'operator'));
        $shown = $this->shownConfig($this->openForm($template));

        $this->setPost(['NotificationTemplate' => $this->formData($template, 'kept', $shown)]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$template->id));

        $stored = $this->reload($template);
        $this->assertSame($config, $stored->getParsedConfig());
        $this->assertSame($template->name . '-kept', $stored->name);
    }

    public function testANewValueReplacesTheSecret(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_TELEGRAM, ChannelSecretFixtures::TELEGRAM);
        $this->loginAs($this->createUserWithRole('nt-secret-replace', 'operator'));

        $this->setPost(['NotificationTemplate' => $this->formData($template, 'rotated', (string)json_encode([
            'bot_token' => ChannelSecretFixtures::NEW_BOT_TOKEN,
            'chat_id' => ChannelSecretFixtures::CHAT_ID,
        ]))]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$template->id));

        $this->assertSame(['bot_token' => ChannelSecretFixtures::NEW_BOT_TOKEN, 'chat_id' => ChannelSecretFixtures::CHAT_ID], $this->reload($template)->getParsedConfig());
    }

    /**
     * Header values keep their stored values beside a header that is new.
     */
    public function testANewHeaderIsAddedBesideTheKeptOnes(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->loginAs($this->createUserWithRole('nt-secret-header', 'operator'));
        $shown = json_decode($this->shownConfig($this->openForm($template)), true);
        $this->assertIsArray($shown);
        $this->assertIsArray($shown['headers']);
        $shown['headers']['X-New'] = 'added-value';

        $this->setPost(['NotificationTemplate' => $this->formData($template, 'header', (string)json_encode($shown))]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$template->id));

        $this->assertSame(
            ['url' => ChannelSecretFixtures::WEBHOOK_URL, 'headers' => ['Authorization' => ChannelSecretFixtures::HEADER_TOKEN, 'X-Env' => ChannelSecretFixtures::HEADER_ENV, 'X-New' => 'added-value']],
            $this->reload($template)->getParsedConfig()
        );
    }

    public function testThePlaceholderWithoutAStoredValueIsAFormErrorOnCreate(): void
    {
        $this->loginAs($this->createUserWithRole('nt-secret-create', 'operator'));
        $name = 'nt-secret-create-' . uniqid('', true);
        $this->setPost(['NotificationTemplate' => [
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_TELEGRAM,
            'config' => '{"bot_token":"$encrypted$","chat_id":"42"}',
            'events' => 'job.failed',
        ]]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(NotificationTemplate::class, $model);
        $this->assertSame(['config' => ['bot_token has no stored value; enter the real value.']], $model->getErrors());
        $this->assertSame(sprintf(NotificationConfigSecrets::NOT_STORED, 'bot_token'), $model->getFirstError('config'));
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
    }

    /**
     * A submission that fails validation shows what was submitted, the typed
     * secret included, never a stored secret: submitting the corrected form
     * stores the typed value, not the older one.
     */
    public function testAFailedValidationShowsTheSubmissionAndStoresNothing(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->loginAs($this->createUserWithRole('nt-secret-invalid', 'operator'));
        $submitted = (string)json_encode([
            'url' => SurveySecrets::PLACEHOLDER,
            'headers' => ['Authorization' => 'Bearer typed-new-token', 'X-Env' => SurveySecrets::PLACEHOLDER],
        ]);
        $data = $this->formData($template, 'invalid', $submitted);
        $data['events'] = '';
        $this->setPost(['NotificationTemplate' => $data]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(NotificationTemplate::class, $model);
        $this->assertArrayHasKey('events', $model->getErrors());
        $this->assertSame($submitted, $ctrl->capturedParams['config']);
        $html = $this->renderForm($model, (string)$ctrl->capturedParams['config']);
        $this->assertNoSecretIn($html, [ChannelSecretFixtures::WEBHOOK_URL, ChannelSecretFixtures::HEADER_TOKEN, ChannelSecretFixtures::HEADER_ENV]);
        $this->assertStringContainsString('typed-new-token', $html);
        $this->assertSame(ChannelSecretFixtures::WEBHOOK, $this->reload($template)->getParsedConfig());
    }

    /**
     * A form post without the config (forged) shows the stored config as the
     * page does: with the placeholder.
     */
    public function testAPostWithoutTheConfigShowsTheStoredConfigRedacted(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_PAGERDUTY, ChannelSecretFixtures::PAGERDUTY);
        $this->loginAs($this->createUserWithRole('nt-secret-forged', 'operator'));
        $this->setPost(['NotificationTemplate' => ['name' => '']]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $this->assertSame('{"routing_key":"$encrypted$"}', $ctrl->capturedParams['config']);
        $this->assertSame(ChannelSecretFixtures::PAGERDUTY, $this->reload($template)->getParsedConfig());
    }

    /**
     * Stored secrets do not follow a template to another channel.
     */
    public function testAfterAChannelChangeThePlaceholderIsAFormError(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_SLACK, ChannelSecretFixtures::SLACK);
        $this->loginAs($this->createUserWithRole('nt-secret-channel', 'operator'));
        $data = $this->formData($template, 'teams', '{"webhook_url":"$encrypted$"}');
        $data['channel'] = NotificationTemplate::CHANNEL_TEAMS;
        $this->setPost(['NotificationTemplate' => $data]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(NotificationTemplate::class, $model);
        $this->assertSame(['config' => ['webhook_url: the channel changed; enter the real value.']], $model->getErrors());
        $stored = $this->reload($template);
        $this->assertSame(NotificationTemplate::CHANNEL_SLACK, $stored->channel);
        $this->assertSame(ChannelSecretFixtures::SLACK, $stored->getParsedConfig());
    }

    /**
     * Header values do not follow the webhook to another URL: otherwise
     * whoever may edit the template but not read it could send its stored
     * Authorization header to a server of their own.
     */
    public function testAfterAURLChangeAHeaderPlaceholderIsAFormError(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->loginAs($this->createUserWithRole('nt-secret-url', 'operator'));
        $this->setPost(['NotificationTemplate' => $this->formData($template, 'moved', (string)json_encode([
            'url' => 'https://collector.example.net/steal',
            'headers' => ['Authorization' => SurveySecrets::PLACEHOLDER],
        ]))]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(NotificationTemplate::class, $model);
        $this->assertSame(['config' => ['headers.Authorization: url changed; enter the real value.']], $model->getErrors());
        $this->assertSame(ChannelSecretFixtures::WEBHOOK, $this->reload($template)->getParsedConfig());
    }

    /**
     * After a round trip through the form, a test notification still goes
     * out with the stored secret.
     */
    public function testATestNotificationAfterTheRoundTripUsesTheStoredSecret(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_TELEGRAM, ChannelSecretFixtures::TELEGRAM);
        $this->loginAs($this->createUserWithRole('nt-secret-send', 'operator'));
        $this->setPost(['NotificationTemplate' => $this->formData($template, 'sent', $this->shownConfig($this->openForm($template)))]);
        $this->makeController()->actionUpdate((int)$template->id);
        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_TELEGRAM, $channel);
        \Yii::$app->set('notificationDispatcher', $dispatcher);
        $this->setPost([]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionTest((int)$template->id));

        $this->assertSame(['success' => NotificationTemplateController::TEST_SENT], \Yii::$app->session->getAllFlashes());
        $this->assertCount(1, $channel->calls);
        $this->assertSame(ChannelSecretFixtures::TELEGRAM, $channel->calls[0]['template']->getParsedConfig());
        $failed = AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $template->id])->count();
        $this->assertSame(0, (int)$failed);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The page of $template, as the signed-in user opens it: through the
     * controller's access rules, with the real view.
     */
    private function openPage(NotificationTemplate $template): string
    {
        $ctrl = $this->renderingController();
        $result = $this->withRealViews(
            $ctrl,
            '/notification-template/view?id=' . $template->id,
            static fn (): mixed => $ctrl->runAction('view', ['id' => $template->id])
        );
        $this->assertIsString($result);

        return $result;
    }

    /** The edit form of $template, through the controller's access rules, with the real view. */
    private function openForm(NotificationTemplate $template): string
    {
        $ctrl = $this->renderingController();
        $result = $this->withRealViews(
            $ctrl,
            '/notification-template/update?id=' . $template->id,
            static fn (): mixed => $ctrl->runAction('update', ['id' => $template->id])
        );
        $this->assertIsString($result);

        return $result;
    }

    private function renderForm(NotificationTemplate $model, string $config): string
    {
        $ctrl = $this->renderingController();

        return $this->withRealViews(
            $ctrl,
            '/notification-template/update?id=' . $model->id,
            static fn (): string => $ctrl->renderPartial('form', ['model' => $model, 'config' => $config])
        );
    }

    /** The config the edit form holds in its hidden input, which its script reads and writes. */
    private function shownConfig(string $html): string
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $input = (new \DOMXPath($document))->query('//input[@id="nt-config"]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $input, 'the hidden config input');

        return $input->getAttribute('value');
    }

    /**
     * @return array<string, string>
     */
    private function formData(NotificationTemplate $template, string $suffix, string $config): array
    {
        return [
            'name' => $template->name . '-' . $suffix,
            'description' => '',
            'channel' => $template->channel,
            'config' => $config,
            'events' => $template->events,
            'subject_template' => (string)$template->subject_template,
            'body_template' => (string)$template->body_template,
        ];
    }

    private function useTheDefaultRoleOfTheWebApplication(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $auth->setDefaultRoles(['viewer']);
    }

    private function renderingController(): NotificationTemplateController
    {
        return new class ('notification-template', \Yii::$app) extends NotificationTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }

    private function makeController(): NotificationTemplateController
    {
        return new class ('notification-template', \Yii::$app) extends NotificationTemplateController {
            use CapturesParams;
        };
    }
}
