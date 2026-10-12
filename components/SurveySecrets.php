<?php

declare(strict_types=1);

namespace app\components;

/**
 * Password answers of a launch: the values of extra vars whose top-level key
 * is a password-type survey field of the job's template.
 *
 * They are stored only sealed (encrypted with {@see SecretCipher}); job and
 * schedule extra vars, the runner payload, the stored command, the UI and the
 * API show PLACEHOLDER instead. The values keep their JSON type: an API or
 * trigger caller may send a number, a list or an object.
 *
 * Sealing encodes with the flags of the inline --extra-vars JSON
 * (JSON_UNESCAPED_UNICODE), so a runner that reads the answers from a file
 * gets the values a runner that reads them from the command line gets.
 */
final class SurveySecrets
{
    /** What stands for a password answer everywhere outside the sealed set. */
    public const PLACEHOLDER = '$encrypted$';

    private const ENCRYPT_FAILED = 'The password answers cannot be encrypted. Check APP_SECRET_KEY.';
    private const OPEN_FAILED = 'The encrypted password answers cannot be decrypted (was APP_SECRET_KEY changed?).';

    /**
     * Split $vars into the public extra vars, with PLACEHOLDER for every
     * secret value and the key order kept, and the secret values by key, as
     * given (any JSON type). Keys of $secretKeys that $vars lacks are left out.
     *
     * @param array<int|string, mixed> $vars
     * @param list<string> $secretKeys
     * @return array{public: array<int|string, mixed>, secret: array<string, mixed>}
     */
    public static function split(#[\SensitiveParameter] array $vars, array $secretKeys): array
    {
        $public = [];
        $secret = [];
        foreach ($vars as $key => $value) {
            if (in_array((string)$key, $secretKeys, true)) {
                $secret[(string)$key] = $value;
                $value = self::PLACEHOLDER;
            }
            $public[$key] = $value;
        }

        return ['public' => $public, 'secret' => $secret];
    }

    /**
     * The sealed form of $secret, or null when there is nothing to seal.
     * Callers that can tell the user check first that the values can be
     * encoded (a launch refuses them with {@see unencodable()}'s message).
     *
     * @param array<string, mixed> $secret
     * @throws SurveySecretException when the values cannot be encoded or encrypted
     */
    public static function seal(#[\SensitiveParameter] array $secret): ?string
    {
        if ($secret === []) {
            return null;
        }
        // No JSON_THROW_ON_ERROR: its exception would carry the input in its trace.
        $json = json_encode($secret, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new SurveySecretException(self::unencodable('password answers', json_last_error()));
        }
        try {
            return (new SecretCipher())->encrypt($json);
        } catch (SecretCipherException $e) {
            throw new SurveySecretException(self::ENCRYPT_FAILED, 0, $e);
        }
    }

    /**
     * The values seal() sealed; [] for null or ''.
     *
     * @return array<string, mixed>
     * @throws SurveySecretException when the value cannot be decrypted or decoded
     */
    public static function open(?string $sealed): array
    {
        if ($sealed === null || $sealed === '') {
            return [];
        }
        try {
            $json = (new SecretCipher())->decrypt($sealed);
        } catch (SecretCipherException $e) {
            throw new SurveySecretException(self::OPEN_FAILED, 0, $e);
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new SurveySecretException(self::OPEN_FAILED);
        }
        $secret = [];
        foreach ($decoded as $key => $value) {
            $secret[(string)$key] = $value;
        }

        return $secret;
    }

    /**
     * $public with the secret values put back: in place of their
     * placeholders, or appended when $public lacks the key.
     *
     * @param array<int|string, mixed> $public
     * @param array<string, mixed> $secret
     * @return array<int|string, mixed>
     */
    public static function reveal(array $public, #[\SensitiveParameter] array $secret): array
    {
        foreach ($secret as $key => $value) {
            $public[$key] = $value;
        }

        return $public;
    }

    /**
     * The message for $subject ("extra vars", "password answers") that
     * json_encode() could not write, by its json_last_error(): JSON input can
     * hold a number PHP reads as INF (1e999), and a web form answer can hold
     * text that is not UTF-8. Never the values.
     */
    public static function unencodable(string $subject, int $jsonError): string
    {
        return match ($jsonError) {
            JSON_ERROR_INF_OR_NAN => "The {$subject} cannot be stored: they contain a number too large for JSON.",
            JSON_ERROR_UTF8 => "The {$subject} cannot be stored: they contain characters that are not valid UTF-8.",
            default => "The {$subject} cannot be stored as JSON.",
        };
    }

    /**
     * $vars without the entries of $keys.
     *
     * @param array<int|string, mixed> $vars
     * @param list<int|string> $keys
     * @return array<int|string, mixed>
     */
    public static function withoutKeys(array $vars, array $keys): array
    {
        return array_diff_key($vars, array_flip(array_map('strval', $keys)));
    }

    /**
     * $vars, decoded from the JSON text $json (json_decode(..., true)), as
     * JSON again with $flags, in the top-level shape of $json: an object
     * stays an object, also when its keys are exactly "0" to "n-1", which
     * json_decode() turns into the int keys of an array that json_encode()
     * writes as a list; a list stays a list. For extra vars whose answers
     * were replaced by the placeholder: a password field may be named "0".
     *
     * @param array<int|string, mixed> $vars
     * @return string|false false when JSON cannot hold a value, as for json_encode()
     */
    public static function reencode(#[\SensitiveParameter] ?string $json, array $vars, int $flags): string|false
    {
        $isList = str_starts_with(ltrim((string)$json, " \t\n\r"), '[');

        // Only a list is cast: its keys are all ints, and a cast drops keys that begin with a NUL byte.
        return json_encode(!$isList && array_is_list($vars) ? (object)$vars : $vars, $flags);
    }
}
