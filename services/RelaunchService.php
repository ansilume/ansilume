<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\Job;
use app\models\JobTemplate;

/**
 * Re-launch: a new job of a finished job's template, with the job's launch
 * parameters (extra vars, limit, verbosity, check mode), launched by the
 * user who re-launches it.
 *
 * The job's password answers are carried over sealed (LaunchContext), so
 * nobody has to enter them again and nobody sees them. They are reused only
 * while the template still points at the project and inventory the job ran
 * with: a template moved to another project, or pointed at other hosts,
 * cannot hand them to a playbook or a host they were not entered for.
 */
class RelaunchService
{
    public const TEMPLATE_GONE = 'Original template no longer exists.';
    public const NOT_FINISHED = 'Job #%d cannot be re-launched in status "%s".';
    public const TARGET_CHANGED = "The template's project or inventory changed since this job ran; "
        . 'launch the template and enter the password answers.';
    public const ANSWERS_UNDECRYPTABLE = "The original job's encrypted survey answers cannot be decrypted; "
        . 'launch the template and enter the password answers.';

    /**
     * @param JobLaunchService|null $launcher the launch service; null for the application's
     */
    public function __construct(private readonly ?JobLaunchService $launcher = null)
    {
    }

    /**
     * Launch $original again, as $userId.
     *
     * @throws RelaunchRefusedException when the job cannot be re-launched; its message is for the user
     * @throws \RuntimeException when the launch fails (JobLaunchRejectedException among them)
     */
    public function relaunch(Job $original, int $userId): Job
    {
        $template = $this->templateOf($original);

        return $this->launcher()->launch($template, $userId, $this->overrides($original, $template));
    }

    /**
     * The template to launch: the job's, if the job has finished and its
     * template still exists. These are the conditions the job page shows
     * the Re-launch button for.
     *
     * @throws RelaunchRefusedException
     */
    private function templateOf(Job $original): JobTemplate
    {
        if (!$original->isFinished()) {
            throw new RelaunchRefusedException(sprintf(self::NOT_FINISHED, $original->id, $original->status));
        }
        $template = $original->jobTemplate;
        if ($template === null || $template->isDeleted()) {
            // A soft-deleted template's jobs are never claimed: the job would stay queued.
            throw new RelaunchRefusedException(self::TEMPLATE_GONE);
        }

        return $template;
    }

    /**
     * The overrides that repeat $original's launch on $template.
     *
     * @return array<string, mixed>
     * @throws RelaunchRefusedException
     */
    private function overrides(Job $original, JobTemplate $template): array
    {
        $secrets = $this->carriedAnswers($original, $template);
        $overrides = $this->extraVars($original, $secrets);
        if ($original->limit) {
            $overrides['limit'] = $original->limit;
        }
        if ($original->verbosity !== null) {
            $overrides['verbosity'] = $original->verbosity;
        }
        if ($original->check_mode) {
            $overrides['check_mode'] = 1;
        }
        // Also when there are no answers: the launch audit names the job it repeats.
        $overrides[LaunchContext::KEY] = LaunchContext::forRelaunch((int)$original->id, $secrets);

        return $overrides;
    }

    /**
     * The password answers of $original, opened, or [] when it has none.
     *
     * @return array<string, mixed>
     * @throws RelaunchRefusedException when the template no longer runs where the job ran, or
     *     the answers cannot be decrypted
     */
    private function carriedAnswers(Job $original, JobTemplate $template): array
    {
        $sealed = $original->getSealedSecretExtraVars();
        if ($sealed === null) {
            return [];
        }
        if (!$this->ranWhereTheTemplateRuns($original, $template)) {
            throw new RelaunchRefusedException(self::TARGET_CHANGED);
        }
        try {
            return SurveySecrets::open($sealed);
        } catch (SurveySecretException) {
            throw new RelaunchRefusedException(self::ANSWERS_UNDECRYPTABLE);
        }
    }

    /**
     * Whether the job ran on the template's current project and inventory,
     * as its runner payload recorded them at launch. A payload without them
     * does not match.
     */
    private function ranWhereTheTemplateRuns(Job $original, JobTemplate $template): bool
    {
        $payload = $original->decodedRunnerPayload();

        return self::sameId($payload['project_id'] ?? null, $template->project_id)
            && self::sameId($payload['inventory_id'] ?? null, $template->inventory_id);
    }

    private static function sameId(mixed $recorded, mixed $current): bool
    {
        if (is_string($recorded) && ctype_digit($recorded)) {
            $recorded = (int)$recorded;
        }

        return is_int($recorded) && $recorded === (int)$current;
    }

    /**
     * The job's extra vars as an override. The keys of the carried answers
     * are left out: the job shows the placeholder for them, and the launch
     * takes their values from the LaunchContext.
     *
     * @param array<string, mixed> $secrets
     * @return array<string, mixed>
     */
    private function extraVars(Job $original, #[\SensitiveParameter] array $secrets): array
    {
        if (!$original->extra_vars) {
            return [];
        }
        if ($secrets === []) {
            return ['extra_vars' => $original->extra_vars];
        }
        $public = json_decode((string)$original->extra_vars, true);
        $vars = is_array($public) ? SurveySecrets::withoutKeys($public, array_keys($secrets)) : [];

        return $vars === [] ? [] : ['extra_vars' => $vars];
    }

    private function launcher(): JobLaunchService
    {
        if ($this->launcher !== null) {
            return $this->launcher;
        }
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');

        return $launcher;
    }
}
