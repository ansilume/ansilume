<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\tests\integration\controllers\ChannelSecretFixtures;
use app\tests\integration\controllers\NotificationSecretFixtures;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\RecordingChannel;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\BaseManager;

/**
 * The secrets of a notification template's channel config in the API:
 * GET /api/v1/notification-templates and /{id} answer them as
 * SurveySecrets::PLACEHOLDER, POST and PUT answer the same, and a PUT that
 * sends the placeholder back keeps the stored value.
 *
 * regression: every signed-in user holds the default role viewer, which
 * holds notification-template.view, and the list and the detail answered
 * the stored config as it was: any API token of a viewer, or of a user
 * without a role, read the bot tokens, webhook URLs, header tokens and
 * routing keys of every template.
 */
class NotificationTemplatesSecretsApiTest extends WebControllerTestCase
{
    use NotificationSecretFixtures;
    use TeamScopeFixtures;

    /** @var string[] */
    private array $savedDefaultRoles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $this->savedDefaultRoles = $auth->getDefaultRoles();
    }

    protected function tearDown(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $auth->setDefaultRoles($this->savedDefaultRoles);
        parent::tearDown();
    }

    // -- Reading ----------------------------------------------------------------

    /**
     * regression: a viewer's token read every channel secret in the detail.
     *
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     * @param list<string> $secrets
     * @param array<string, mixed> $shown
     */
    public function testRegressionAViewerReadTheChannelSecretsInTheDetail(string $channel, array $config, array $secrets, array $shown): void
    {
        $template = $this->secretTemplate($channel, $config);
        $this->authenticate($this->createUserWithRole('nt-api-viewer', 'viewer'));

        $result = $this->call('view', ['id' => $template->id]);

        $this->assertNoSecretIn((string)json_encode($result), $secrets);
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame($shown, $data['config']);
        $this->assertSame(200, \Yii::$app->response->statusCode);
    }

    /**
     * regression: one GET of the list answered the secrets of every template.
     */
    public function testRegressionAViewerReadTheChannelSecretsInTheList(): void
    {
        $ids = [];
        foreach (ChannelSecretFixtures::channels() as [$channel, $config]) {
            $ids[] = (int)$this->secretTemplate($channel, $config)->id;
        }
        $this->authenticate($this->createUserWithRole('nt-api-list', 'viewer'));

        $result = $this->call('index');

        $this->assertNoSecretIn((string)json_encode($result), ChannelSecretFixtures::allSecrets());
        $listed = $this->listedConfigs($result, $ids);
        $this->assertCount(count($ids), $listed, 'the templates are on the first page, newest first');
        foreach ($listed as $config) {
            $this->assertContains(SurveySecrets::PLACEHOLDER, array_merge(array_values($config), array_values((array)($config['headers'] ?? []))));
        }
    }

    /**
     * regression: a user without any role holds the default role viewer
     * (config/web.php) and read the secrets with an API token as well.
     */
    public function testRegressionAUserWithoutARoleReadTheChannelSecrets(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(BaseManager::class, $auth);
        $auth->setDefaultRoles(['viewer']);
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_SLACK, ChannelSecretFixtures::SLACK);
        $user = $this->createUser('nt-api-roleless');
        $this->assertSame([], $auth->getAssignments((string)$user->id), 'precondition: the user holds no role');
        $this->authenticate($user);

        $detail = $this->call('view', ['id' => $template->id]);
        $list = $this->call('index');

        $this->assertNoSecretIn((string)json_encode($detail) . (string)json_encode($list), [ChannelSecretFixtures::SLACK_URL]);
        $this->assertSame(['webhook_url' => SurveySecrets::PLACEHOLDER], $this->dataOf($detail)['config']);
    }

    // -- Writing ----------------------------------------------------------------

    /**
     * The round trip: a GET's config sent back with a PUT keeps every stored
     * secret, and the answer shows the placeholder again.
     *
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     * @param list<string> $secrets
     * @param array<string, mixed> $shown
     */
    public function testAGetPutRoundTripKeepsTheSecrets(string $channel, array $config, array $secrets, array $shown): void
    {
        $template = $this->secretTemplate($channel, $config);
        $this->authenticate($this->createUserWithRole('nt-api-operator', 'operator'));
        $got = $this->dataOf($this->call('view', ['id' => $template->id]));
        $this->assertSame($shown, $got['config']);

        $this->setBody(['name' => $template->name . '-renamed', 'config' => $got['config']]);
        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($config, $this->reload($template)->getParsedConfig());
        $this->assertSame($shown, $this->dataOf($answer)['config']);
        $this->assertNoSecretIn((string)json_encode($answer), $secrets);
    }

    /**
     * After a GET→PUT round trip, a test notification still goes out with
     * the stored secrets.
     */
    public function testATestNotificationAfterTheRoundTripUsesTheStoredSecrets(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->authenticate($this->createUserWithRole('nt-api-send', 'operator'));
        $this->setBody(['config' => $this->dataOf($this->call('view', ['id' => $template->id]))['config']]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $original = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        $channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_WEBHOOK, $channel);
        \Yii::$app->set('notificationDispatcher', $dispatcher);
        try {
            $answer = $this->call('test', ['id' => $template->id]);
        } finally {
            \Yii::$app->set('notificationDispatcher', $original);
        }

        $this->assertSame(['data' => ['sent' => true]], $answer);
        $this->assertCount(1, $channel->calls);
        $this->assertSame(ChannelSecretFixtures::WEBHOOK, $channel->calls[0]['template']->getParsedConfig());
    }

    /**
     * A PUT without config leaves the stored config as it is.
     */
    public function testAPutWithoutTheConfigKeepsIt(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $this->authenticate($this->createUserWithRole('nt-api-noconfig', 'operator'));

        $this->setBody(['description' => 'no config sent']);
        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(ChannelSecretFixtures::WEBHOOK, $this->reload($template)->getParsedConfig());
        $this->assertNoSecretIn((string)json_encode($answer), [ChannelSecretFixtures::WEBHOOK_URL, ChannelSecretFixtures::HEADER_TOKEN]);
    }

    public function testANewValueReplacesTheSecret(): void
    {
        $template = $this->secretTemplate(NotificationTemplate::CHANNEL_TELEGRAM, ChannelSecretFixtures::TELEGRAM);
        $this->authenticate($this->createUserWithRole('nt-api-rotate', 'operator'));

        $this->setBody(['config' => ['bot_token' => ChannelSecretFixtures::NEW_BOT_TOKEN, 'chat_id' => ChannelSecretFixtures::CHAT_ID]]);
        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(['bot_token' => ChannelSecretFixtures::NEW_BOT_TOKEN, 'chat_id' => ChannelSecretFixtures::CHAT_ID], $this->reload($template)->getParsedConfig());
        $this->assertNoSecretIn((string)json_encode($answer), [ChannelSecretFixtures::NEW_BOT_TOKEN]);
    }

    public function testCreatingWithThePlaceholderIsRefused(): void
    {
        $this->authenticate($this->createUserWithRole('nt-api-create', 'operator'));
        $name = 'nt-api-create-' . uniqid('', true);

        $this->setBody([
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_PAGERDUTY,
            'events' => 'job.failed',
            'config' => ['routing_key' => SurveySecrets::PLACEHOLDER],
        ]);
        $answer = $this->call('create');

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(NotificationConfigSecrets::NOT_STORED, 'routing_key')]], $answer);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
    }

    public function testACreateAnswersThePlaceholder(): void
    {
        $this->authenticate($this->createUserWithRole('nt-api-created', 'operator'));

        $this->setBody([
            'name' => 'nt-api-created-' . uniqid('', true),
            'channel' => NotificationTemplate::CHANNEL_WEBHOOK,
            'events' => 'job.failed',
            'config' => ChannelSecretFixtures::WEBHOOK,
        ]);
        $answer = $this->call('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $data = $this->dataOf($answer);
        $this->assertSame(['url' => ChannelSecretFixtures::SHOWN, 'headers' => ['Authorization' => ChannelSecretFixtures::SHOWN, 'X-Env' => ChannelSecretFixtures::SHOWN]], $data['config']);
        $this->assertNoSecretIn((string)json_encode($answer), [ChannelSecretFixtures::WEBHOOK_URL, ChannelSecretFixtures::HEADER_TOKEN, ChannelSecretFixtures::HEADER_ENV]);
        $created = NotificationTemplate::findOne((int)$data['id']);
        $this->assertInstanceOf(NotificationTemplate::class, $created);
        $this->assertSame(ChannelSecretFixtures::WEBHOOK, $created->getParsedConfig());
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        /** @var \yii\base\Module $app */
        $app = \Yii::$app;
        $result = (new NotificationTemplatesController('api/v1/notification-templates', $app))->runAction($action, $params);
        $this->assertIsArray($result);
        $named = [];
        foreach ($result as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-secret-token');
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

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function dataOf(array $answer): array
    {
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $named = [];
        foreach ($data as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * The configs the list answered for the templates $ids.
     *
     * @param array<string, mixed> $answer
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function listedConfigs(array $answer, array $ids): array
    {
        $items = $answer['data'] ?? null;
        $this->assertIsArray($items);
        $configs = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            if (in_array($item['id'] ?? null, $ids, true)) {
                $this->assertIsArray($item['config']);
                $configs[] = $item['config'];
            }
        }

        return $configs;
    }
}
