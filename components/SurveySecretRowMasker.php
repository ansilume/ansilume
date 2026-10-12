<?php

declare(strict_types=1);

namespace app\components;

/**
 * The row-level step of the survey password backfill ({@see SurveySecretBackfill}):
 * what one stored job, schedule or job template row looks like once the
 * password answers that older releases stored in plaintext are moved into
 * the sealed set. Pure: it reads the row it is given and touches no database.
 *
 * A value is a password answer when its top-level key is a password field
 * name of the row's job template, usable or not, compared as text: releases
 * up to 2.8.1 stored answers under the exact name. Values that already are
 * SurveySecrets::PLACEHOLDER are left alone, so a row the backfill (or a
 * launch of this release) wrote comes back unchanged and running the
 * backfill again changes nothing. Masked JSON keeps its top-level shape: an
 * object whose keys are "0" to "n-1" stays an object.
 *
 * Every result has the same shape: a status, the new column values for an
 * UPDATE of the row by its ID (only for STATUS_CHANGED), and whether the
 * stored command had to be cleared.
 */
final class SurveySecretRowMasker
{
    /** Bytes a TEXT column holds (extra_vars, runner_payload, execution_command, survey_fields). */
    public const TEXT_MAX_BYTES = 65535;

    /** Nothing to move: no plaintext answer in the row. */
    public const STATUS_UNCHANGED = 'unchanged';
    /** The answers are moved and masked: write the columns. */
    public const STATUS_CHANGED = 'changed';
    /** The row's existing sealed answers cannot be opened; the row is left as it is. */
    public const STATUS_UNDECRYPTABLE = 'undecryptable';
    /** A masked column would not fit its TEXT column; the row is left as it is. */
    public const STATUS_TOO_LONG = 'too_long';

    /** How a launch encodes job.extra_vars and the runner payload. */
    private const JOB_FLAGS = JSON_UNESCAPED_UNICODE;

    /** How the forms write schedule extra vars and survey definitions. */
    private const FORM_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The argument that precedes the extra vars JSON in a stored command. */
    private const EXTRA_VARS_ARGUMENT = '--extra-vars ';

