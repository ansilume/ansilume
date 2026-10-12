<?php

declare(strict_types=1);

namespace app\commands;

use app\components\SurveyField;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobClaimService;
use app\services\JobCompletionService;
use app\services\JobLaunchService;
use app\services\ScheduleSecrets;
use yii\db\Query;

/**
 * Fixtures for the specs of password survey answers
 * (tests/e2e/tests/jobs/survey-secrets*.spec.ts,
 * schedules/survey-secrets.spec.ts, trigger/fire.spec.ts and the trigger
 * cards):
 *
 * - e2e-secret-template: a template with a required password survey field
 *   and verbosity 4, in e2e-secret-project (no team, so every role sees it),
 *   with the static inventory e2e-secret-inventory and its own runner group
 *   e2e-secret-group. No real runner serves that group, so its jobs stay
 *   where the specs leave them, in CI and in a dev stack whose runners serve
 *   the default group.
 * - Two jobs launched with a password answer and then claimed, with their
 *   payload built, on the server, because CI has no runner. One is claimed by
 *   e2e-secret-runner-current, which reports every capability of this
 *   release, and stays running. The other is claimed by
 *   e2e-secret-runner-legacy, a 2.8 runner without secret_extra_vars: the
 *   claim lowers its verbosity, and the job finishes, so its log ends with
 *   the note that says so. Their stored commands show the placeholder.
 * - e2e-secret-workflow: one job step of the template, for workflow triggers.
 * - e2e-secret-schedule: a disabled schedule of the template with a stored answer.
 *
 * Idempotent: every seed restores the fixtures by name, deletes every job and
 * workflow run of the template and launches the two jobs again. The answers
 * are E2E test values, not secrets.
 */
final class E2eSurveySecretSeeder
{
    public const GROUP = 'e2e-secret-group';
    public const PROJECT = 'e2e-secret-project';
    public const INVENTORY = 'e2e-secret-inventory';
    public const TEMPLATE = 'e2e-secret-template';
    public const WORKFLOW = 'e2e-secret-workflow';
    public const SCHEDULE = 'e2e-secret-schedule';
    public const CURRENT_RUNNER = 'e2e-secret-runner-current';
    public const LEGACY_RUNNER = 'e2e-secret-runner-legacy';
    public const TEXT_FIELD = 'app_env';
    public const PASSWORD_FIELD = 'db_password';
    /** The answer of the seeded jobs; tests/e2e/lib/survey-secrets.ts has the same value. */
    public const JOB_ANSWER = 'e2e-seeded-job-answer-Qx71';
    /** The answer the seeded schedule stores; tests/e2e/lib/survey-secrets.ts has the same value. */
    public const SCHEDULE_ANSWER = 'e2e-seeded-schedule-answer-Rw52';
    /** Above 3, so the claim for the legacy runner lowers it. */
    public const VERBOSITY = 4;
    /** The task the legacy runner reports; the job finishes as succeeded, not as "matched no host". */
    public const LEGACY_TASK = 'Connect with the database password';

    /** No host a runner could reach: no runner serves the group anyway. */
    private const INVENTORY_CONTENT = "all:\n  hosts:\n    e2e-secret-host.example.test:\n";

