<?php

declare(strict_types=1);

namespace app\components;

/**
 * The launch overrides the web launch form may set: extra_vars, limit,
 * verbosity and check_mode from overrides[], survey answers from survey[].
 *
 * Nothing else a request posts reaches JobLaunchService: server-only launch
 * data (LaunchContext) cannot be posted. A limit and a verbosity go on as
 * posted whenever they are set, as POST /api/v1/jobs and the job trigger
 * hand them on, for JobLaunchInput to check before the launch: it refuses a
 * list or an object, also an empty one, and the form shows why, so that a
 * forged overrides[limit][]=x cannot fail the launch with "Array to string
 * conversion". A list as check_mode is dropped: the launch would take one
 * that is not empty for true.
 *
 * Regression: a list as the limit or the verbosity was dropped, also one of
 * an empty value (overrides[verbosity][]=), and the job launched with the
 * template's limit and verbosity, where the API and the job trigger refuse
 * such a list.
 */
final class WebLaunchOverrides
{
    /** The single-value fields of the form's Advanced Overrides. */
    private const SINGLE_VALUE_FIELDS = ['limit', 'verbosity', 'check_mode'];

    /** The single-value fields that JobLaunchInput checks before the launch. */
    private const CHECKED_FIELDS = ['limit', 'verbosity'];

    /**
     * @param mixed $overrides the posted overrides[]
     * @param mixed $survey the posted survey[]
     * @return array<string, mixed>
     */
    public static function fromRequest(#[\SensitiveParameter] mixed $overrides, #[\SensitiveParameter] mixed $survey): array
    {
        $result = [];
        if (is_array($overrides)) {
            $extraVars = $overrides['extra_vars'] ?? null;
            // JSON text from the form, or the vars posted as overrides[extra_vars][<key>].
            if (is_string($extraVars) || is_array($extraVars)) {
                $result['extra_vars'] = $extraVars;
            }
            foreach (self::SINGLE_VALUE_FIELDS as $field) {
                if (self::goesOn($field, $overrides[$field] ?? null)) {
                    $result[$field] = $overrides[$field];
                }
            }
        }
        if (is_array($survey) && $survey !== []) {
            $result['survey'] = $survey;
        }

        return $result;
    }

    /**
     * Whether the posted $value of the single-value field $field goes on to
     * the launch: that of a field JobLaunchInput checks whenever it is set,
     * also a list or an object, for the check to refuse; that of check_mode
     * only when it is a single value.
     */
    private static function goesOn(string $field, mixed $value): bool
    {
        return in_array($field, self::CHECKED_FIELDS, true) ? $value !== null : is_scalar($value);
    }
}
