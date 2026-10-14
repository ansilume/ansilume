<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowStepsController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowStep;
use yii\web\HttpException;
use yii\web\JsonParser;
use yii\web\Request;
use yii\web\UrlManager;

/**
 * Requests to the per-step endpoints of the workflow API
 * (WorkflowStepsController), sent as the web application receives them: the
 * URL rules of config/web.php resolve the route and its parameters, the body
 * is JSON parsed as in a real request, and the controller runs the action
 * with its filters, its authentication and its access rules. Use in
 * WebControllerTestCase subclasses.
 */
trait WorkflowStepApiRequests
{
    /** The route prefix of the per-step controller in config/web.php. */
    private const STEPS_ROUTE = 'api/v1/workflow-steps/';

    private ?UrlManager $webRules = null;

    /**
     * The next requests carry an API token of $user.
     */
    protected function tokenOf(User $user): ApiToken
    {
        ['raw' => $raw, 'token' => $token] = ApiToken::generate((int)$user->id, 'workflow-steps-api-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);

        return $token;
    }

    /**
     * Send $verb /$path with $body as its JSON body, through the URL rules
     * and the controller, and return the status and the answer. A float
     * stays a float (1.0, not 1), as a client can send it.
     *
     * @return array{status: int, body: array<mixed>}
     */
    protected function api(string $verb, string $path, mixed $body = []): array
    {
        return $this->apiRaw($verb, $path, (string)json_encode($body, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Like api(), with the body as JSON text, such as one with a number too
     * large for PHP to encode again.
     *
     * @return array{status: int, body: array<mixed>}
     */
    protected function apiRaw(string $verb, string $path, string $json): array
    {
        [$action, $params] = $this->routeOf($verb, $path, $json);
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;
        $result = (new WorkflowStepsController('api/v1/workflow-steps', \Yii::$app))->runAction($action, $params);
        $answer = $result ?? \Yii::$app->response->data;
        $this->assertIsArray($answer, "{$verb} /{$path} answers with an array");

        return ['status' => \Yii::$app->response->statusCode, 'body' => $answer];
    }

    /**
     * Send $verb /$path as api() does, expecting an HTTP exception, which
     * the error handler would render: a 401 or a 404 for the workflow.
     */
    protected function apiFails(string $verb, string $path, mixed $body = []): HttpException
    {
        try {
            $this->api($verb, $path, $body);
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail("{$verb} /{$path} was expected to fail with an HTTP exception.");
    }

    /**
     * The answer's data, after asserting the status.
     *
     * @param array{status: int, body: array<mixed>} $answer
     * @return array<mixed>
     */
    protected function dataOf(array $answer, int $status = 200): array
    {
        $this->assertSame($status, $answer['status'], (string)json_encode($answer['body']));
        $this->assertArrayHasKey('data', $answer['body']);
        $this->assertIsArray($answer['body']['data']);

        return $answer['body']['data'];
    }

    /**
     * The answer's change ({message, warnings}), after asserting the status.
     *
     * @param array{status: int, body: array<mixed>} $answer
     * @return array<mixed>
     */
    protected function changeOf(array $answer, int $status = 200): array
    {
        $this->assertSame($status, $answer['status'], (string)json_encode($answer['body']));
        $this->assertArrayHasKey('change', $answer['body']);
        $this->assertIsArray($answer['body']['change']);

        return $answer['body']['change'];
    }

    /**
     * The answer is the API's error with $status, $message and $details.
     *
     * @param array{status: int, body: array<mixed>} $answer
     * @param array<string, mixed> $details
     */
    protected function assertApiError(int $status, string $message, array $answer, array $details = []): void
    {
        $this->assertSame(['error' => ['message' => $message] + $details], $answer['body']);
        $this->assertSame($status, $answer['status']);
    }

    protected function stepsPath(int $workflowId): string
    {
        return 'api/v1/workflow-templates/' . $workflowId . '/steps';
    }

    protected function stepPath(int $workflowId, int $stepId): string
    {
        return $this->stepsPath($workflowId) . '/' . $stepId;
    }

    protected function movePath(int $workflowId, int $stepId): string
    {
        return $this->stepPath($workflowId, $stepId) . '/move';
    }

    /**
     * The ID of the step named $name of the workflow.
     */
    protected function stepIdOf(int $workflowId, string $name): int
    {
        $id = WorkflowStep::find()->select('id')->where(['workflow_template_id' => $workflowId, 'name' => $name])->scalar();
        $this->assertNotFalse($id, "step {$name}");

        return (int)$id;
    }

    /**
     * The URL rules of the web application, read from config/web.php.
     */
    protected function webRules(): UrlManager
    {
        if ($this->webRules === null) {
            /** @var array{components: array{urlManager: array{rules: array<mixed>}}} $config */
            $config = require dirname(__DIR__, 5) . '/config/web.php';
            $this->webRules = new UrlManager([
                'enablePrettyUrl' => true,
                'showScriptName' => false,
                'enableStrictParsing' => false,
                'rules' => $config['components']['urlManager']['rules'],
                'cache' => false,
                'baseUrl' => '',
                'scriptUrl' => '/index.php',
            ]);
        }

        return $this->webRules;
    }

    /**
     * Prepare the request $verb /$path with the JSON body $json and resolve
     * it with the URL rules: the action of the per-step controller and its
     * parameters.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function routeOf(string $verb, string $path, string $json): array
    {
        $_SERVER['REQUEST_METHOD'] = $verb;
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        // Parsed when the action first reads it, as in a real request.
        $request->setBodyParams(null);
        $request->setPathInfo($path);
        $request->setQueryParams([]);
        $parsed = $this->webRules()->parseRequest($request);
        $this->assertIsArray($parsed, "{$verb} /{$path} resolves");
        [$route, $params] = $parsed;
        $this->assertIsString($route);
        $this->assertIsArray($params);
        $this->assertStringStartsWith(self::STEPS_ROUTE, $route, "{$verb} /{$path} reaches the workflow steps controller");

        /** @var array<string, mixed> $params */
        return [substr($route, strlen(self::STEPS_ROUTE)), $params];
    }
}
