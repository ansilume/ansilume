<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * The extra vars of a workflow run.
 *
 * A launch (web, API, trigger) may send extra vars: a JSON object, decoded or
 * as JSON text. They are checked before the run is written, stored on the run
 * and reach every job step of it, not only the first. A value whose key is a
 * password field of any job step's job template is a password answer: the run
 * stores it sealed (workflow_job.secret_extra_vars) and the placeholder in its
 * place (workflow_job.extra_vars). Every job step's job gets the answers as
 * carried answers (LaunchContext::forWorkflow()), so they stay sealed in the
 * job of a step whose own template has no such password field too.
 *
 * Precedence for a job step, lowest to highest: its job template's extra
 * vars, the run's launch extra vars, the step's extra_vars_template mapping
 * of the previous step's output. The run's password answers win for their
 * keys, as carried answers do (JobLaunchSecrets).
 */
class WorkflowRunVars extends Component
{
    /** Launch extra vars that are not a JSON object: a list, a number, a text, true or false. */
    public const NOT_AN_OBJECT = 'The extra vars must be a JSON object, such as {"env": "prod"}.';

    /** Launch extra vars too large for their column: the most bytes of JSON a run stores. */
    public const TOO_LARGE = 'The extra vars are too large: a run stores at most %d bytes of them as JSON.';

    /** The size of workflow_job.extra_vars (TEXT) in bytes. */
    public const MAX_EXTRA_VARS_BYTES = 65535;

