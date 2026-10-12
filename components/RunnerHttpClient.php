<?php

declare(strict_types=1);

namespace app\components;

use app\models\Runner;

/**
 * Minimal HTTP client for runner ↔ server communication.
 *
 * Uses file_get_contents + stream_context — no framework dependency.
 */
class RunnerHttpClient
{
    /**
     * Features this runner build implements, reported with every request so
     * the server knows which runners honour them. Older servers ignore the
     * field.
     */
    public const CAPABILITIES = [Runner::CAPABILITY_VAULT_PASSWORD_SOURCE, Runner::CAPABILITY_SECRET_EXTRA_VARS];

    private string $apiUrl;
    private string $token;
    private int $lastHttpStatus = 0;

    public function __construct(string $apiUrl, #[\SensitiveParameter] string $token)
    {
        $this->apiUrl = $apiUrl;
        $this->token = $token;
    }

    public function setToken(#[\SensitiveParameter] string $token): void
    {
        $this->token = $token;
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /**
     * POST JSON to the ansilume API with Bearer authentication.
     *
     * Every request automatically carries `software_version` from the
     * local VERSION file (read via `Yii::$app->params['version']`) so the
     * server can track which version each runner is actually running, and
     * `capabilities` ({@see CAPABILITIES}) so it knows which features the
     * runner honours. Callers can override either value by passing their
     * own key in $body — useful for tests; in practice nothing does.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function post(string $path, #[\SensitiveParameter] array $body): ?array
    {
        $body = array_merge(
            ['software_version' => $this->softwareVersion(), 'capabilities' => self::CAPABILITIES],
            $body
        );
        return $this->httpPost($path, $body, [
            'Authorization: Bearer ' . $this->token,
        ]);
    }

    /**
     * POST JSON to the ansilume API without authentication (for registration).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function postUnauthenticated(string $path, #[\SensitiveParameter] array $body): ?array
    {
        return $this->httpPost($path, $body);
    }

    /**
     * Low-level HTTP POST. Returns decoded JSON response or null on network error / empty body.
     *
     * Bytes that are not UTF-8 (job output can hold any) become U+FFFD
     * instead of failing the encoding, which would drop the whole request.
     *
     * @param array<string, mixed> $body
     * @param string[] $extraHeaders Additional HTTP headers.
     * @return array<string, mixed>|null
     */
    protected function httpPost(
        string $path,
        #[\SensitiveParameter] array $body,
        #[\SensitiveParameter] array $extraHeaders = []
    ): ?array {
        $url = $this->apiUrl . $path;
        $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return null;
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($payload),
        ], $extraHeaders);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $raw = $this->fetchUrl($url, $context);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Execute file_get_contents with error handling and HTTP status extraction.
     *
     * @param resource $context
     * @return string|false
     */
    private function fetchUrl(string $url, $context)
    {
        set_error_handler(function (): bool {
            return true;
        }, E_WARNING);
        $raw = file_get_contents($url, false, $context);
        restore_error_handler();

        $this->lastHttpStatus = 0;
        if (!empty($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $this->lastHttpStatus = (int)($m[1] ?? 0);
        }

        return $raw;
    }

    /**
     * Runner's own build version. Reads from the VERSION file baked into
     * the image by `./bin/release`. Falls back to "dev" on a source
     * checkout where the file is absent or in test fixtures where no Yii
     * app is wired. Capped at 32 chars to match the column width on the
     * server side.
     */
    private function softwareVersion(): string
    {
        $version = 'dev';
        if (\Yii::$app !== null) {
            $paramsValue = \Yii::$app->params['version'] ?? null;
            if (is_string($paramsValue) && $paramsValue !== '') {
                $version = $paramsValue;
            }
        }
        return substr($version, 0, 32);
    }
}
