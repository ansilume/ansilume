<?php

declare(strict_types=1);

namespace app\controllers\api\runner;

use app\models\Runner;
use yii\filters\ContentNegotiator;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * Base controller for the runner pull API.
 *
 * Authentication: Bearer <runner_token> in Authorization header.
 * No session, no CSRF — runners authenticate with their per-runner token.
 */
abstract class BaseRunnerApiController extends Controller
{
    public $enableCsrfValidation = false;

    protected ?Runner $currentRunner = null;

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
        $this->authenticateRunner();
        return true;
    }

    private function authenticateRunner(): void
    {
        $header = (string)\Yii::$app->request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedHttpException('Runner token required.');
        }

        $raw = substr($header, 7);
        $runner = Runner::findByToken($raw);

        if ($runner === null) {
            throw new UnauthorizedHttpException('Invalid runner token.');
        }

        // Update heartbeat
        Runner::updateAll(['last_seen_at' => time(), 'updated_at' => time()], ['id' => $runner->id]);
        $runner->last_seen_at = time();

        $this->currentRunner = $runner;
    }

    /**
     * Get the authenticated runner. Always non-null after beforeAction().
     */
    protected function runner(): Runner
    {
        if ($this->currentRunner === null) {
            throw new UnauthorizedHttpException('Runner not authenticated.');
        }
        return $this->currentRunner;
    }

    /**
     * @return array{ok: true}|array{ok: true, data: mixed}
     */
    protected function ok(mixed $data = null): array
    {
        if ($data === null) {
            return ['ok' => true];
        }
        return ['ok' => true, 'data' => $data];
    }

    /**
     * @return array{ok: false, error: string}
     */
    protected function err(string $message, int $status = 400): array
    {
        \Yii::$app->response->statusCode = $status;
        return ['ok' => false, 'error' => $message];
    }
}
