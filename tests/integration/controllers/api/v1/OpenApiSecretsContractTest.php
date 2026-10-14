<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\GitSyncDiagnostics;
use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\api\v1\WebhooksController;
use app\models\ApiToken;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\User;
use app\models\Webhook;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\Request;

/**
 * What web/openapi.yaml says about the secrets that the answers of
 * notification templates, projects and webhooks leave out is what the API
 * answers: every channel secret of a notification template's config is
 * $encrypted$ (NotificationTemplate::SECRET_CONFIG_KEYS and
 * SECRET_CONFIG_MAPS), the spec's PUT example keeps the stored secret, the
 * refusals of NotificationTemplateRejected are NotificationConfigSecrets's,
 * a project's scm_url loses its credentials for callers who may not update
 * the project (GitSyncDiagnostics::redactUrl()), and a webhook's secret
 * takes $encrypted$ only where one is stored (Webhook::SECRET_NOT_STORED).
 *
 * Regression (wave 5, R-SEC-2/W5-API-2): the spec documented config as the
 * stored settings and scm_url without a word on credentials, while every
 * signed-in caller read the secrets in them. Fails on the spec from before.
 */
class OpenApiSecretsContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    private const TEMPLATES = '/api/v1/notification-templates';

    private const TEMPLATE = '/api/v1/notification-templates/{id}';

    /** A bot token of the tests, never a real one. */
    private const BOT_TOKEN = '123456:contract-test-bot-token';

    /** A repository URL with credentials, and how callers who may not update its project read it. */
    private const SCM_URL = 'https://deploy:contract-test-token@git.example.com/org/repo.git';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('secrets-contract-admin', 'admin');
        $this->authenticate($this->admin);
    }

    /**
     * regression: config was documented as the stored settings, with the
     * secrets in them.
     */
    public function testRegressionEveryChannelSecretIsAnsweredAsThePlaceholder(): void
    {
        $description = $this->specText(['components', 'schemas', 'NotificationTemplate', 'properties', 'config', 'description']);
        $this->assertStringContainsString('Every channel secret is answered as `' . SurveySecrets::PLACEHOLDER . '`, to every caller', $description);
        foreach (array_merge(...array_values(NotificationTemplate::SECRET_CONFIG_KEYS), ...array_values(NotificationTemplate::SECRET_CONFIG_MAPS)) as $key) {
            $this->assertStringContainsString('`' . $key . '`', $description, "config names the secret {$key}.");
        }

        $template = $this->telegramTemplate();
        $answer = $this->templates()->actionView((int)$template->id);
        $this->assertArrayHasKey('data', $answer);
        $this->assertIsArray($answer['data']);
        $this->assertSame(
            $this->specAt(['components', 'schemas', 'NotificationTemplate', 'properties', 'config', 'example']),
            $answer['data']['config'],
            'The example is what GET answers for a Telegram template.'
        );
    }

    /**
     * The PUT example sends the placeholder back and keeps the stored
     * secret; a create with it is refused with NotificationConfigSecrets's
     * text, and so are the other refusals the spec quotes.
     */
    public function testTheRoundTripAndTheRefusalsAreAnsweredAsTheSpecSays(): void
    {
        $example = $this->specAt(['paths', self::TEMPLATE, 'put', 'requestBody', 'content', 'application/json', 'example']);
        $this->assertIsArray($example);
        $template = $this->telegramTemplate();
        $this->setBody($example);
        $updated = $this->templates()->actionUpdate((int)$template->id);
        $this->assertArrayHasKey('data', $updated, (string)json_encode($updated));
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame(self::BOT_TOKEN, $stored->getParsedConfig()['bot_token'] ?? null, 'The round trip keeps the stored secret.');

        $refusals = [
            sprintf(NotificationConfigSecrets::NOT_STORED, 'bot_token'),
            sprintf(NotificationConfigSecrets::CHANNEL_CHANGED, 'webhook_url'),
            sprintf(NotificationConfigSecrets::URL_CHANGED, 'headers.Authorization', 'url'),
            NotificationConfigSecrets::NOT_ENCODABLE,
        ];
        $this->assertSame($refusals, $this->exampleMessages(self::TEMPLATES, 'post', '422'));
        $this->assertSame('#/components/responses/NotificationTemplateRejected', $this->specAt(['paths', self::TEMPLATE, 'put', 'responses', '422', '$ref']));
        $input = $this->specText(['components', 'schemas', 'NotificationTemplateInput', 'properties', 'config', 'description']);
        foreach ($refusals as $refusal) {
            $this->assertStringContainsString('`' . $refusal . '`', $input);
        }
        $this->assertEqualsCanonicalizing(
            [['type' => 'object'], ['type' => 'string']],
            (array)$this->specAt(['components', 'schemas', 'NotificationTemplateInput', 'properties', 'config', 'oneOf'])
        );

        $this->setBody(['name' => 'contract-new'] + $example);
        $this->assertSame(['error' => ['message' => $refusals[0]]], $this->templates()->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $slack = $this->template(NotificationTemplate::CHANNEL_SLACK, ['webhook_url' => 'https://hooks.example.com/contract/slack']);
        $this->setBody(['channel' => NotificationTemplate::CHANNEL_TEAMS, 'config' => ['webhook_url' => SurveySecrets::PLACEHOLDER]]);
        $this->assertSame(['error' => ['message' => $refusals[1]]], $this->templates()->actionUpdate((int)$slack->id));

        $webhook = $this->template(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'https://hooks.example.com/contract/a', 'headers' => ['Authorization' => 'Bearer contract']]);
        $this->setBody(['config' => ['url' => 'https://other.example.com/contract/b', 'headers' => ['Authorization' => SurveySecrets::PLACEHOLDER]]]);
        $this->assertSame(['error' => ['message' => $refusals[2]]], $this->templates()->actionUpdate((int)$webhook->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * WebhookInput.secret says what $encrypted$ does, and a create with it
     * is refused with Webhook::SECRET_NOT_STORED.
     */
    public function testTheWebhookSecretIsDescribedAsTheApiTreatsIt(): void
    {
        $description = $this->specText(['components', 'schemas', 'WebhookInput', 'properties', 'secret', 'description']);
        $this->assertStringContainsString('On update, `' . SurveySecrets::PLACEHOLDER . '` keeps the stored secret', $description);
        $this->assertStringContainsString('`' . Webhook::SECRET_NOT_STORED . '`', $description);
        $this->assertArrayNotHasKey('secret', $this->properties('Webhook'), 'The secret is never answered.');

        $this->setBody(['name' => 'contract-hook', 'url' => 'https://hooks.example.com/contract', 'events' => ['job.failure'], 'secret' => SurveySecrets::PLACEHOLDER]);
        $answer = (new WebhooksController('api/v1/webhooks', $this->app()))->actionCreate();
        $this->assertSame(['error' => ['message' => Webhook::SECRET_NOT_STORED]], $answer);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * Webhook documents has_secret, and GET /api/v1/webhooks/{id} answers
     * exactly the documented fields with and without a signing secret
     * ('' and '0' count as none, as on the webhook pages and in
     * WebhookService's signing), never the secret itself.
     */
    public function testHasSecretIsDocumentedAsTheApiAnswersIt(): void
    {
        $this->assertSame('boolean', $this->specAt(['components', 'schemas', 'Webhook', 'properties', 'has_secret', 'type']));
        $this->assertSame(
            'Whether the webhook has a signing secret, so that its deliveries carry `X-Ansilume-Signature`. The secret itself is never answered. A secret of \'\' or \'0\' counts as none, as on the webhook pages.',
            $this->specText(['components', 'schemas', 'Webhook', 'properties', 'has_secret', 'description'])
        );
        $this->assertStringContainsString('`has_secret` says whether one is stored', $this->specText(['components', 'schemas', 'WebhookInput', 'properties', 'secret', 'description']));
        foreach ([['contract-signing-secret', true], [null, false], ['', false], ['0', false]] as [$secret, $hasSecret]) {
            $webhook = $this->createWebhook((int)$this->admin->id, 'job.failure', true, $secret);
            $answer = (new WebhooksController('api/v1/webhooks', $this->app()))->actionView((int)$webhook->id);
            $data = $this->data($answer);
            $this->assertMatchesSchema('Webhook', $data);
            $this->assertSame($hasSecret, $data['has_secret'], 'secret ' . var_export($secret, true));
            $this->assertStringNotContainsString('contract-signing-secret', (string)json_encode($answer));
        }
    }

    /**
     * regression: scm_url was documented without a word on the credentials
     * in it, which every viewer of the project read.
     */
    public function testRegressionTheRepositoryUrlLosesItsCredentialsAsTheSpecSays(): void
    {
        $redacted = GitSyncDiagnostics::redactUrl(self::SCM_URL);
        $this->assertSame('https://***@git.example.com/org/repo.git', $redacted);
        $description = $this->specText(['components', 'schemas', 'Project', 'properties', 'scm_url', 'description']);
        $this->assertStringContainsString('A caller who may update the project (`project.update` and operator access to it) gets it in full', $description);
        $this->assertStringContainsString('replaced by `***@`, such as `' . $redacted . '`', $description);

        $project = $this->createProject((int)$this->admin->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::SCM_URL;
        $this->assertTrue($project->save(false));
        $this->assertSame(self::SCM_URL, $this->scmUrlAnswered((int)$project->id), 'The admin may update the project.');
        $this->authenticate($this->userWithRole('secrets-contract-viewer', 'viewer'));
        $this->assertSame($redacted, $this->scmUrlAnswered((int)$project->id), 'A viewer gets it without the credentials.');
    }

    private function scmUrlAnswered(int $projectId): mixed
    {
        $answer = (new ProjectsController('api/v1/projects', $this->app()))->actionView($projectId);

        return $this->data($answer)['scm_url'] ?? null;
    }

    private function templates(): NotificationTemplatesController
    {
        \Yii::$app->response->statusCode = 200;

        return new NotificationTemplatesController('api/v1/notification-templates', $this->app());
    }

    /**
     * A Telegram template with BOT_TOKEN and the chat of the spec's example.
     */
    private function telegramTemplate(): NotificationTemplate
    {
        return $this->template(NotificationTemplate::CHANNEL_TELEGRAM, ['bot_token' => self::BOT_TOKEN, 'chat_id' => '-1001234567890']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function template(string $channel, array $config): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$this->admin->id, $channel, 'job.failed', (string)json_encode($config, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Signs $user in with an API token of their own.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'secrets-contract');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<mixed> $body
     */
    private function setBody(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }
}
