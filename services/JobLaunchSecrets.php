<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\JobTemplate;

/**
 * The launch step for password answers, applied to the merged extra vars
 * (template, then survey answers, then explicit extra vars; see
 * JobLaunchService::mergeExtraVars()).
 *
 * The step first refuses a template whose password field has a name that
 * cannot carry an answer ({@see SurveyDefinition::isUsablePasswordName()}):
 * whether a template can be launched is all that usability decides. Stored
 * values under such a name are secret all the same; schedules and the
 * backfill seal them ({@see SurveyDefinition::secretNames()}).
 *
 * Past that refusal, every password field name of the template is usable.
 * A value is secret when its top-level key is one of them, whatever the
 * source of the value (survey form, Advanced Overrides, API, trigger,
 * schedule, template extra vars), or a key of the answers a LaunchContext
 * carries.
 *
 * The step also refuses the placeholder sent as a password answer (a client
 * that copied a job's extra vars would otherwise launch with "$encrypted$"
 * as the password), and answers JSON cannot hold. Carried answers are
 * exempt from the placeholder check and win over every other source, as
 * they are the values the earlier launch used.
 */
final class JobLaunchSecrets
{
    /** What the messages of {@see encode()} call the values. */
    public const EXTRA_VARS = 'extra vars';
    public const PASSWORD_ANSWERS = 'password answers';

    private const UNUSABLE_NAME = 'Survey field %s has a name that cannot be used for a password answer; '
        . 'rename it in the survey before launching.';

    /**
     * A password answer sent as the placeholder, by a job launch or a
     * workflow launch (WorkflowRunVars). sprintf(): the field name (quoted),
     * the placeholder.
     */
    public const PLACEHOLDER_SENT = 'The value of %s is the placeholder %s; send the real value.';

    /**
     * The public extra vars, with the placeholder for every password answer,
     * and the sealed answers (null when there are none). A template with an
     * unusable password field name is refused first, so the usable names
     * used after that are all its password field names.
     *
     * @param array<int|string, mixed> $merged
     * @return array{public: array<int|string, mixed>, sealed: string|null}
     * @throws JobLaunchRejectedException
     * @throws SurveySecretException when the answers cannot be encrypted (APP_SECRET_KEY)
     */
    public function apply(JobTemplate $template, #[\SensitiveParameter] array $merged, ?LaunchContext $context): array
    {
        $fields = JobTemplate::passwordFieldsFromJson($template->survey_fields);
        if ($fields['unusable'] !== []) {
            throw new JobLaunchRejectedException(sprintf(self::UNUSABLE_NAME, SurveyDefinition::quote($fields['unusable'][0])));
        }
        $carried = $context === null ? [] : $context->carriedSecrets;
        foreach ($fields['usable'] as $name) {
            if (!array_key_exists($name, $carried) && ($merged[$name] ?? null) === SurveySecrets::PLACEHOLDER) {
                throw new JobLaunchRejectedException(sprintf(self::PLACEHOLDER_SENT, SurveyDefinition::quote($name), SurveySecrets::PLACEHOLDER));
            }
        }
        // The carried answers replace whatever the other sources supplied.
        $vars = SurveySecrets::reveal($merged, $carried);
        $secretKeys = array_values(array_unique(array_merge($fields['usable'], array_map('strval', array_keys($carried)))));
        $split = SurveySecrets::split($vars, $secretKeys);
        // Checked before sealing: answers JSON cannot hold are the caller's to fix, not an error.
        self::encode($split['secret'], self::PASSWORD_ANSWERS);

        return ['public' => $split['public'], 'sealed' => SurveySecrets::seal($split['secret'])];
    }

    /**
     * $vars as the job stores them: JSON with the flags of the inline
     * --extra-vars argument, which seal() uses too.
     *
     * @param array<int|string, mixed> $vars
     * @param string $subject self::EXTRA_VARS or self::PASSWORD_ANSWERS, for the message
     * @throws JobLaunchRejectedException when JSON cannot hold them: a number
     *     too large for JSON (1e999 decodes as INF), or text that is not UTF-8
     */
    public static function encode(#[\SensitiveParameter] array $vars, string $subject): string
    {
        // No JSON_THROW_ON_ERROR: its exception would carry the values in its trace.
        $json = json_encode($vars, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new JobLaunchRejectedException(SurveySecrets::unencodable($subject, json_last_error()));
        }

        return $json;
    }
}
