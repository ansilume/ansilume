<?php

declare(strict_types=1);

namespace app\components;

use yii\web\Request;

/**
 * Finds the trigger token of an inbound trigger call and how it was sent,
 * in this order:
 *
 * 1. the X-Trigger-Token header;
 * 2. the URL: the `token` query parameter, which also holds the token of the
 *    path form /trigger/<token> (Yii merges route parameters into the query
 *    parameters);
 * 3. `Authorization: Bearer <token>`. Only the Bearer scheme counts, and a
 *    URL token wins over it, so a caller that sends an unrelated
 *    Authorization header (Basic credentials for a proxy, the API token of a
 *    shared HTTP client) keeps working with its URL token.
 *
 * A transport whose value is not a non-empty string (an empty header,
 * token[]=x) counts as absent. Tokens in URLs are deprecated: URLs end up in
 * access logs and in proxies in front of Ansilume; headers do not.
 */
final class TriggerTokenResolver
{
    /** The header that carries the token. */
    public const HEADER = 'X-Trigger-Token';

    public const TRANSPORT_HEADER = 'header';
    public const TRANSPORT_URL = 'url';
    public const TRANSPORT_BEARER = 'bearer';

    /**
     * The token of $request and its transport, or null when it carries none.
     *
     * @return array{token: non-empty-string, transport: string}|null
     */
    public static function resolve(Request $request): ?array
    {
        $headers = $request->getHeaders();
        $candidates = [
            self::TRANSPORT_HEADER => $headers->get(self::HEADER),
            self::TRANSPORT_URL => $request->getQueryParam('token'),
            self::TRANSPORT_BEARER => self::bearerCredentials($headers->get('Authorization')),
        ];
        foreach ($candidates as $transport => $value) {
            if (is_string($value) && $value !== '') {
                return ['token' => $value, 'transport' => $transport];
            }
        }

        return null;
    }

    /**
     * The credentials of an Authorization header of the Bearer scheme (the
     * scheme name is case-insensitive); null for any other value.
     */
    private static function bearerCredentials(#[\SensitiveParameter] mixed $authorization): ?string
    {
        if (!is_string($authorization) || preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
