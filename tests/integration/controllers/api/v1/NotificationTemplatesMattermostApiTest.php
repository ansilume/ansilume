<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\MattermostApiException;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\FakeMattermost;
use app\tests\integration\TeamScopeFixtures;

/**
 * Mattermost templates through /api/v1/notification-templates: saved
 * without a request to Mattermost, with an https server only, the bot token
 * answered as $encrypted$ and kept by a PUT only while server_url keeps its
 * stored value; their test (POST .../test) checks the token and the channel
 * first and answers 502 with what is wrong.
 */
class NotificationTemplatesMattermostApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RestoresComponents;

    private const CONFIG = ['server_url' => FakeMattermost::SERVER, 'bot_token' => FakeMattermost::BOT_TOKEN, 'team' => FakeMattermost::TEAM, 'channel' => FakeMattermost::CHANNEL];

    private FakeMattermost $mattermost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mattermost = new FakeMattermost();
        $this->swapComponent('outboundHttp', $this->mattermost);
        $this->swapComponent('notificationDispatcher', new NotificationDispatcher());
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * Saving checks the form only (spec answer 4): a Mattermost template is
     * created without a request to Mattermost, so an outage never blocks
     * it, and its token is answered as $encrypted$.
     */
    public function testSavingNeedsNoServer(): void
    {
        $this->authenticate($this->createUserWithRole('nt-mm-api-create', 'operator'));
        $this->setBody([
            'name' => 'nt-mm-api-' . uniqid('', true),
            'channel' => NotificationTemplate::CHANNEL_MATTERMOST,
            'events' => 'workflow.failed',
            'config' => self::CONFIG + ['mention' => '@ops-team'],
        ]);

        $answer = $this->call('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame([], $this->mattermost->requests);
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(array_merge(self::CONFIG, ['bot_token' => '$encrypted$', 'mention' => '@ops-team']), $data['config'] ?? null);
        $this->assertStringNotContainsString(FakeMattermost::BOT_TOKEN, (string)json_encode($answer));
    }

    /**
     * The bot token is a full session of the bot: server_url must be https,
     * and nothing is written otherwise.
     */
    public function testHttpServerUrlIsRefused(): void
    {
        $this->authenticate($this->createUserWithRole('nt-mm-api-http', 'operator'));
        $name = 'nt-mm-api-' . uniqid('', true);
        $this->setBody([
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_MATTERMOST,
            'events' => 'workflow.failed',
            'config' => ['server_url' => 'http://chat.example.com'] + self::CONFIG,
        ]);

        $answer = $this->call('create');

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'server_url must be an https URL.']], $answer);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
        $this->assertSame([], $this->mattermost->requests);
    }

    /**
     * Regression (review focus 3): an operator who may edit an admin's
     * template but not read its token changes server_url and keeps the
     * token's placeholder: the PUT is refused, nothing is written, and a
     * test afterwards sends the stored token to the stored server only. With
     * the new server's token entered, the change is taken.
     */
    public function testRegressionAStoredBotTokenNeverGoesToAChangedServer(): void
    {
        $admin = $this->createUserWithRole('nt-mm-api-admin', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id, NotificationTemplate::CHANNEL_MATTERMOST, 'workflow.failed', (string)json_encode(self::CONFIG, JSON_UNESCAPED_SLASHES));
        $this->authenticate($this->createUserWithRole('nt-mm-api-operator', 'operator'));

        $this->setBody(['config' => ['server_url' => FakeMattermost::OTHER_SERVER, 'bot_token' => '$encrypted$', 'channel_id' => FakeMattermost::OTHER_CHANNEL_ID]]);
        $refused = $this->call('update', ['id' => $template->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'bot_token: server_url changed; enter the real value.']], $refused);
        $this->assertSame(self::CONFIG, $this->reload($template)->getParsedConfig());

        $tested = $this->call('test', ['id' => $template->id]);

        $this->assertSame(['data' => ['sent' => true]], $tested);
        $this->assertNotSame([], $this->mattermost->requests);
        $this->assertSame([FakeMattermost::SERVER], array_values(array_unique(array_column($this->mattermost->requests, 'server'))));
        $this->assertSame([FakeMattermost::BOT_TOKEN], array_values(array_unique(array_column($this->mattermost->requests, 'token'))));

        $this->setBody(['config' => ['server_url' => FakeMattermost::OTHER_SERVER, 'bot_token' => FakeMattermost::OTHER_BOT_TOKEN, 'channel_id' => FakeMattermost::OTHER_CHANNEL_ID]]);
        $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(FakeMattermost::OTHER_SERVER, $this->reload($template)->getParsedConfig()['server_url'] ?? null);
    }

    /**
     * The test of a template with a personal token answers 502 with what to
     * do, and posts nothing.
     */
    public function testTheTestOfAPersonalTokenAnswersWhatToDo(): void
    {
        $operator = $this->createUserWithRole('nt-mm-api-test', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id, NotificationTemplate::CHANNEL_MATTERMOST, 'workflow.failed', (string)json_encode(['bot_token' => FakeMattermost::PERSONAL_TOKEN] + self::CONFIG, JSON_UNESCAPED_SLASHES));
        $this->authenticate($operator);

        $answer = $this->call('test', ['id' => $template->id]);

        $this->assertSame(502, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(NotificationTemplatesController::TEST_FAILED, MattermostApiException::PERSONAL_TOKEN)]], $answer);
        $this->assertSame([], $this->mattermost->posts());
    }

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
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-mm-api-token');
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

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }
}