    /**
     * Check the launch extra vars, $overrides['extra_vars'] when the key is
     * there, and put them on $run, which the caller saves. null, an empty
     * text and an empty object mean none.
     *
     * @param array<string, mixed> $overrides
     * @throws WorkflowLaunchRejectedException when they are not a JSON object,
     *     JSON cannot hold them, they are too large to store, or a password
     *     answer of a job step's template is the placeholder
     * @throws SurveySecretException when the answers cannot be encrypted (APP_SECRET_KEY)
     * @throws \RuntimeException when there are vars to store but the run's
     *     columns do not exist yet (migration m000084)
     */
    public function storeLaunchVars(WorkflowTemplate $template, WorkflowJob $run, #[\SensitiveParameter] array $overrides): void
    {
        if (!array_key_exists('extra_vars', $overrides)) {
            return;
        }
        $vars = self::objectOf($overrides['extra_vars']);
        if ($vars === []) {
            return;
        }
        $passwordFields = self::passwordFieldNames((int)$template->id);
        foreach ($passwordFields as $name) {
            if (($vars[$name] ?? null) === SurveySecrets::PLACEHOLDER) {
                throw new WorkflowLaunchRejectedException(
                    sprintf(JobLaunchSecrets::PLACEHOLDER_SENT, SurveyDefinition::quote($name), SurveySecrets::PLACEHOLDER)
                );
            }
        }
        $split = SurveySecrets::split($vars, $passwordFields);
        self::assertEncodable($split);
        $run->setLaunchExtraVars($split['public']);
        // As stored: the column holds at most this many bytes.
        if (strlen((string)$run->getAttribute('extra_vars')) > self::MAX_EXTRA_VARS_BYTES) {
            throw new WorkflowLaunchRejectedException(sprintf(self::TOO_LARGE, self::MAX_EXTRA_VARS_BYTES));
        }
        $run->setSealedSecretExtraVars(SurveySecrets::seal($split['secret']));
    }

    /**
     * The launch overrides of a job step of $run: the run's launch extra vars
     * with $mappedVars on top (array_replace(), so numeric keys stay), and
     * the run's password answers as carried answers. The context names the
     * run in the job's job.launched audit entry.
     *
     * @param array<int|string, mixed> $mappedVars the step's mapping of the previous step's output
     * @return array<string, mixed>
     * @throws SurveySecretException when the run's sealed answers cannot be
     *     opened (APP_SECRET_KEY was changed)
     */
    public function jobOverrides(WorkflowJob $run, #[\SensitiveParameter] array $mappedVars): array
    {
        $secrets = SurveySecrets::open($run->getSealedSecretExtraVars());
        $overrides = [LaunchContext::KEY => LaunchContext::forWorkflow((int)$run->id, $secrets)];
        $vars = array_replace($run->launchExtraVars(), $mappedVars);
        if ($vars !== []) {
            $overrides['extra_vars'] = $vars;
        }

        return $overrides;
    }

    /**
     * The extra vars that $next's extra_vars_template maps from the output
     * of $previous, the step that led to it: target key => output key. A key
     * whose output value is missing or null is left out, and so is an entry
     * that does not map an extra var name (non-empty text) to the name of an
     * output variable (text), which a mapping an older version stored can
     * hold (WorkflowStepMapping): the job's extra vars stay a JSON object.
     *
     * Regression: a mapping stored as a list, such as ["build_version"],
     * mapped the output value to the key 0, and the job's extra vars became
     * the JSON list ["1.2.3"], which Ansible refuses.
     *
     * @return array<string, mixed>
     */
    public function stepMapping(WorkflowJobStep $previous, WorkflowStep $next): array
    {
        $mapping = $next->getParsedExtraVarsTemplate();
        if ($mapping === []) {
            return [];
        }

        $outputVars = $previous->getParsedOutputVars();
        $result = [];
        foreach ($mapping as $targetKey => $sourceKey) {
            if (!is_string($targetKey) || $targetKey === '' || !is_string($sourceKey)) {
                continue;
            }
            $value = $outputVars[$sourceKey] ?? null;
            if ($value !== null) {
                $result[$targetKey] = $value;
            }
        }

        return $result;
    }

    /**
     * The launch extra vars as an array: [] for none (null, an empty text, an
     * empty object), the object itself, or the object of a JSON text.
     *
     * A decoded JSON object whose keys are exactly 0 to n-1 cannot be told
     * from a JSON list; it is refused like one (Ansible variable names start
     * with a letter or an underscore). As JSON text it is taken.
     *
     * @return array<int|string, mixed>
     * @throws WorkflowLaunchRejectedException when the value is not a JSON object
     */
    private static function objectOf(#[\SensitiveParameter] mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (is_string($value)) {
            return self::objectOfText($value);
        }
        if (is_array($value) && !array_is_list($value)) {
            return $value;
        }

        throw new WorkflowLaunchRejectedException(self::NOT_AN_OBJECT);
    }

    /**
     * The object of a JSON text; [] for an empty one.
     *
     * @return array<int|string, mixed>
     * @throws WorkflowLaunchRejectedException when the text is not a JSON object
     */
    private static function objectOfText(#[\SensitiveParameter] string $json): array
    {
        $text = trim($json);
        if ($text === '') {
            return [];
        }
        $decoded = str_starts_with($text, '{') ? json_decode($text, true) : null;
        if (!is_array($decoded)) {
            throw new WorkflowLaunchRejectedException(self::NOT_AN_OBJECT);
        }

        return $decoded;
    }

    /**
     * Refuse values JSON cannot hold (a number too large for JSON, text that
     * is not UTF-8), with the message a job launch gives.
     *
     * @param array{public: array<int|string, mixed>, secret: array<string, mixed>} $split
     * @throws WorkflowLaunchRejectedException
     */
    private static function assertEncodable(#[\SensitiveParameter] array $split): void
    {
        try {
            JobLaunchSecrets::encode($split['public'], JobLaunchSecrets::EXTRA_VARS);
            JobLaunchSecrets::encode($split['secret'], JobLaunchSecrets::PASSWORD_ANSWERS);
        } catch (JobLaunchRejectedException $e) {
            throw new WorkflowLaunchRejectedException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Every password field name of the job templates of the workflow's job
     * steps, soft-deleted templates included: a value under such a name is a
     * password answer wherever it goes.
     *
     * @return list<string>
     */
    private static function passwordFieldNames(int $workflowTemplateId): array
    {
        $surveys = JobTemplate::findWithDeleted()
            ->select('survey_fields')
            ->where(['id' => WorkflowStep::find()
                ->select('job_template_id')
                ->where(['workflow_template_id' => $workflowTemplateId, 'step_type' => WorkflowStep::TYPE_JOB])])
            ->column();
        $names = [];
        foreach ($surveys as $json) {
            $names = array_merge($names, SurveyDefinition::secretNames(is_string($json) ? $json : null));
        }

        return array_values(array_unique($names));
    }
}
