<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\BaseApiController;
use app\models\ApiToken;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\base\Action;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Exercises the abstract BaseApiController via a minimal concrete subclass
 * so every protected helper (authenticateRequest, success, error, paginated,
 * behaviors, beforeAction) is covered.
 */
class BaseApiControllerTest extends WebControllerTestCase
{
    public function testBehaviorsRegistersContentNegotiator(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $behaviors = $ctrl->behaviors();
        $this->assertArrayHasKey('contentNegotiator', $behaviors);
    }

    public function testSuccessReturnsWrappedData(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $result = $ctrl->callSuccess(['foo' => 'bar'], 201);
        $this->assertSame(['data' => ['foo' => 'bar']], $result);
        $this->assertSame(201, \Yii::$app->response->statusCode);
    }

    public function testErrorReturnsWrappedMessage(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $result = $ctrl->callError('boom', 422);
        $this->assertSame(['error' => ['message' => 'boom']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testPaginatedReturnsDataAndMeta(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $result = $ctrl->callPaginated(['a', 'b', 'c'], 25, 2, 10);
        $this->assertSame(['a', 'b', 'c'], $result['data']);
        $this->assertSame(25, $result['meta']['total']);
        $this->assertSame(2, $result['meta']['page']);
        $this->assertSame(10, $result['meta']['per_page']);
        $this->assertSame(3, $result['meta']['pages']); // ceil(25/10)
    }

    public function testPaginatedHandlesZeroPerPageGracefully(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $result = $ctrl->callPaginated([], 5, 1, 0);
        // max(0, 1) = 1 → pages = 5
        $this->assertSame(5, $result['meta']['pages']);
    }

    public function testAuthenticateRequestRejectsMissingHeader(): void
    {
        $ctrl = new StubApiController('stub', \Yii::$app);
        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Bearer token required.');
        $ctrl->callAuthenticate();
    }

    public function testAuthenticateRequestRejectsMalformedHeader(): void
    {
        \Yii::$app->request->headers->set('Authorization', 'Basic foo');
        $ctrl = new StubApiController('stub', \Yii::$app);
        $this->expectException(UnauthorizedHttpException::class);
        $ctrl->callAuthenticate();
    }

    public function testAuthenticateRequestRejectsUnknownToken(): void
    {
        \Yii::$app->request->headers->set('Authorization', 'Bearer does-not-exist');
        $ctrl = new StubApiController('stub', \Yii::$app);
        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Invalid or expired token.');
        $ctrl->callAuthenticate();
    }

    public function testAuthenticateRequestAcceptsValidToken(): void
    {
        $user = $this->createUser();
        ['token' => $token, 'raw' => $raw] = ApiToken::generate((int)$user->id, 'test-token');

        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);

        $ctrl = new StubApiController('stub', \Yii::$app);
        $ctrl->callAuthenticate();

        // last_used_at should have been bumped.
        $reloaded = ApiToken::findOne($token->id);
        $this->assertNotNull($reloaded->last_used_at);
    }

    public function testBeforeActionInvokesAuthenticate(): void
    {
        \Yii::$app->request->headers->set('Authorization', '');
        $ctrl = new StubApiController('stub', \Yii::$app);
        $action = new Action('test', $ctrl);
        $this->expectException(UnauthorizedHttpException::class);
        $ctrl->beforeAction($action);
    }
}

/**
 * Concrete test double exposing protected helpers.
 */
class StubApiController extends BaseApiController
{
    public function callAuthenticate(): void
    {
        $this->authenticateRequest();
    }

    public function callSuccess(mixed $data, int $status = 200): array
    {
        return $this->success($data, $status);
    }

    public function callError(string $message, int $status = 400): array
    {
        return $this->error($message, $status);
    }

    /**
     * @param array<int, mixed> $items
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function callPaginated(array $items, int $total, int $page, int $perPage): array
    {
        return $this->paginated($items, $total, $page, $perPage);
    }
}