    /**
     * A job row: the answers in job.extra_vars and in the extra vars of the
     * runner payload (the payload's value wins: it is what the runner gets)
     * are added to the sealed set and replaced by the placeholder, and the
     * stored command gets the masked extra vars in place of the old ones, or
     * NULL when it does not contain them as stored.
     *
     * @param array<mixed> $row extra_vars, runner_payload, execution_command and secret_extra_vars
     * @param list<string> $names every password field name of the job's template, usable or not
     * @return array{status: string, columns: array<string, string|null>, command_cleared: bool}
     * @throws SurveySecretException when the answers cannot be sealed
     * @throws \UnexpectedValueException when masked extra vars cannot be encoded again
     */
    public function maskJob(#[\SensitiveParameter] array $row, array $names): array
    {
        $varsJson = self::stringOrNull($row['extra_vars'] ?? null);
        $vars = self::decode($varsJson);
        $payloadJson = self::stringOrNull($row['runner_payload'] ?? null);
        $payload = self::decode($payloadJson);
        $payloadVarsJson = self::stringOrNull($payload['extra_vars'] ?? null);
        $payloadVars = self::decode($payloadVarsJson);

        $secret = self::secretsToSeal(array_replace(self::plaintext($vars, $names), self::plaintext($payloadVars, $names)), $row);
        if (!is_array($secret)) {
            return self::result($secret);
        }
        $keys = array_map('strval', array_keys($secret));
        $maskedPayloadVars = self::masked($payloadVarsJson, $payloadVars, $keys, self::JOB_FLAGS);
        if ($maskedPayloadVars !== $payloadVarsJson) {
            $payload['extra_vars'] = $maskedPayloadVars;
            $payloadJson = self::encode($payload, self::JOB_FLAGS, $payloadJson);
        }
        $command = self::maskedCommand(self::stringOrNull($row['execution_command'] ?? null), $payloadVarsJson, $maskedPayloadVars);
        $columns = [
            'extra_vars' => self::masked($varsJson, $vars, $keys, self::JOB_FLAGS),
            'runner_payload' => $payloadJson,
            'execution_command' => $command['command'],
        ];

        return self::sealedResult($columns, $secret, $command['cleared']);
    }

    /**
     * A schedule row: the answers in schedule.extra_vars are added to the
     * sealed set and replaced by the placeholder.
     *
     * @param array<mixed> $row extra_vars and secret_extra_vars
     * @param list<string> $names every password field name of the schedule's job template, usable or not
     * @return array{status: string, columns: array<string, string|null>, command_cleared: bool}
     * @throws SurveySecretException when the answers cannot be sealed
     * @throws \UnexpectedValueException when the masked extra vars cannot be encoded again
     */
    public function maskSchedule(#[\SensitiveParameter] array $row, array $names): array
    {
        $json = self::stringOrNull($row['extra_vars'] ?? null);
        $vars = self::decode($json);
        $secret = self::secretsToSeal(self::plaintext($vars, $names), $row);
        if (!is_array($secret)) {
            return self::result($secret);
        }
        $keys = array_map('strval', array_keys($secret));

        return self::sealedResult(['extra_vars' => self::masked($json, $vars, $keys, self::FORM_FLAGS)], $secret, false);
    }

    /**
     * A job template row: its survey without the default of any password
     * field. Launches never used those defaults; they were plaintext copies
     * of a password.
     *
     * @param array<mixed> $row survey_fields
     * @return array{status: string, columns: array<string, string|null>, command_cleared: bool}
     * @throws \UnexpectedValueException when the survey cannot be encoded again
     */
    public function maskSurvey(#[\SensitiveParameter] array $row): array
    {
        $json = self::withoutPasswordDefaults(self::stringOrNull($row['survey_fields'] ?? null));
        if ($json === null) {
            return self::result(self::STATUS_UNCHANGED);
        }
        $columns = ['survey_fields' => $json];

        return self::fits($columns) ? self::result(self::STATUS_CHANGED, $columns) : self::result(self::STATUS_TOO_LONG);
    }

    /**
     * Whether a password field of the survey definition $json has a default:
     * a value other than null or ''. Never throws.
     */
    public static function hasPasswordDefault(#[\SensitiveParameter] ?string $json): bool
    {
        return self::clearPasswordDefaults(self::decode($json))['changed'];
    }

    /**
     * The survey definition $json without the default of its password
     * fields (each becomes ''), as the survey editor writes it; null when no
     * password field has a default, or $json is not a JSON list or map.
     *
     * @throws \UnexpectedValueException when the survey cannot be encoded again
     */
    public static function withoutPasswordDefaults(#[\SensitiveParameter] ?string $json): ?string
    {
        $cleared = self::clearPasswordDefaults(self::decode($json));

        return $cleared['changed'] ? self::encode($cleared['entries'], self::FORM_FLAGS, $json) : null;
    }

    /**
     * @param array<int|string, mixed> $entries
     * @return array{entries: array<int|string, mixed>, changed: bool}
     */
    private static function clearPasswordDefaults(#[\SensitiveParameter] array $entries): array
    {
        $changed = false;
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === SurveyField::TYPE_PASSWORD && ($entry['default'] ?? '') !== '') {
                $entry['default'] = '';
                $entries[$index] = $entry;
                $changed = true;
            }
        }

        return ['entries' => $entries, 'changed' => $changed];
    }

    /**
     * The sealed set the row gets: its existing answers plus $found. An
     * existing answer wins, as the claim gives the runner the sealed value.
     * A status instead when there is nothing to seal, or the existing answers
     * cannot be opened (they must never be dropped).
     *
     * @param array<string, mixed> $found
     * @param array<mixed> $row
     * @return array<string, mixed>|string
     */
    private static function secretsToSeal(#[\SensitiveParameter] array $found, #[\SensitiveParameter] array $row): array|string
    {
        if ($found === []) {
            return self::STATUS_UNCHANGED;
        }
        try {
            $existing = SurveySecrets::open(self::stringOrNull($row['secret_extra_vars'] ?? null));
        } catch (SurveySecretException) {
            return self::STATUS_UNDECRYPTABLE;
        }

        return $existing + $found;
    }

    /**
     * The changed columns plus the sealed answers, or STATUS_TOO_LONG when a
     * masked column would not fit (checked before anything is sealed).
     *
     * @param array<string, string|null> $columns
     * @param array<string, mixed> $secret
     * @return array{status: string, columns: array<string, string|null>, command_cleared: bool}
     * @throws SurveySecretException
     */
    private static function sealedResult(array $columns, #[\SensitiveParameter] array $secret, bool $commandCleared): array
    {
        if (!self::fits($columns)) {
            return self::result(self::STATUS_TOO_LONG);
        }
        $columns['secret_extra_vars'] = SurveySecrets::seal($secret);

        return self::result(self::STATUS_CHANGED, $columns, $commandCleared);
    }

    /**
     * @param array<string, string|null> $columns
     * @return array{status: string, columns: array<string, string|null>, command_cleared: bool}
     */
    private static function result(string $status, array $columns = [], bool $commandCleared = false): array
    {
        return ['status' => $status, 'columns' => $columns, 'command_cleared' => $commandCleared];
    }

    /**
     * The values of $names in $vars that are not the placeholder yet.
     *
     * @param array<int|string, mixed> $vars
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function plaintext(#[\SensitiveParameter] array $vars, array $names): array
    {
        $found = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $vars) && $vars[$name] !== SurveySecrets::PLACEHOLDER) {
                $found[$name] = $vars[$name];
            }
        }

        return $found;
    }

    /**
     * $json with the placeholder as the value of each of $keys it has,
     * encoded again in its top-level shape; $json itself when no value
     * changes, so a row keeps its exact bytes unless an answer is masked.
     *
     * @param array<int|string, mixed> $vars $json decoded
     * @param list<string> $keys
     * @throws \UnexpectedValueException
     */
    private static function masked(#[\SensitiveParameter] ?string $json, #[\SensitiveParameter] array $vars, array $keys, int $flags): ?string
    {
        $changed = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $vars) && $vars[$key] !== SurveySecrets::PLACEHOLDER) {
                $vars[$key] = SurveySecrets::PLACEHOLDER;
                $changed = true;
            }
        }

        return $changed ? self::encode($vars, $flags, $json) : $json;
    }

    /**
     * The stored command with the masked extra vars in place of the old
     * ones. A claim stored `implode(' ', argv)`, with the runner payload's
     * extra vars right after --extra-vars; a command that does not contain
     * them as stored (an older format, a hand-written one) cannot be
     * repaired and is cleared instead.
     *
     * @return array{command: string|null, cleared: bool}
     */
    private static function maskedCommand(#[\SensitiveParameter] ?string $command, #[\SensitiveParameter] ?string $old, ?string $new): array
    {
        if ($command === null || $command === '' || $old === null || $old === $new) {
            return ['command' => $command, 'cleared' => false];
        }
        $needle = self::EXTRA_VARS_ARGUMENT . $old;
        if (!str_contains($command, $needle)) {
            return ['command' => null, 'cleared' => true];
        }

        return ['command' => str_replace($needle, self::EXTRA_VARS_ARGUMENT . $new, $command), 'cleared' => false];
    }

    /**
     * Whether every column fits a TEXT column, in bytes: MariaDB rejects
     * longer values in strict mode, and truncates them (into invalid JSON)
     * otherwise.
     *
     * @param array<string, string|null> $columns
     */
    private static function fits(array $columns): bool
    {
        foreach ($columns as $value) {
            if ($value !== null && strlen($value) > self::TEXT_MAX_BYTES) {
                return false;
            }
        }

        return true;
    }

    /**
     * $json decoded as an array; [] for null, invalid JSON and JSON that is
     * not a list or an object.
     *
     * @return array<int|string, mixed>
     */
    private static function decode(#[\SensitiveParameter] ?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * $value, decoded from $json, encoded again in the top-level shape of
     * $json ({@see SurveySecrets::reencode()}): a password field may be
     * named "0", and the object {"0": ...} must not come back as a list.
     *
     * No JSON_THROW_ON_ERROR: its exception would carry the values in its
     * trace. A decoded document fails to encode again only when it holds a
     * number JSON allows but PHP cannot write back, such as 1e999.
     *
     * @param array<int|string, mixed> $value
     * @throws \UnexpectedValueException
     */
    private static function encode(#[\SensitiveParameter] array $value, int $flags, #[\SensitiveParameter] ?string $json): string
    {
        $encoded = SurveySecrets::reencode($json, $value, $flags);
        if ($encoded === false) {
            throw new \UnexpectedValueException('The masked JSON cannot be encoded.');
        }

        return $encoded;
    }

    private static function stringOrNull(#[\SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
