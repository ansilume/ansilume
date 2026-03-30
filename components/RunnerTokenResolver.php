<?php

declare(strict_types=1);

namespace app\components;

use yii\console\Controller;

/**
 * Resolves a runner authentication token from environment, cache, or self-registration.
 */
class RunnerTokenResolver
{
    private RunnerHttpClient $http;
    /** @phpstan-ignore-next-line Yii2 Controller is generic but type param is irrelevant here */
    private Controller $controller;

    /**
     * @phpstan-ignore-next-line
     */
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
        $this->controller->stdout("No token found — registering as '{$name}' with the server...\n");

        $response = $this->http->postUnauthenticated('/api/runner/v1/register', [
            'name' => $name,
            'bootstrap_secret' => $bootstrapSecret,
        ]);

        if ($response === null) {
            $apiUrl = '(server)';
            $this->controller->stderr("ERROR: Could not reach the server at {$apiUrl} for registration.\n");
            return '';
        }

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (empty($response['ok']) || empty($responseData['token'])) {
            $error = (string)($response['error'] ?? 'unknown error');
            $this->controller->stderr("ERROR: Registration failed: {$error}\n");
            return '';
        }

        $token = (string)$responseData['token'];
        $this->cacheToken($name, $token);

        $this->controller->stdout("Registered successfully. Token cached.\n");
        return $token;
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
