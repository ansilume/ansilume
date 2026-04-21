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

        // Update heartbeat. Runners post their own software_version in the
        // JSON body on every request; persist it alongside last_seen_at so
        // the UI can flag runners that lag behind the server version.
        // Pre-upgrade runners without the field stay at NULL ("unknown").
        $updates = ['last_seen_at' => time(), 'updated_at' => time()];
        $reportedVersion = $this->readReportedVersion();
        if ($reportedVersion !== null && $reportedVersion !== $runner->software_version) {
            $updates['software_version'] = $reportedVersion;
            $runner->software_version = $reportedVersion;
        }
        Runner::updateAll($updates, ['id' => $runner->id]);
        $runner->last_seen_at = time();

        $this->currentRunner = $runner;
    }

    /**
     * Pull `software_version` from the JSON body, if present and sane.
     * Bounded to 32 chars to match the column width and to keep a
     * hostile runner from stuffing arbitrary strings into the DB.
     */
    private function readReportedVersion(): ?string
    {
        $body = \Yii::$app->request->bodyParams;
        if (!is_array($body)) {
            return null;
        }
        $value = $body['software_version'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 32) {
            return null;
        }
        return $trimmed;
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
