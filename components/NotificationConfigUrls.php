<?php

declare(strict_types=1);

namespace app\components;

use app\models\NotificationTemplate;

/**
 * The URLs of a notification template's channel config
 * (the urlSchemes of its channel's definition, ChannelDefinition): each must be an absolute URL with
 * a host whose scheme its key allows, as the outbound HTTP client sends only
 * to such URLs (OutboundHttpClient::schemeOf()).
 */
final class NotificationConfigUrls
{
    /**
     * The errors of the URLs in $config whose scheme their key does not
     * allow: NotificationTemplate::URL_SCHEME_REFUSED, or HTTPS_REQUIRED for
     * a key that allows https only. A value that is no string is refused
     * too. A key that $config does not hold, or holds empty, is left to the
     * required check.
     *
     * @param array<int|string, mixed> $config the channel config, its secrets included
     * @param array<string, list<string>> $urlKeys each URL key with the schemes it allows
     * @return list<string>
     */
    public static function refusals(#[\SensitiveParameter] array $config, array $urlKeys): array
    {
        $errors = [];
        foreach ($urlKeys as $key => $schemes) {
            $url = $config[$key] ?? null;
            if ($url === null || $url === '') {
                continue;
            }
            $scheme = is_string($url) ? OutboundHttpClient::schemeOf($url) : null;
            if (!in_array($scheme, $schemes, true)) {
                $refusal = $schemes === ['https'] ? NotificationTemplate::HTTPS_REQUIRED : NotificationTemplate::URL_SCHEME_REFUSED;
                $errors[] = sprintf($refusal, $key);
            }
        }

        return $errors;
    }
}