    /**
     * The output the legacy runner reports. Its script text must stay text
     * on the job page, and must not hide what the log shows after it.
     */
    private const LEGACY_OUTPUT = "PLAY [E2E survey secrets] ******************************\n\n"
        . 'TASK [' . self::LEGACY_TASK . "] ******************************\n"
        . "ok: [localhost]\n"
        . "<!--<script> e2e: </script><script>window.__e2eLogXss = 1</script>\n\n"
        . "PLAY RECAP *********************************************\n"
        . "localhost : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0\n";

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array{0: int, 1: int, 2: int} the project, inventory and runner group ids of the template
     */
    public function seed(int $userId): array
    {
        $group = $this->runnerGroup($userId);
        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $inventory = E2eFixtureHelper::inventory(self::INVENTORY, Inventory::TYPE_STATIC, null, $userId, self::INVENTORY_CONTENT);
        $parents = [(int)$project->id, (int)$inventory->id, (int)$group->id];
        $template = E2eFixtureHelper::surveyTemplate(self::TEMPLATE, $parents, $userId, self::surveyFields(), self::VERBOSITY);
        $workflow = $this->workflow((int)$template->id, $userId);
        // First of the sealed answers: without a usable APP_SECRET_KEY it stops the seed with the reason.
        $this->schedule((int)$template->id, $userId);
        $this->deleteJobs((int)$template->id);

        $current = $this->claimedJob($template, $group, $this->runner($group, self::CURRENT_RUNNER, Runner::CAPABILITIES, $userId), $userId);
        $legacy = $this->claimedJob($template, $group, $this->runner($group, self::LEGACY_RUNNER, [Runner::CAPABILITY_VAULT_PASSWORD_SOURCE], $userId), $userId);
        $this->finish($legacy);

        ($this->logger)(sprintf(
            "  Restored survey secret fixtures: template '%s' (ID %d), job #%d claimed by %s, job #%d claimed by %s and finished, workflow '%s' (ID %d), schedule '%s'.\n",
            self::TEMPLATE,
            $template->id,
            $current->id,
            self::CURRENT_RUNNER,
            $legacy->id,
            self::LEGACY_RUNNER,
            self::WORKFLOW,
            $workflow->id,
            self::SCHEDULE
        ));

        return $parents;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function surveyFields(): array
    {
        return [
            [
                'name' => self::TEXT_FIELD,
                'label' => 'Environment',
                'type' => SurveyField::TYPE_TEXT,
                'required' => true,
                'default' => 'staging',
                'options' => [],
                'hint' => '',
            ],
            [
                'name' => self::PASSWORD_FIELD,
                'label' => 'Database password',
                'type' => SurveyField::TYPE_PASSWORD,
                'required' => true,
                'default' => '',
                'options' => [],
                'hint' => 'Stored encrypted; pages show $encrypted$.',
            ],
        ];
    }

    private function runnerGroup(int $userId): RunnerGroup
    {
        $group = RunnerGroup::findOne(['name' => self::GROUP]) ?? new RunnerGroup();
        $group->name = self::GROUP;
        $group->description = 'E2E: no real runner serves this group';
        $group->created_by = $userId;
        $group->save(false);

        return $group;
    }

    /**
     * A runner of $group that reports $capabilities. Nobody knows its token:
     * it never calls the runner API.
     *
     * @param list<string> $capabilities
     */
    private function runner(RunnerGroup $group, string $name, array $capabilities, int $userId): Runner
    {
        $runner = Runner::findOne(['name' => $name]) ?? new Runner();
        $runner->runner_group_id = (int)$group->id;
        $runner->name = $name;
        if ($runner->isNewRecord) {
            $runner->token_hash = Runner::generateToken()['hash'];
        }
        $runner->capabilities = implode(',', $capabilities);
        $runner->created_by = $userId;
        $runner->save(false);

        return $runner;
    }

    /**
     * Restores the workflow with one job step of the template, without runs
     * or trigger token.
     */
    private function workflow(int $templateId, int $userId): WorkflowTemplate
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => self::WORKFLOW])->orderBy(['id' => SORT_ASC])->one();
        $workflow = $existing ?? new WorkflowTemplate();
        $workflow->name = self::WORKFLOW;
        $workflow->description = 'E2E fixture: one job step of ' . self::TEMPLATE;
        $workflow->created_by = $userId;
        $workflow->deleted_at = null;
        $workflow->trigger_token = null;
        $workflow->trigger_token_created_by = null;
        $workflow->save(false);

        // The runs first: their steps refer to the workflow's steps.
        \Yii::$app->db->createCommand()->delete('{{%workflow_job}}', ['workflow_template_id' => $workflow->id])->execute();
        WorkflowStep::deleteAll(['workflow_template_id' => $workflow->id]);
        $step = new WorkflowStep();
        $step->workflow_template_id = (int)$workflow->id;
        $step->name = self::WORKFLOW . '-job';
        $step->step_order = 10;
        $step->step_type = WorkflowStep::TYPE_JOB;
        $step->job_template_id = $templateId;
        $step->save(false);

        return $workflow;
    }

    /**
     * Every job of the template: the seeded ones and those the specs
     * launched, triggered or re-launched.
     */
    private function deleteJobs(int $templateId): void
    {
        $db = \Yii::$app->db;
        $ids = (new Query())->select('id')->from('{{%job}}')->where(['job_template_id' => $templateId])->column($db);
        if ($ids === []) {
            return;
        }
        $db->createCommand()->delete('{{%workflow_job_step}}', ['job_id' => $ids])->execute();
        $db->createCommand()->delete('{{%job_task}}', ['job_id' => $ids])->execute();
        // Logs, host summaries, artifacts and approval requests go with the jobs.
        $db->createCommand()->delete('{{%job}}', ['id' => $ids])->execute();
    }

    /**
     * Launches a job with a password answer and claims it for $runner, as
     * the runner API does: the claim stores the command the job page shows.
     *
     * @throws \RuntimeException when the claim takes another job: the group holds a job the seed did not launch
     */
    private function claimedJob(JobTemplate $template, RunnerGroup $group, Runner $runner, int $userId): Job
    {
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $launched = $launcher->launch($template, $userId, [
            'survey' => [self::TEXT_FIELD => 'staging', self::PASSWORD_FIELD => self::JOB_ANSWER],
        ]);

        /** @var JobClaimService $claims */
        $claims = \Yii::$app->get('jobClaimService');
        $job = $claims->claim($group, $runner);
        if ($job === null || (int)$job->id !== (int)$launched->id) {
            throw new \RuntimeException(sprintf(
                'E2E survey secret fixtures: %s did not claim job #%d; remove the other queued jobs of %s.',
                $runner->name,
                $launched->id,
                self::GROUP
            ));
        }
        // Only the stored command is a fixture: the payload, with the answer, goes nowhere.
        $claims->buildExecutionPayload($job, $runner);

        return $job;
    }

    /**
     * Reports output, a task and success for $job, as its runner would.
     */
    private function finish(Job $job): void
    {
        /** @var JobCompletionService $completion */
        $completion = \Yii::$app->get('jobCompletionService');
        $completion->appendLog($job, 'stdout', self::LEGACY_OUTPUT, 0);
        $completion->saveTasks($job, [[
            'seq' => 0,
            'name' => self::LEGACY_TASK,
            'action' => 'ansible.builtin.debug',
            'host' => 'localhost',
            'status' => 'ok',
            'changed' => false,
            'duration_ms' => 15,
        ]]);
        $completion->complete($job, 0);
    }

    /**
     * Restores the disabled schedule with SCHEDULE_ANSWER stored, sealed as a
     * save from the schedule form seals it.
     *
     * @throws \RuntimeException when the answer cannot be stored, e.g. without APP_SECRET_KEY
     */
    private function schedule(int $templateId, int $userId): void
    {
        $schedule = Schedule::findOne(['name' => self::SCHEDULE]) ?? new Schedule();
        $schedule->name = self::SCHEDULE;
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 0 1 1 *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = $userId;
        $schedule->extra_vars = (string)json_encode([self::TEXT_FIELD => 'prod', self::PASSWORD_FIELD => self::SCHEDULE_ANSWER]);
        $error = (new ScheduleSecrets())->prepare($schedule, true);
        if ($error !== null) {
            throw new \RuntimeException('E2E survey secret fixtures: the schedule answer was not stored: ' . $error);
        }
        $schedule->computeNextRunAt();
        $schedule->save(false);
    }
}
