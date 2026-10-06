<?php

declare(strict_types=1);

namespace app\components;

use yii\console\Controller;

/**
 * Resolves a runner authentication token from environment, cache, or self-registration.
 */
class RunnerTokenResolver
{
    /**
     * Registration attempts while the server reports 503 (not initialized
     * yet, e.g. quickstart has not created the admin user). 12 × 5s covers
     * a normal first-boot window before giving up and letting the container
     * restart policy take over.
     */
    private const REGISTER_MAX_ATTEMPTS = 12;
    private const REGISTER_RETRY_SECONDS = 5;

    private RunnerHttpClient $http;
    private Controller $controller;

    public function __construct(RunnerHttpClient $http, Controller $controller)
    {
        $this->http = $http;
        $this->controller = $controller;
    }

    /**
     * Resolve a runner token from RUNNER_TOKEN env, cache file, or self-registration.
     */
    public function resolve(): string
    {
        $explicit = $_ENV['RUNNER_TOKEN'] ?? '';
        if ($explicit !== '') {
            return $explicit;
        }

        $name = $_ENV['RUNNER_NAME'] ?? '';
        $bootstrapSecret = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? '';

        if ($name === '' || $bootstrapSecret === '') {
            return '';
        }

        $cached = $this->readCachedToken($name);
        if ($cached !== '') {
            return $cached;
        }

        return $this->selfRegister($name, $bootstrapSecret);
    }

    /**
     * Clear the cached token for a runner name and re-resolve.
     */
    public function clearCacheAndResolve(): string
    {
        $name = $_ENV['RUNNER_NAME'] ?? '';
        $cacheFile = $name !== '' ? $this->tokenCacheFile($name) : '';
        if ($cacheFile !== '' && file_exists($cacheFile)) {
            $this->controller->stdout("Cached token rejected (401) — clearing cache and re-registering...\n");
            \app\helpers\FileHelper::safeUnlink($cacheFile);
        }
        return $this->resolve();
    }

    /**
     * Check whether a cache file exists for the current runner name.
     */
    public function hasCacheFile(): bool
    {
        $name = $_ENV['RUNNER_NAME'] ?? '';
        if ($name === '') {
            return false;
        }
        return file_exists($this->tokenCacheFile($name));
    }

    private function readCachedToken(string $name): string
    {
        $cacheFile = $this->tokenCacheFile($name);
        if (file_exists($cacheFile)) {
            $cached = trim((string)file_get_contents($cacheFile));
            if ($cached !== '') {
                return $cached;
            }
        }
        return '';
    }

    private function selfRegister(string $name, string $bootstrapSecret): string
    {
        $group = $_ENV['RUNNER_GROUP'] ?? '';
        $groupInfo = $group !== '' ? " in group '{$group}'" : '';
        $this->controller->stdout("No token found — registering as '{$name}'{$groupInfo} with the server...\n");

        $payload = $this->buildRegisterPayload($name, $bootstrapSecret, $group);
        $lastError = '';

        for ($attempt = 1; $attempt <= self::REGISTER_MAX_ATTEMPTS; $attempt++) {
            $response = $this->http->postUnauthenticated('/api/runner/v1/register', $payload);

            $token = $this->extractToken($response);
            if ($token !== '') {
                $this->cacheToken($name, $token);

                $this->controller->stdout("Registered successfully. Token cached.\n");
                return $token;
            }

            $lastError = $this->registrationErrorText($response);
            if (!$this->isNotReadyStatus()) {
                return $this->failPermanently($response, $lastError);
            }

            // 502/503 (also as an unparsable nginx HTML page, i.e. a null
            // response) means the server is still booting or awaits its
            // initial setup — wait and try again.
            if ($attempt < self::REGISTER_MAX_ATTEMPTS) {
                $this->controller->stdout(
                    "Server is not ready for runner registration yet ({$lastError}) — retrying in "
                    . self::REGISTER_RETRY_SECONDS . "s...\n"
                );
                $this->waitBeforeRetry();
            }
        }

        $this->controller->stderr("ERROR: Registration failed: {$lastError}\n");
        $this->controller->stderr($this->neverReadyHint() . " The runner will retry after the container restarts.\n");
        return '';
    }

    /**
     * A null response without a not-ready status means the server was never
     * reached at all; any other non-retryable answer is a permanent rejection.
     *
     * @param array<string, mixed>|null $response
     */
    private function failPermanently(?array $response, string $error): string
    {
        if ($response === null) {
            $apiUrl = (string)($_ENV['API_URL'] ?? '(server)');
            $this->controller->stderr("ERROR: Could not reach the server at {$apiUrl} for registration.\n");
            return '';
        }

        $this->controller->stderr("ERROR: Registration failed: {$error}\n");
        return '';
    }

    /**
     * Operator guidance after the retry budget is exhausted, depending on
     * which not-ready status the server kept answering with.
     */
    private function neverReadyHint(): string
    {
        if ($this->http->getLastHttpStatus() === 502) {
            return 'The app container never answered (502 from nginx) — '
                . 'check that it is running and healthy: docker compose logs app.';
        }

        return 'The server never became ready (503) — complete the initial setup (create the admin user).';
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function extractToken(?array $response): string
    {
        if ($response === null || empty($response['ok'])) {
            return '';
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        return (string)($data['token'] ?? '');
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function registrationErrorText(?array $response): string
    {
        if ($response === null) {
            return 'HTTP ' . $this->http->getLastHttpStatus() . ' without a readable response body';
        }
        return (string)($response['error'] ?? 'unknown error');
    }

    /**
     * @return array<string, string>
     */
    private function buildRegisterPayload(string $name, string $bootstrapSecret, string $group): array
    {
        $payload = [
            'name' => $name,
            'bootstrap_secret' => $bootstrapSecret,
            'software_version' => substr((string)(\Yii::$app->params['version'] ?? 'dev'), 0, 32),
        ];
        if ($group !== '') {
            $payload['group'] = $group;
        }
        return $payload;
    }

    /**
     * A 503 means the server itself is not ready to register runners yet
     * (typically: no admin user exists seconds after a fresh install); a 502
     * is nginx answering for an app container that is still booting.
     * Anything else is a permanent error and must fail loudly.
     */
    private function isNotReadyStatus(): bool
    {
        return in_array($this->http->getLastHttpStatus(), [502, 503], true);
    }

    protected function waitBeforeRetry(): void
    {
        sleep(self::REGISTER_RETRY_SECONDS);
    }

    private function cacheToken(string $name, string $token): void
    {
        $cacheFile = $this->tokenCacheFile($name);
        \app\helpers\FileHelper::safeFilePutContents($cacheFile, $token);
        \app\helpers\FileHelper::safeChmod($cacheFile, 0600);
    }

    protected function tokenCacheFile(string $name): string
    {
        return '/var/www/runtime/runner-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.token';
    }
}
