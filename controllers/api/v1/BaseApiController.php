<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApiToken;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Base controller for REST API v1.
 *
 * Authentication: Bearer token in Authorization header.
 * All responses: JSON.
 * No CSRF: API uses token auth, not session cookies.
 */
abstract class BaseApiController extends Controller
{
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
        return true;
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

        // Update last_used_at without triggering model events
        ApiToken::updateAll(['last_used_at' => time()], ['id' => $token->id]);

        // Make user available via Yii::$app->user if needed
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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
        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int)ceil($total / max($perPage, 1)),
            ],
        ];
    }
}
