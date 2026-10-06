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

    /**
     * Regression: a valid token of a disabled user was accepted. The request
     * then ran as a guest, and endpoints without their own permission check
     * (workflow launches, notification templates) still worked.
     */
    public function testTokenOfDisabledUserIsRejected(): void
    {
        $user = $this->createUser('disabled');
        ['token' => $token, 'raw' => $raw] = ApiToken::generate((int)$user->id, 'test-token');
        $user->status = \app\models\User::STATUS_INACTIVE;
        $user->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);

        try {
            (new StubApiController('stub', \Yii::$app))->callAuthenticate();
            $this->fail('the token of a disabled user must be rejected');
        } catch (UnauthorizedHttpException $e) {
            $this->assertSame('Invalid or expired token.', $e->getMessage());
        }
        $this->assertNull(ApiToken::findOne($token->id)?->last_used_at, 'a rejected token is not marked as used');
    }

    private function authenticate(?string $permission = null, bool $superadmin = false): void
    {
        $user = $this->createUser('gate');
        if ($superadmin) {
            $user->is_superadmin = true;
            $user->save(false);
        }
        if ($permission !== null) {
            $auth = \Yii::$app->authManager;
            $this->assertNotNull($auth);
            $role = $auth->createRole('gate-test-' . uniqid());
            $auth->add($role);
            $item = $auth->getPermission($permission);
            $this->assertNotNull($item);
            $auth->addChild($role, $item);
            $auth->assign($role, (string)$user->id);
        }
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'gate-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    private function gate(string $actionId): bool
    {
        $ctrl = new StubApiController('stub', \Yii::$app);

        return $ctrl->beforeAction(new Action($actionId, $ctrl));
    }

    public function testGateDeniesAUserWithoutThePermission(): void
    {
        $this->authenticate();

        $this->assertFalse($this->gate('guarded'));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    public function testGateAllowsAUserWithThePermission(): void
    {
        $this->authenticate('job.view');

        $this->assertTrue($this->gate('guarded'));
    }

    public function testGateDeniesActionsWithoutARuleEvenForSuperadmins(): void
    {
        $this->authenticate('job.view', true);

        $this->assertFalse($this->gate('unlisted'));
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testGateLetsSuperadminsPassEveryRule(): void
    {
        $this->authenticate(null, true);

        $this->assertTrue($this->gate('guarded'));
    }

    public function testAuthenticatedRuleAllowsEverySignedInUser(): void
    {
        $this->authenticate();

        $this->assertTrue($this->gate('open'));
    }
}

/**
 * Concrete test double exposing protected helpers.
 */
class StubApiController extends BaseApiController // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    protected function apiAccessRules(): array
    {
        return [
            'test' => self::AUTHENTICATED,
            'guarded' => 'job.view',
            'open' => self::AUTHENTICATED,
        ];
    }

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
