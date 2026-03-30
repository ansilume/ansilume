<?php

declare(strict_types=1);

namespace app\components;

/**
 * Minimal HTTP client for runner ↔ server communication.
 *
 * Uses file_get_contents + stream_context — no framework dependency.
 */
class RunnerHttpClient
{
    private string $apiUrl;
    private string $token;
    private int $lastHttpStatus = 0;

    public function __construct(string $apiUrl, string $token)
    {
        $this->apiUrl = $apiUrl;
        $this->token = $token;
    }

    public function setToken(string $token): void
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
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function post(string $path, array $body): ?array
    {
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
    public function postUnauthenticated(string $path, array $body): ?array
    {
        return $this->httpPost($path, $body);
    }

    /**
     * Low-level HTTP POST. Returns decoded JSON response or null on network error / empty body.
     *
     * @param array<string, mixed> $body
     * @param string[] $extraHeaders Additional HTTP headers.
     * @return array<string, mixed>|null
     */
    private function httpPost(string $path, array $body, array $extraHeaders = []): ?array
    {
        $url = $this->apiUrl . $path;
        $payload = json_encode($body);
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

        return json_decode($raw, true) ?: null;
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
}
