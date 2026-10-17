<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\ApiToken;
use app\models\User;
use yii\web\Controller;
use yii\web\HttpException;
use yii\web\Request;

/**
 * Calls of REST API actions as a client makes them, for the tests of the
 * fields that say what gates a launch (JobTemplatesApprovalGateTest,
 * JobsInventoryApiTest, ApprovalsGateFieldsApiTest): through runAction(),
 * so the controller's filters, its token authentication and its access
 * rules run too. Use in WebControllerTestCase subclasses together with
 * TeamScopeFixtures.
 */
trait GateFieldsApiCalls
{
    /**
     * The next calls carry a new API token of $user.
     */
    protected function bearerOf(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'gate-fields-api-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * The next calls carry no API token, or with $invalid one that belongs
     * to nobody.
     */
    protected function bearerOfNobody(bool $invalid): void
    {
        $headers = \Yii::$app->request->headers;
        $headers->remove('Authorization');
        if ($invalid) {
            $headers->set('Authorization', 'Bearer ' . str_repeat('0', 64));
        }
    }

    /**
     * $verb of $action of $controller, with the action parameters $params,
     * the query $query and the body $body, as the web application runs it;
     * the status and the answer.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function callApi(Controller $controller, string $action, string $verb, array $params = [], array $body = [], array $query = []): array
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
        $request->setQueryParams($query);
        $_SERVER['REQUEST_METHOD'] = $verb;
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;

        $result = $controller->runAction($action, $params);
        $answer = is_array($result) ? $result : \Yii::$app->response->data;
        $this->assertIsArray($answer, "{$verb} {$controller->id}/{$action} answers with an array");
        /** @var array<string, mixed> $answer */

        return ['status' => \Yii::$app->response->statusCode, 'body' => $answer];
    }

    /**
     * callApi() for a call the framework answers with an HTTP error, such as
     * a 401 or a 404: the error.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     */
    protected function failedApiCall(Controller $controller, string $action, string $verb, array $params = [], array $body = []): HttpException
    {
        try {
            $this->callApi($controller, $action, $verb, $params, $body);
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail("{$verb} {$controller->id}/{$action} was expected to fail with an HTTP exception.");
    }

    /**
     * The data of $answer, after asserting its status.
     *
     * @param array{status: int, body: array<string, mixed>} $answer
     * @return array<mixed>
     */
    protected function answerData(array $answer, int $status = 200): array
    {
        $this->assertSame($status, $answer['status'], (string)json_encode($answer['body']));
        $this->assertArrayHasKey('data', $answer['body']);
        $this->assertIsArray($answer['body']['data']);

        return $answer['body']['data'];
    }

    /**
     * The items of a list answer, by ID.
     *
     * @param array{status: int, body: array<string, mixed>} $answer
     * @return array<int, array<string, mixed>>
     */
    protected function answerItems(array $answer): array
    {
        $items = [];
        foreach ($this->answerData($answer) as $item) {
            $this->assertIsArray($item);
            /** @var array<string, mixed> $item */
            $items[(int)$item['id']] = $item;
        }

        return $items;
    }

    /**
     * $answer is the API's error with $status and $message.
     *
     * @param array{status: int, body: array<string, mixed>} $answer
     */
    protected function assertApiRefusal(int $status, string $message, array $answer): void
    {
        $this->assertSame(['error' => ['message' => $message]], $answer['body']);
        $this->assertSame($status, $answer['status']);
    }

    /**
     * A user whose only role holds exactly $permissions.
     *
     * @param list<string> $permissions
     */
    protected function userWithPermissions(string $suffix, array $permissions): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('gate-fields-' . $suffix . '-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser($suffix);
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    /**
     * A superadmin without any role.
     */
    protected function rolelessSuperadmin(): User
    {
        $user = $this->createUser('gate-fields-superadmin');
        $user->is_superadmin = true;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $this->assertSame([], $auth->getAssignments((string)$user->id));

        return $user;
    }
}
