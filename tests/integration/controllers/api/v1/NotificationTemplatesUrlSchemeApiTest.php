<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\NotificationTemplate;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * POST and PUT /api/v1/notification-templates take only http and https URLs
 * in a channel config (the urlSchemes of its channel's definition): another
 * scheme answers 422 with URL_SCHEME_REFUSED, and nothing is written.
 *
 * Regression: the API stored a URL of any scheme.
 */
class NotificationTemplatesUrlSchemeApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testCreatingWithAUrlOfAnotherSchemeIsRefused(): void
    {
        $this->authenticate($this->createUserWithRole('nt-url-api-create', 'operator'));
        $name = 'nt-url-api-' . uniqid('', true);
        $this->setBody([
            'name' => $name,
            'channel' => NotificationTemplate::CHANNEL_SLACK,
            'events' => 'job.failed',
            'config' => ['webhook_url' => 'gopher://127.0.0.1:70/_x'],
        ]);

        $answer = $this->call('create');

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'webhook_url must be an http or https URL.']], $answer);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
    }

    public function testUpdatingToAUrlOfAnotherSchemeIsRefusedAndAnHttpsUrlTaken(): void
    {
        $operator = $this->createUserWithRole('nt-url-api-update', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id, NotificationTemplate::CHANNEL_WEBHOOK, 'job.failed', '{"url":"https://hooks.example.com/before"}');
        $this->authenticate($operator);

        $this->setBody(['config' => ['url' => 'file:///etc/hostname']]);
        $refused = $this->call('update', ['id' => $template->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'url must be an http or https URL.']], $refused);
        $this->assertSame(['url' => 'https://hooks.example.com/before'], $this->reload($template)->getParsedConfig());

        $this->setBody(['config' => ['url' => 'https://hooks.example.com/after']]);
        $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['url' => 'https://hooks.example.com/after'], $this->reload($template)->getParsedConfig());
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
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-url-api-token');
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
