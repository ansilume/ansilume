<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ApiBodyValues;
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
use app\services\notification\ChannelRegistry;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateSave;
use app\services\notification\WebhookConfig;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\Request;

/**
 * What web/openapi.yaml says about the secrets that the answers of
 * notification templates, projects and webhooks leave out is what the API
 * answers: every channel secret of a notification template's config is
 * $encrypted$ (the secretKeys and secretMaps of every channel's definition,
 * ChannelRegistry), the spec's PUT example keeps the stored secret, the
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
        $secrets = [];
        foreach (ChannelRegistry::installed() as $definition) {
            $secrets = array_merge($secrets, $definition->secretKeys, $definition->secretMaps);
        }
        foreach (array_unique($secrets) as $key) {
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
     * text, and so are the other refusals the spec quotes: those of the
     * config, each quoted where the config is described, and those of the
     * other fields, each quoted where its field is.
     */
    public function testTheRoundTripAndTheRefusalsAreAnsweredAsTheSpecSays(): void
    {
        $example = $this->requestExample('put', 'telegram');
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
            sprintf(NotificationTemplate::URL_SCHEME_REFUSED, 'webhook_url'),
            sprintf(NotificationConfigSecrets::URL_CHANGED, 'bot_token', 'server_url'),
            sprintf(NotificationConfigSecrets::URL_CHANGED, 'signing_secret', 'url'),
            WebhookConfig::HEADERS_FORM,
        ];
        $fieldRefusals = [
            sprintf(ApiBodyValues::NOT_A_VALUE, 'name') => ['NotificationTemplateInput', 'description'],
            NotificationTemplateBody::EVENTS_FORM => ['NotificationTemplateInput', 'properties', 'events', 'description'],
            NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM => ['NotificationTemplateInput', 'properties', 'workflow_template_ids', 'description'],
            sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#41') => ['NotificationTemplateInput', 'properties', 'workflow_template_ids', 'description'],
        ];
        $this->assertSame([...$refusals, ...array_keys($fieldRefusals)], $this->exampleMessages(self::TEMPLATES, 'post', '422'));
        $this->assertSame('#/components/responses/NotificationTemplateRejected', $this->specAt(['paths', self::TEMPLATE, 'put', 'responses', '422', '$ref']));
        $input = $this->specText(['components', 'schemas', 'NotificationTemplateInput', 'properties', 'config', 'description']);
        foreach ($refusals as $refusal) {
            $this->assertStringContainsString('`' . $refusal . '`', $input);
        }
        foreach ($fieldRefusals as $refusal => $where) {
            $this->assertStringContainsString('`' . $refusal . '`', $this->specText(['components', 'schemas', ...$where]), implode('.', $where));
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

        $this->setBody(['name' => 'contract-scheme', 'channel' => NotificationTemplate::CHANNEL_SLACK, 'events' => 'job.failed', 'config' => ['webhook_url' => 'ftp://hooks.example.com/contract/slack']]);
        $this->assertSame(['error' => ['message' => $refusals[4]]], $this->templates()->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNull(NotificationTemplate::findOne(['name' => 'contract-scheme']));

        $mattermost = $this->template(NotificationTemplate::CHANNEL_MATTERMOST, ['server_url' => 'https://chat.example.com', 'bot_token' => self::BOT_TOKEN, 'channel_id' => 'abcdefghijklmnopqrstuvwxyz']);
        $this->setBody(['config' => ['server_url' => 'https://other.example.com', 'bot_token' => SurveySecrets::PLACEHOLDER, 'channel_id' => 'abcdefghijklmnopqrstuvwxyz']]);
        $this->assertSame(['error' => ['message' => $refusals[5]]], $this->templates()->actionUpdate((int)$mattermost->id));
        $signed = $this->template(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'https://hooks.example.com/contract/a', 'signing_secret' => 'contract-signing-secret']);
        $this->setBody(['config' => ['url' => 'https://other.example.com/contract/b', 'signing_secret' => SurveySecrets::PLACEHOLDER]]);
        $this->assertSame(['error' => ['message' => $refusals[6]]], $this->templates()->actionUpdate((int)$signed->id));
        $this->setBody(['name' => 'contract-headers', 'channel' => NotificationTemplate::CHANNEL_WEBHOOK, 'events' => 'job.failed', 'config' => ['url' => 'https://hooks.example.com/contract/h', 'headers' => 'X-Custom: value']]);
        $this->assertSame(['error' => ['message' => $refusals[7]]], $this->templates()->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNull(NotificationTemplate::findOne(['name' => 'contract-headers']));

        $bodies = [
            sprintf(ApiBodyValues::NOT_A_VALUE, 'name') => ['name' => ['contract-list']],
            NotificationTemplateBody::EVENTS_FORM => ['events' => ['first' => 'job.failed']],
            NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM => ['workflow_template_ids' => '41'],
            sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#987654321') => ['workflow_template_ids' => [987654321]],
        ];
        foreach ($bodies as $refusal => $body) {
            $this->setBody($body + ['name' => 'contract-field', 'channel' => NotificationTemplate::CHANNEL_EMAIL, 'events' => 'job.failed', 'config' => ['emails' => ['ops@example.com']]]);
            $this->assertSame(['error' => ['message' => $refusal]], $this->templates()->actionCreate());
            $this->assertSame(422, \Yii::$app->response->statusCode);
        }
        $this->assertNull(NotificationTemplate::findOne(['name' => 'contract-field']));
    }

    /**
     * The request examples of a Mattermost and a signed webhook template
     * are bodies the API takes: a POST example creates its template, whose
     * answer shows each channel secret as $encrypted$, and the PUT example
     * of the same name, sent to it, keeps every stored secret. They hold
     * placeholders only: every channel secret of every example is
     * $encrypted$, a <placeholder>, or a URL of example.com.
     */
    public function testTheMattermostAndSignedWebhookExamplesAreTakenAndHoldPlaceholdersOnly(): void
    {
        $workflows = [(int)$this->createWorkflowTemplate((int)$this->admin->id)->id, (int)$this->createWorkflowTemplate((int)$this->admin->id)->id];
        foreach (['mattermost', 'signedWebhook'] as $name) {
            $post = $this->requestExample('post', $name);
            $put = $this->requestExample('put', $name);
            foreach ([$post, $put] as $example) {
                $this->assertHoldsPlaceholdersOnly($example, $name);
            }

            $this->setBody(self::withIds($post, $workflows) + ['name' => 'contract-' . $name]);
            $created = $this->templates()->actionCreate();
            $this->assertSame(201, \Yii::$app->response->statusCode, $name . ': ' . json_encode($created));
            $data = $this->data($created);
            $this->assertIsArray($data['config']);
            foreach ($this->secretsOf((string)$post['channel'], $data['config']) as $place => $shown) {
                $this->assertSame(SurveySecrets::PLACEHOLDER, $shown, "{$name}: {$place} is answered as the placeholder");
            }
            $stored = NotificationTemplate::findOne((int)$data['id']);
            $this->assertNotNull($stored);
            $this->assertSame($post['config'], $stored->getParsedConfig(), "{$name}: stored as sent");

            $this->setBody(self::withIds($put, $workflows));
            $updated = $this->templates()->actionUpdate((int)$data['id']);
            $this->assertSame(200, \Yii::$app->response->statusCode, $name . ': ' . json_encode($updated));
            $stored->refresh();
            $this->assertSame(
                $this->secretsOf((string)$post['channel'], (array)$post['config']),
                $this->secretsOf((string)$post['channel'], $stored->getParsedConfig()),
                "{$name}: the PUT example keeps every stored secret"
            );
        }
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

    /**
     * The request example $name of the $method of a notification template:
     * POST /api/v1/notification-templates or PUT .../{id}.
     *
     * @return array<string, mixed>
     */
    private function requestExample(string $method, string $name): array
    {
        $example = $this->specAt(['paths', $method === 'post' ? self::TEMPLATES : self::TEMPLATE, $method, 'requestBody', 'content', 'application/json', 'examples', $name, 'value']);
        $this->assertIsArray($example);
        $named = [];
        foreach ($example as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * $example with the workflow templates $ids in place of its own, which
     * name none of the test database.
     *
     * @param array<string, mixed> $example
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private static function withIds(array $example, array $ids): array
    {
        if (isset($example['workflow_template_ids'])) {
            $example['workflow_template_ids'] = array_slice($ids, 0, count((array)$example['workflow_template_ids']));
        }

        return $example;
    }

    /**
     * The values at the places of the channel secrets of $config, a config
     * of $channel (ChannelRegistry), by place: a secret key, or a map's
     * key with each of its keys.
     *
     * @param array<array-key, mixed> $config
     * @return array<string, mixed>
     */
    private function secretsOf(string $channel, array $config): array
    {
        $definition = ChannelRegistry::definitionOf($channel);
        $this->assertNotNull($definition, $channel);
        $secrets = [];
        foreach ($definition->secretKeys as $key) {
            if (array_key_exists($key, $config)) {
                $secrets[$key] = $config[$key];
            }
        }
        foreach ($definition->secretMaps as $map) {
            foreach ((array)($config[$map] ?? []) as $key => $value) {
                $secrets[$map . '.' . $key] = $value;
            }
        }

        return $secrets;
    }

    /**
     * Every channel secret of $example's config is the placeholder, holds
     * a <placeholder>, or is a URL of example.com: no example carries what
     * could be a real secret.
     *
     * @param array<string, mixed> $example
     */
    private function assertHoldsPlaceholdersOnly(array $example, string $name): void
    {
        $this->assertIsArray($example['config'] ?? null, $name);
        $secrets = $this->secretsOf((string)($example['channel'] ?? ''), $example['config']);
        $this->assertNotSame([], $secrets, "{$name} has secrets");
        foreach ($secrets as $place => $value) {
            $this->assertIsString($value, "{$name}: {$place}");
            $host = (string)parse_url($value, PHP_URL_HOST);
            $this->assertTrue(
                $value === SurveySecrets::PLACEHOLDER || preg_match('/<[a-z][a-z-]*>/', $value) === 1 || str_ends_with($host, '.example.com'),
                "{$name}: {$place} must be \$encrypted\$, a <placeholder> or a URL of example.com, not " . $value
            );
        }
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
