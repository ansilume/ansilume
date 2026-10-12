<?php

declare(strict_types=1);

namespace app\components;

/**
 * The launch overrides the web launch form may set: extra_vars, limit,
 * verbosity and check_mode from overrides[], survey answers from survey[].
 *
 * Nothing else a request posts reaches JobLaunchService: server-only launch
 * data (LaunchContext) cannot be posted, and a list where the form sends a
 * single value (overrides[limit][]=x) is dropped instead of failing the
 * launch with "Array to string conversion".
 */
final class WebLaunchOverrides
{
    /** The single-value fields of the form's Advanced Overrides. */
    private const SCALAR_FIELDS = ['limit', 'verbosity', 'check_mode'];

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
            foreach (self::SCALAR_FIELDS as $field) {
                if (is_scalar($overrides[$field] ?? null)) {
                    $result[$field] = $overrides[$field];
                }
            }
        }
        if (is_array($survey) && $survey !== []) {
            $result['survey'] = $survey;
        }

        return $result;
    }
}
