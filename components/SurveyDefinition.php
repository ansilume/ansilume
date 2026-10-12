<?php

declare(strict_types=1);

namespace app\components;

/**
 * Survey definitions as job_template.survey_fields stores them: JSON, a list
 * (or a map) of field objects.
 *
 * Everything here reads the raw decoded JSON and never casts a value that
 * may be a list or an object, so a malformed definition, which the API could
 * store before definitions were checked, gives an answer or a validation
 * message instead of an error.
 */
final class SurveyDefinition
{
    /**
     * Names a new or renamed survey field may get: variable names, as the
     * launch form posts each answer as survey[<name>] and Ansible reads it as
     * a variable.
     *
     * D: without it, $ also matches before a final line break, so
     * "db_password\n" passed. web/openapi.yaml has the same pattern without
     * the delimiters and D, as ECMA-262's $ has no such exception.
     */
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    public const PASSWORD_DEFAULT = 'A password field cannot have a default value.';

    private const NOT_A_LIST = 'Survey fields must be a JSON list of field definitions.';
    private const NOT_AN_OBJECT = 'Survey field %d must be a JSON object with a name and a type.';
    private const INVALID_NAME = 'Survey field name %s is not a valid variable name: use letters, digits and underscores, '
        . 'and start with a letter or an underscore.';
    private const DUPLICATE_NAME = 'Survey field name %s is used more than once.';
    private const UNKNOWN_TYPE = 'Survey field %s has an unknown type %s; use one of: %s.';
    private const DEFAULT_NOT_A_VALUE = 'The default of survey field %s must be a single value, not a list or an object.';

    /**
     * The entries of a definition in order, as decoded: [] for null, '' and
     * the JSON null; null when $json is not a JSON list or map.
     *
     * @return list<mixed>|null
     */
    public static function entries(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        return $decoded === null && json_last_error() === JSON_ERROR_NONE ? [] : null;
    }

