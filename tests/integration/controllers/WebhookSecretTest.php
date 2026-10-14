<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\api\v1\WebhooksController;
use app\controllers\WebhookController;
use app\models\ApiToken;
use app\models\User;
use app\models\Webhook;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\Response;

/**
 * The HMAC signing secret of an outbound webhook (Webhook::$secret) in its
 * edit form and through the API.
 *
 * regression: the edit form's password field carried the stored secret as
 * its value, in the HTML of the page (yii's passwordInput() renders the
 * attribute's value). Now it carries SurveySecrets::PLACEHOLDER, and a form
 * or an API request that sends the placeholder back keeps the stored secret.
 */
class WebhookSecretTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /** A test value, not a secret. */
    private const SECRET = 'w5b-hmac-signing-secret-6612';
    private const NEW_SECRET = 'w5b-hmac-rotated-secret-0815';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('webhook-secret-admin', 'admin');
        $this->loginAs($this->admin);
    }

    // -- The edit form ------------------------------------------------------------

    /**
     * regression: the stored secret was the value of the form's password field.
     */
    public function testRegressionTheEditFormCarriedTheStoredSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);

        $html = $this->openForm($webhook);

        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertSame(SurveySecrets::PLACEHOLDER, $this->secretField($html));
    }

    public function testTheEditFormOfAWebhookWithoutASecretShowsAnEmptyField(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, null);

        $html = $this->openForm($webhook);

        $this->assertSame('', $this->secretField($html));
        $this->assertStringNotContainsString(Html::encode(Webhook::SECRET_HINT), $html);
    }

    public function testTheEditFormSaysHowToKeepTheSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);

        $this->assertStringContainsString(Html::encode(Webhook::SECRET_HINT), $this->openForm($webhook));
    }

    /**
     * The round trip: the form submitted as shown keeps the stored secret.
     */
    public function testSubmittingTheEditFormUnchangedKeepsTheSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);
        $shown = $this->secretField($this->openForm($webhook));

        $this->setPost(['Webhook' => $this->formData($webhook, 'kept', $shown)]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$webhook->id));

        $stored = $this->reload($webhook);
        $this->assertSame(self::SECRET, $stored->secret);
        $this->assertSame($webhook->name . '-kept', $stored->name);
    }

    public function testANewValueReplacesTheSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);

        $this->setPost(['Webhook' => $this->formData($webhook, 'rotated', self::NEW_SECRET)]);
        $this->makeController()->actionUpdate((int)$webhook->id);

        $this->assertSame(self::NEW_SECRET, $this->reload($webhook)->secret);
    }

    public function testAnEmptyFieldRemovesTheSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);

        $this->setPost(['Webhook' => $this->formData($webhook, 'unsigned', '')]);
        $this->makeController()->actionUpdate((int)$webhook->id);

        $this->assertSame('', (string)$this->reload($webhook)->secret);
    }

    public function testThePlaceholderWithoutAStoredSecretIsAFormError(): void
    {
        $name = 'webhook-secret-create-' . uniqid('', true);
        $this->setPost(['Webhook' => [
            'name' => $name,
            'url' => 'https://hooks.example.com/w5b',
            'eventsArray' => ['job.success'],
            'secret' => SurveySecrets::PLACEHOLDER,
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Webhook::class, $model);
        $this->assertSame(['secret' => [Webhook::SECRET_NOT_STORED]], $model->getErrors());
        $this->assertSame('The secret has no stored value; enter the real value.', Webhook::SECRET_NOT_STORED);
        $this->assertNull(Webhook::findOne(['name' => $name]));
    }

    /**
     * A submission that fails validation shows the submitted field again,
     * never the stored secret that the placeholder stood for.
     */
    public function testAFailedValidationShowsTheSubmissionNotTheStoredSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);
        $data = $this->formData($webhook, 'invalid', SurveySecrets::PLACEHOLDER);
        $data['url'] = 'not a url';
        $this->setPost(['Webhook' => $data]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$webhook->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Webhook::class, $model);
        $this->assertArrayHasKey('url', $model->getErrors());
        $this->assertSame(SurveySecrets::PLACEHOLDER, $ctrl->capturedParams['secret']);
        $html = $this->renderForm($model, (string)$ctrl->capturedParams['secret']);
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertSame(SurveySecrets::PLACEHOLDER, $this->secretField($html));
        $this->assertSame(self::SECRET, $this->reload($webhook)->secret);
    }

    /**
     * A form post without the secret (forged) shows the placeholder for the
     * stored one.
     */
    public function testAPostWithoutTheSecretShowsThePlaceholder(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);
        $this->setPost(['Webhook' => ['name' => '']]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$webhook->id));

        $this->assertSame(SurveySecrets::PLACEHOLDER, $ctrl->capturedParams['secret']);
    }

    public function testTheCreateFormShowsAnEmptyField(): void
    {
        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $this->assertSame('', $ctrl->capturedParams['secret']);
    }

    // -- The API ------------------------------------------------------------------

    public function testAnApiUpdateWithThePlaceholderKeepsTheSecret(): void
    {
        $webhook = $this->createWebhook((int)$this->admin->id, 'job.success', true, self::SECRET);
        $this->authenticate($this->admin);

        $this->setBody(['name' => $webhook->name . '-api', 'secret' => SurveySecrets::PLACEHOLDER]);
        $answer = $this->callApi('update', ['id' => $webhook->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(self::SECRET, $this->reload($webhook)->secret);
        $this->assertStringNotContainsString(self::SECRET, (string)json_encode($answer));
    }

    public function testAnApiCreateWithThePlaceholderIsRefused(): void
    {
        $this->authenticate($this->admin);
        $name = 'webhook-secret-api-' . uniqid('', true);

        $this->setBody(['name' => $name, 'url' => 'https://hooks.example.com/w5b', 'events' => ['job.success'], 'secret' => SurveySecrets::PLACEHOLDER]);
        $answer = $this->callApi('create');

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => Webhook::SECRET_NOT_STORED]], $answer);
        $this->assertNull(Webhook::findOne(['name' => $name]));
    }

    // -- Helpers ------------------------------------------------------------------

    private function openForm(Webhook $webhook): string
    {
        $ctrl = new class ('webhook', \Yii::$app) extends WebhookController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $result = $this->withRealViews(
            $ctrl,
            '/webhook/update?id=' . $webhook->id,
            static fn (): mixed => $ctrl->runAction('update', ['id' => $webhook->id])
        );
        $this->assertIsString($result);

        return $result;
    }

    private function renderForm(Webhook $model, string $secret): string
    {
        $ctrl = new WebhookController('webhook', \Yii::$app);

        return $this->withRealViews(
            $ctrl,
            '/webhook/update?id=' . $model->id,
            static fn (): string => $ctrl->renderPartial('form', ['model' => $model, 'secret' => $secret])
        );
    }

    /** The value the secret's password field carries in the HTML. */
    private function secretField(string $html): string
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $input = (new \DOMXPath($document))->query('//input[@id="webhook-secret"]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $input, 'the secret field');
        $this->assertSame('password', $input->getAttribute('type'));

        return $input->getAttribute('value');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Webhook $webhook, string $suffix, string $secret): array
    {
        return [
            'name' => $webhook->name . '-' . $suffix,
            'url' => $webhook->url,
            'eventsArray' => $webhook->getEventList(),
            'secret' => $secret,
            'enabled' => '1',
        ];
    }

    private function reload(Webhook $webhook): Webhook
    {
        $stored = Webhook::findOne($webhook->id);
        $this->assertInstanceOf(Webhook::class, $stored);

        return $stored;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'webhook-secret-token');
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
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    private function callApi(string $action, array $params = []): array
    {
        /** @var \yii\base\Module $app */
        $app = \Yii::$app;
        $result = (new WebhooksController('api/v1/webhooks', $app))->runAction($action, $params);
        $this->assertIsArray($result);

        return $result;
    }

    private function makeController(): WebhookController
    {
        return new class ('webhook', \Yii::$app) extends WebhookController {
            use CapturesParams;
        };
    }
}
