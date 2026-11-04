<?php

declare(strict_types=1);

namespace app\services\notification;

/**
 * The config of a webhook template beside its url and headers
 * (docs/notifications.md):
 * - signing_secret, optional: the key of the signature of each request
 *   (WebhookChannel::SIGNATURE_HEADER), a channel secret that goes along
 *   with the url (NotificationConfigSecrets): text of at most
 *   SIGNING_SECRET_MAX_LENGTH characters;
 * - payload, optional: template (the default, and what every template
 *   stored before the setting sends: its subject, body and variables) or
 *   event (NotificationEventPayload);
 * - headers: an object of header names (HTTP tokens) and values of one
 *   line, a number or null, as customHeaders() sends them; never one that
 *   the channel sets itself (WebhookChannel::RESERVED_HEADERS), on which a
 *   receiver relies.
 *
 * The template's rules check it when it is saved (refusals(): the
 * channel's configRules, ChannelRegistry::BUILT_IN); the channel
 * reads it when it sends (payloadOf(), signingSecretOf(), customHeaders()),
 * also a config stored before a rule existed. Every method takes the
 * config with its secrets, marked #[\SensitiveParameter].
 */
final class WebhookConfig implements ChannelConfigRules
{
    /** The template's subject, body and variables: the default. */
    public const PAYLOAD_TEMPLATE = 'template';

    /** The event payload (NotificationEventPayload). */
    public const PAYLOAD_EVENT = 'event';

    public const PAYLOADS = [self::PAYLOAD_TEMPLATE, self::PAYLOAD_EVENT];

    /** The longest signing secret, in characters. */
    public const SIGNING_SECRET_MAX_LENGTH = 256;

    /** A payload that is neither template nor event. */
    public const PAYLOAD_REFUSED = 'payload must be template or event.';

    /** A signing secret that is no text, or too long. */
    public const SIGNING_SECRET_FORM = 'signing_secret must be text of at most 256 characters.';

    /** A header of the config that the channel sets itself; sprintf(): its name, as given. */
    public const HEADER_RESERVED = 'headers.%s is set by Ansilume; leave it out.';

    /** Headers that are no object of names and values, such as text or a list of lines. */
    public const HEADERS_FORM = 'headers must be an object of header names and values.';

    /** A header name that is no HTTP token; sprintf(): the name, as given. */
    public const HEADER_NAME_REFUSED = 'headers.%s is no header name: leave out spaces, quotes, colons and the other separators.';

    /** A header value that is a list, an object, or text of more than one line; sprintf(): the name. */
    public const HEADER_VALUE_REFUSED = 'headers.%s must be one line of text.';

    /** An HTTP header name: a token of RFC 9110. */
    private const HEADER_NAME_PATTERN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /**
     * What is wrong with $config, the config of a webhook template; [] for
     * nothing. A key that is not set (missing, null or '') is the default.
     *
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    public static function refusals(#[\SensitiveParameter] array $config): array
    {
        $errors = [];
        $payload = $config['payload'] ?? null;
        if (!self::isUnset($payload) && !in_array($payload, self::PAYLOADS, true)) {
            $errors[] = self::PAYLOAD_REFUSED;
        }
        if (!self::isUnset($config['signing_secret'] ?? null) && self::signingSecretOf($config) === null) {
            $errors[] = self::SIGNING_SECRET_FORM;
        }
        foreach (self::reservedNames($config) as $name) {
            $errors[] = sprintf(self::HEADER_RESERVED, $name);
        }

        return [...$errors, ...self::headerRefusals($config['headers'] ?? null)];
    }

    /**
     * What is wrong with $headers beside a name the channel sets itself:
     * nothing when they are not set or empty; otherwise they are an object
     * whose names are HTTP tokens and whose values are one line of text, a
     * number or null. Up to 2.14.0 the API stored headers of any form, and
     * the channel sent none of them, or a header named 0 for each line of a
     * list.
     *
     * @return list<string>
     */
    private static function headerRefusals(#[\SensitiveParameter] mixed $headers): array
    {
        if (self::isUnset($headers) || $headers === []) {
            return [];
        }
        if (!is_array($headers) || array_is_list($headers)) {
            return [self::HEADERS_FORM];
        }
        $errors = [];
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            if (self::isReserved($name)) {
                continue;
            }
            if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
                $errors[] = sprintf(self::HEADER_NAME_REFUSED, $name);
            } elseif (!self::isHeaderValue($value)) {
                $errors[] = sprintf(self::HEADER_VALUE_REFUSED, $name);
            }
        }

        return $errors;
    }

    /** Whether $value is a header value the channel sends: one line of text, a number, true or false, or null. */
    private static function isHeaderValue(#[\SensitiveParameter] mixed $value): bool
    {
        return $value === null || (is_scalar($value) && strpbrk((string)$value, "\r\n\0") === false);
    }

    /**
     * The payload $config sends: event only where it says so, else
     * template.
     *
     * @param array<int|string, mixed> $config
     */
    public static function payloadOf(#[\SensitiveParameter] array $config): string
    {
        return ($config['payload'] ?? null) === self::PAYLOAD_EVENT ? self::PAYLOAD_EVENT : self::PAYLOAD_TEMPLATE;
    }

    /**
     * The signing secret of $config; null without one, and for one the
     * rules refuse.
     *
     * @param array<int|string, mixed> $config
     */
    public static function signingSecretOf(#[\SensitiveParameter] array $config): ?string
    {
        $secret = $config['signing_secret'] ?? null;
        if (!is_string($secret) || $secret === '' || mb_strlen($secret) > self::SIGNING_SECRET_MAX_LENGTH) {
            return null;
        }

        return $secret;
    }

    /**
     * The headers of $config as "Name: value" lines, without those the
     * channel sets itself and those whose value is a list or an object;
     * none for headers that are no list or object.
     *
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    public static function customHeaders(#[\SensitiveParameter] array $config): array
    {
        $lines = [];
        foreach (self::headersOf($config) as $name => $value) {
            if (!self::isReserved((string)$name) && (is_scalar($value) || $value === null)) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }

    /**
     * The names of the headers of $config that the channel sets itself, as
     * given.
     *
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    private static function reservedNames(#[\SensitiveParameter] array $config): array
    {
        $names = [];
        foreach (array_keys(self::headersOf($config)) as $name) {
            if (self::isReserved((string)$name)) {
                $names[] = (string)$name;
            }
        }

        return $names;
    }

    /**
     * @param array<int|string, mixed> $config
     * @return array<int|string, mixed>
     */
    private static function headersOf(#[\SensitiveParameter] array $config): array
    {
        $headers = $config['headers'] ?? null;

        return is_array($headers) ? $headers : [];
    }

    /** Whether the channel sets the header $name itself, whatever its case and the spaces around it. */
    private static function isReserved(string $name): bool
    {
        return in_array(strtolower(trim($name)), array_map('strtolower', WebhookChannel::RESERVED_HEADERS), true);
    }

    private static function isUnset(#[\SensitiveParameter] mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