    /**
     * The names of the password fields (type exactly 'password').
     *
     * A name is unusable when it is not text or cannot carry an answer
     * ({@see isUsablePasswordName()}): the launch refuses its template, and
     * m000081 and the scrub list it. Unusable names are given as text, as
     * stored; a list or an object as its JSON. Usability decides only
     * whether the template can be launched: every name is a secret key
     * ({@see secretNames()}).
     *
     * @return array{usable: list<string>, unusable: list<string>}
     */
    public static function passwordFields(?string $json): array
    {
        $usable = [];
        $unusable = [];
        foreach (self::entries($json) ?? [] as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? null) !== SurveyField::TYPE_PASSWORD) {
                continue;
            }
            $name = $entry['name'] ?? '';
            if (is_string($name) && self::isUsablePasswordName($name)) {
                $usable[] = $name;
            } else {
                $unusable[] = is_scalar($name) ? (string)$name : self::quote($name);
            }
        }

        return ['usable' => array_values(array_unique($usable)), 'unusable' => array_values(array_unique($unusable))];
    }

    /**
     * Every password field name, usable or not, as passwordFields() gives
     * it: the top-level extra vars keys whose values are password answers.
     * Wherever answers are stored, a value under one of these exact keys
     * (compared as text, so "0" is the key 0 of a decoded array) is sealed
     * and shown as the placeholder: by m000081 and the scrub of finished
     * jobs, at a schedule save and at a scheduled run, and on a schedule's
     * page, edit form and API. Releases up to 2.8.1 stored answers under the
     * exact name, also under one that cannot carry an answer now; only the
     * launch refuses such a name ({@see isUsablePasswordName()}). Answers
     * stored under another spelling of a name are not found.
     *
     * @return list<string>
     */
    public static function secretNames(?string $json): array
    {
        $fields = self::passwordFields($json);

        // A name that is a JSON object is listed as its JSON, which can be a usable text name too.
        return array_values(array_unique(array_merge($fields['usable'], $fields['unusable'])));
    }

    /**
     * A comparable form of a definition: each field as the survey editor
     * shows and saves it (label, options and hint trimmed, the label
     * defaulting to the name, missing values filled in), other entries as
     * they are; null when $json is not a JSON list or map. Definitions with
     * the same form are the same survey, however the JSON is written.
     *
     * Names stay as stored, untrimmed: "db_password\n" is another name than
     * "db_password", and a password answer is stored under the exact name.
     *
     * @return list<mixed>|null
     */
    public static function normalize(?string $json): ?array
    {
        $entries = self::entries($json);
        if ($entries === null) {
            return null;
        }

        return array_map(static fn (mixed $entry): mixed => is_array($entry) ? self::normalizeField($entry) : $entry, $entries);
    }

    /**
     * What is wrong with $json as a survey definition, when it is not the
     * same survey as $baseline (the stored definition, or the cloned
     * template's): [] when it is, so definitions saved before these rules
     * keep saving. The name rule applies only to fields whose name the
     * baseline does not have, compared as stored (untrimmed), and to every
     * password field whose name is not printable ASCII without a space at
     * either end ({@see isExact()}); names must be unique, types known, and
     * password fields have no default.
     *
     * @return list<string>
     */
    public static function problems(?string $json, ?string $baseline): array
    {
        $normal = self::normalize($json);
        $baselineNormal = self::normalize($baseline);
        if ($normal === $baselineNormal) {
            return [];
        }
        if ($normal === null) {
            return [self::NOT_A_LIST];
        }
        $known = self::namesOf($baselineNormal ?? []);
        $problems = [];
        foreach (self::entries($json) ?? [] as $index => $entry) {
            array_push($problems, ...self::entryProblems($entry, $index + 1, $known));
        }
        array_push($problems, ...self::duplicateProblems(array_map('trim', self::namesOf($normal))));

        return array_values(array_unique($problems));
    }

    /**
     * $value for a message: as JSON, so a name stands out and quotes in it
     * cannot break the text, with every character outside printable ASCII
     * escaped, so the operator sees what to rename: "db_password\u00a0" for
     * a trailing non-breaking space, "db_password\n" for a line break,
     * "p\u00e4ssword" for "pässword", and \ufffd for a byte that is not
     * UTF-8. HTML escaping is up to whoever renders the message.
     */
    public static function quote(mixed $value): string
    {
        $json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // json_encode() escapes every other character outside printable ASCII, but not DEL.
        return str_replace("\x7F", '\u007f', $json);
    }

    /**
     * Whether a password field named $name can carry an answer, so that the
     * answer arrives under that exact name and is kept secret:
     * - not empty, without [ or ], and not numeric: PHP turns survey[<name>]
     *   into another key, or into a number that merging extra vars
     *   renumbers;
     * - printable ASCII without a space at either end ({@see isExact()}):
     *   API, trigger and schedule callers send the variable name the
     *   playbook reads, browsers post a line break in a form field name as
     *   CRLF, a letter outside ASCII has more than one encoding, and an
     *   invisible character cannot be told apart from none, so the answer
     *   would arrive under another name, which is not kept secret.
     * Inner spaces and other printable ASCII are fine ("db password").
     * A template with a name that cannot carry an answer is not launched;
     * answers stored under that exact name are sealed all the same
     * ({@see secretNames()}).
     */
    public static function isUsablePasswordName(string $name): bool
    {
        return $name !== '' && strpbrk($name, '[]') === false && !is_numeric($name) && self::isExact($name);
    }

    /**
     * Whether a caller can send $name exactly as stored: every byte is
     * printable ASCII (0x20 to 0x7E) and no space is at either end. So any
     * byte from 0x80 on fails (a non-breaking or other Unicode space, U+FEFF,
     * U+200B, a C1 control character such as U+0085, a letter such as "ä",
     * text that is not UTF-8), and so does an ASCII control character
     * anywhere (a line break, a tab, NUL, DEL).
     */
    private static function isExact(string $name): bool
    {
        return preg_match('/^[\x20-\x7E]*$/D', $name) === 1 && $name === trim($name, ' ');
    }

    /**
     * @param array<mixed> $entry
     * @return array{name: string, label: string, type: string, required: bool, default: string, options: list<string>, hint: string}
     */
    private static function normalizeField(array $entry): array
    {
        $field = SurveyField::fromArray($entry);
        $label = trim($field->label);

        return [
            'name' => $field->name,
            'label' => $label !== '' ? $label : trim($field->name),
            'type' => $field->type,
            'required' => $field->required,
            'default' => $field->default,
            'options' => array_values(array_filter(array_map('trim', $field->options), static fn (string $option): bool => $option !== '')),
            'hint' => trim($field->hint),
        ];
    }

    /**
     * The names of the fields of a normalized definition, as stored.
     *
     * @param list<mixed> $normal
     * @return list<string>
     */
    private static function namesOf(array $normal): array
    {
        $names = [];
        foreach ($normal as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $names[] = $field['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<string> $known the names of the baseline's fields, as stored
     * @return list<string>
     */
    private static function entryProblems(mixed $entry, int $position, array $known): array
    {
        if (!is_array($entry)) {
            return [sprintf(self::NOT_AN_OBJECT, $position)];
        }
        $name = $entry['name'] ?? null;
        $isPassword = ($entry['type'] ?? null) === SurveyField::TYPE_PASSWORD;

        return array_values(array_filter([
            self::nameProblem($name, $known, $isPassword),
            self::typeProblem($name, $entry['type'] ?? SurveyField::TYPE_TEXT),
            self::defaultProblem($name, $entry),
        ]));
    }

    /**
     * @param list<string> $known
     */
    private static function nameProblem(mixed $name, array $known, bool $isPassword): ?string
    {
        if (self::keepsLegacyName($name, $known, $isPassword)) {
            return null;
        }
        if (is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1) {
            return null;
        }

        return sprintf(self::INVALID_NAME, self::quote($name ?? ''));
    }

    /**
     * Whether $name is a name of the baseline, compared as stored: such a
     * name keeps working, whatever it looks like. A password field's name
     * is kept only when it is printable ASCII without a space at either end
     * ({@see isExact()}), though: the answers of any other name would be
     * stored in plaintext, so a changed survey cannot keep it, add it
     * ("db_password\n" or "db_password\u00a0" for "db_password") or give it
     * to a field retyped to password.
     *
     * @param list<string> $known
     */
    private static function keepsLegacyName(mixed $name, array $known, bool $isPassword): bool
    {
        if (!is_scalar($name) || !in_array((string)$name, $known, true)) {
            return false;
        }

        return !$isPassword || self::isExact((string)$name);
    }

    private static function typeProblem(mixed $name, mixed $type): ?string
    {
        $types = SurveyField::types();
        if (is_string($type) && isset($types[$type])) {
            return null;
        }

        return sprintf(self::UNKNOWN_TYPE, self::quote($name ?? ''), self::quote($type), implode(', ', array_keys($types)));
    }

    /**
     * @param array<mixed> $entry
     */
    private static function defaultProblem(mixed $name, array $entry): ?string
    {
        $default = $entry['default'] ?? null;
        if ($default !== null && !is_scalar($default)) {
            return sprintf(self::DEFAULT_NOT_A_VALUE, self::quote($name ?? ''));
        }
        $isPassword = ($entry['type'] ?? null) === SurveyField::TYPE_PASSWORD;

        return $isPassword && $default !== null && $default !== '' ? self::PASSWORD_DEFAULT : null;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function duplicateProblems(array $names): array
    {
        $problems = [];
        foreach (array_count_values($names) as $name => $count) {
            // A missing name is reported by the name rule.
            if ($count > 1 && $name !== '') {
                $problems[] = sprintf(self::DUPLICATE_NAME, self::quote((string)$name));
            }
        }

        return $problems;
    }
}
