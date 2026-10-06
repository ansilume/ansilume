<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApiToken;
use app\models\User;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Base controller for REST API v1.
 *
 * Authentication: Bearer token in Authorization header.
 * Authorization: every action declares the permission it requires in
 * apiAccessRules(); actions without a rule are denied.
 * All responses: JSON.
 * No CSRF: API uses token auth, not session cookies.
 */
abstract class BaseApiController extends Controller
{
    /**
     * Access rule for actions every signed-in user may call, such as changing
     * one's own password.
     */
    public const AUTHENTICATED = '@';

    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => ['application/json' => Response::FORMAT_JSON],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->authenticateRequest();
        if (!$this->isAllowed($action->id)) {
            \Yii::$app->response->data = $this->error('Forbidden.', 403);
            return false;
        }

        return true;
    }

    /**
     * The permission each action requires, by action id, matching the access
     * rules of the corresponding web controller. beforeAction() enforces it
     * right after authentication and denies every action without a rule, so a
     * new action cannot ship unprotected. Superadmins pass, as in the web UI.
     * Actions still check team scope, approval eligibility or a stricter
     * permission for some inputs themselves.
     *
     * @return array<string, string> action id => permission or AUTHENTICATED
     */
    abstract protected function apiAccessRules(): array;

    /**
     * Whether the signed-in user holds the permission. Superadmins hold every
     * permission, as in the web UI.
     */
    protected function userCan(string $permission): bool
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $identity = $user->identity;
        if ($identity instanceof User && (bool)$identity->is_superadmin) {
            return true;
        }

        return $user->can($permission);
    }

    private function isAllowed(string $actionId): bool
    {
        $permission = $this->apiAccessRules()[$actionId] ?? null;
        if ($permission === null || \Yii::$app->user->isGuest) {
            return false;
        }

        return $permission === self::AUTHENTICATED || $this->userCan($permission);
    }

    /**
     * Validate Bearer token and attach the token/user to the request context.
     *
     * @throws UnauthorizedHttpException
     */
    protected function authenticateRequest(): void
    {
        $header = (string)\Yii::$app->request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedHttpException('Bearer token required.');
        }

        $raw = substr($header, 7);
        $token = ApiToken::findByRawToken($raw);

        if ($token === null) {
            throw new UnauthorizedHttpException('Invalid or expired token.');
        }

        // Tokens of disabled users must not keep working: without an identity
        // the request would otherwise continue as a guest.
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        if ($userComponent->loginByAccessToken($raw) === null) {
            throw new UnauthorizedHttpException('Invalid or expired token.');
        }

        // Update last_used_at without triggering model events
        ApiToken::updateAll(['last_used_at' => time()], ['id' => $token->id]);
    }

    /**
     * @return array{data: mixed}
     */
    protected function success(mixed $data, int $status = 200): array
    {
        \Yii::$app->response->statusCode = $status;
        return ['data' => $data];
    }

    /**
     * @return array{error: array{message: string}}
     */
    protected function error(string $message, int $status = 400): array
    {
        \Yii::$app->response->statusCode = $status;
        return ['error' => ['message' => $message]];
    }

    /**
     * @param array<int, mixed> $items
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    protected function paginated(array $items, int $total, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return [
            'data' => $items,
            'meta' => [
                'total' => max(0, $total),
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int)ceil(max(0, $total) / $perPage),
            ],
        ];
    }
}
