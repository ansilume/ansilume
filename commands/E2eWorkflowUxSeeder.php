<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTask;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Workflow fixtures of the template-page E2E specs
 * (tests/e2e/tests/workflow-templates/): positions, routes and editing steps.
 *
 * - e2e-routes-workflow, every kind of route cell: e2e-routes-build (job; On
 *   Failure → e2e-routes-cleanup), e2e-routes-approve (approval step on
 *   e2e-gate-rule), e2e-routes-deploy (job on e2e-gated-job-template, a gated
 *   job step), e2e-routes-verify (job; On Failure back to e2e-routes-deploy,
 *   a loop), e2e-routes-wait (pause), e2e-routes-cleanup (job; both branches
 *   end the workflow) and e2e-routes-spare (job; no branch leads to it, so it
 *   is unreachable).
 * - e2e-routes-legacy-workflow, steps older versions could save:
 *   e2e-legacy-start (job; On Success to a step that does not exist,
 *   MISSING_STEP_ID), e2e-legacy-gone (job on e2e-routes-gone-template, which
 *   is soft-deleted), e2e-legacy-pause (pause with an Always branch to
 *   e2e-legacy-end) and e2e-legacy-end (job).
 * - e2e-routes-loop-workflow: e2e-loop-check (job; On Failure back to itself,
 *   a retry) and e2e-loop-retry (job; On Success back to e2e-loop-check, On
 *   Failure ends the workflow): the success path repeats.
 * - e2e-edit-steps-workflow, for Edit Step: e2e-edit-build (job; On Failure
 *   → e2e-edit-deploy), e2e-edit-test (job) and e2e-edit-deploy (job).
 * - e2e-edit-operator-workflow, which e2e-operator edits: the job steps
 *   e2e-edit-operator-first and e2e-edit-operator-second.
 *
 * Registered in E2eController::seedData() after E2eWorkflowGateSeeder, whose
 * e2e-gate-rule and e2e-gated-job-template e2e-routes-workflow uses, looked
 * up by name; without them it is skipped, with a line in the log. Every
 * other job step uses the job template given to seed() (e2e-template).
 *
 * Every seed restores each fixture by name, soft-deleted ones too, in place:
 * its steps (step order 10, 20, ...) with their branches, no trigger token,
 * and no runs. The runs a spec left go with the jobs their steps launched,
 * and so with their approval requests.
 *
 * @phpstan-type StepSpec array{name: string, type: string, target: int|null, routes: array<string, int|string>}
 */
final class E2eWorkflowUxSeeder
{
    public const ROUTES = 'e2e-routes-workflow';

    public const LEGACY = 'e2e-routes-legacy-workflow';

    public const LOOP = 'e2e-routes-loop-workflow';

    public const EDIT_STEPS = 'e2e-edit-steps-workflow';

    public const EDIT_OPERATOR = 'e2e-edit-operator-workflow';

    /** The soft-deleted job template of e2e-legacy-gone. */
    public const GONE_TEMPLATE = 'e2e-routes-gone-template';

    /** The step ID e2e-legacy-start's On Success leads to: no step has it. */
    public const MISSING_STEP_ID = 999999999;

    /** E2eWorkflowGateSeeder's approval rule: e2e-admin and e2e-operator approve. */
    public const GATE_RULE = 'e2e-gate-rule';

    /** E2eWorkflowGateSeeder's job template that e2e-gate-rule gates. */
    public const GATED_TEMPLATE = 'e2e-gated-job-template';

    /** sprintf(): the restored fixtures, joined by ", ". */
    public const RESTORED = "  Restored workflow UX fixtures: %s.\n";

    /** sprintf(): the fixture, then what it needs that is missing. */
    public const SKIPPED = "  Workflow UX fixture %s skipped: %s missing.\n";

    /** sprintf(): the ID of the job template given to seed(). */
    public const TEMPLATE_MISSING = 'Job template #%d does not exist.';

    private const DESCRIPTION = 'E2E fixture: step positions, routes and editing (E2eWorkflowUxSeeder)';

    private const GONE_DESCRIPTION = 'E2E: soft-deleted, so a step on it cannot run (E2eWorkflowUxSeeder)';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @throws \InvalidArgumentException when the job template $jobTemplateId does not exist
     */
    public function seed(int $userId, int $jobTemplateId): void
    {
        $job = WorkflowStep::TYPE_JOB;
        $restored = $this->seedRoutes($userId, $jobTemplateId);
        $gone = self::goneTemplate($userId, $jobTemplateId);
        $this->restore(self::LEGACY, $userId, [
            self::step('e2e-legacy-start', $job, $jobTemplateId, ['on_success_step_id' => self::MISSING_STEP_ID]),
            self::step('e2e-legacy-gone', $job, $gone),
            self::step('e2e-legacy-pause', WorkflowStep::TYPE_PAUSE, null, ['on_always_step_id' => 'e2e-legacy-end']),
            self::step('e2e-legacy-end', $job, $jobTemplateId),
        ]);
        $this->restore(self::LOOP, $userId, [
            self::step('e2e-loop-check', $job, $jobTemplateId, ['on_failure_step_id' => 'e2e-loop-check']),
            self::step('e2e-loop-retry', $job, $jobTemplateId, [
                'on_success_step_id' => 'e2e-loop-check',
                'on_failure_step_id' => WorkflowStep::END_WORKFLOW,
            ]),
        ]);
        $this->restore(self::EDIT_STEPS, $userId, [
            self::step('e2e-edit-build', $job, $jobTemplateId, ['on_failure_step_id' => 'e2e-edit-deploy']),
            self::step('e2e-edit-test', $job, $jobTemplateId),
            self::step('e2e-edit-deploy', $job, $jobTemplateId),
        ]);
        $this->restore(self::EDIT_OPERATOR, $userId, [
            self::step('e2e-edit-operator-first', $job, $jobTemplateId),
            self::step('e2e-edit-operator-second', $job, $jobTemplateId),
        ]);

        $restored = array_merge($restored, [self::GONE_TEMPLATE, self::LEGACY, self::LOOP, self::EDIT_STEPS, self::EDIT_OPERATOR]);
        ($this->logger)(sprintf(self::RESTORED, implode(', ', $restored)));
    }

    /**
     * e2e-routes-workflow, unless e2e-gate-rule or e2e-gated-job-template is
     * missing.
     *
     * @return list<string> what was restored
     */
    private function seedRoutes(int $userId, int $jobTemplateId): array
    {
        $rule = ApprovalRule::findOne(['name' => self::GATE_RULE]);
        $gated = JobTemplate::findOne(['name' => self::GATED_TEMPLATE]);
        if ($rule === null || $gated === null) {
            ($this->logger)(sprintf(self::SKIPPED, self::ROUTES, $rule === null ? self::GATE_RULE : self::GATED_TEMPLATE));
            return [];
        }
        $job = WorkflowStep::TYPE_JOB;
        $this->restore(self::ROUTES, $userId, [
            self::step('e2e-routes-build', $job, $jobTemplateId, ['on_failure_step_id' => 'e2e-routes-cleanup']),
            self::step('e2e-routes-approve', WorkflowStep::TYPE_APPROVAL, (int)$rule->id),
            self::step('e2e-routes-deploy', $job, (int)$gated->id),
            self::step('e2e-routes-verify', $job, $jobTemplateId, ['on_failure_step_id' => 'e2e-routes-deploy']),
            self::step('e2e-routes-wait', WorkflowStep::TYPE_PAUSE, null),
            self::step('e2e-routes-cleanup', $job, $jobTemplateId, [
                'on_success_step_id' => WorkflowStep::END_WORKFLOW,
                'on_failure_step_id' => WorkflowStep::END_WORKFLOW,
            ]),
            self::step('e2e-routes-spare', $job, $jobTemplateId),
        ]);

        return [self::ROUTES];
    }

    /**
     * A step of a fixture. $target: the job template of a job step, the
     * approval rule of an approval step. $routes: the branches, by field,
     * each the name of the step it leads to or the value to store (0 ends
     * the workflow).
     *
     * @param array<string, int|string> $routes
     * @return StepSpec
     */
    private static function step(string $name, string $type, ?int $target, array $routes = []): array
    {
        return ['name' => $name, 'type' => $type, 'target' => $target, 'routes' => $routes];
    }

    /**
     * Restores e2e-routes-gone-template, soft-deleted, without an approval
     * rule: the project, inventory, runner group and playbook of the job
     * template $sourceId. Its ID.
     *
     * @throws \InvalidArgumentException when the job template $sourceId does not exist
     */
    private static function goneTemplate(int $userId, int $sourceId): int
    {
        $source = JobTemplate::findWithDeleted()->where(['id' => $sourceId])->one();
        if (!$source instanceof JobTemplate) {
            throw new \InvalidArgumentException(sprintf(self::TEMPLATE_MISSING, $sourceId));
        }
        /** @var JobTemplate|null $existing */
        $existing = JobTemplate::findWithDeleted()->where(['name' => self::GONE_TEMPLATE])->orderBy(['id' => SORT_ASC])->one();
        $gone = $existing ?? new JobTemplate();
        $gone->setAttributes([
            'name' => self::GONE_TEMPLATE,
            'description' => self::GONE_DESCRIPTION,
            'project_id' => (int)$source->project_id,
            'inventory_id' => (int)$source->inventory_id,
            'runner_group_id' => $source->runner_group_id,
            'playbook' => (string)$source->playbook,
            'approval_rule_id' => null,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
            'created_by' => $userId,
            'deleted_at' => time(),
        ], false);
        $gone->save(false);

        return (int)$gone->id;
    }

    /**
     * Restores the workflow template $name in place with exactly $steps, in
     * this order: no runs, no trigger token.
     *
     * @param list<StepSpec> $steps
     */
    private function restore(string $name, int $userId, array $steps): void
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_ASC])->one();
        $workflow = $existing ?? new WorkflowTemplate();
        $workflow->setAttributes([
            'name' => $name,
            'description' => self::DESCRIPTION,
            'created_by' => $userId,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
            'deleted_at' => null,
        ], false);
        $workflow->save(false);
        $workflowId = (int)$workflow->id;
        self::deleteRunsOf($workflowId);
        WorkflowStep::deleteAll(['workflow_template_id' => $workflowId]);

        $ids = [];
        foreach ($steps as $index => $spec) {
            $ids[$spec['name']] = self::insertStep($workflowId, ($index + 1) * 10, $spec);
        }
        foreach ($steps as $spec) {
            if ($spec['routes'] !== []) {
                $routes = array_map(static fn (int|string $to): int => is_int($to) ? $to : $ids[$to], $spec['routes']);
                WorkflowStep::updateAll($routes, ['id' => $ids[$spec['name']]]);
            }
        }
    }

    /**
     * @param StepSpec $spec
     * @return int the new step's ID
     */
    private static function insertStep(int $workflowId, int $order, array $spec): int
    {
        $step = new WorkflowStep();
        $step->setAttributes([
            'workflow_template_id' => $workflowId,
            'name' => $spec['name'],
            'step_order' => $order,
            'step_type' => $spec['type'],
            'job_template_id' => $spec['type'] === WorkflowStep::TYPE_JOB ? $spec['target'] : null,
            'approval_rule_id' => $spec['type'] === WorkflowStep::TYPE_APPROVAL ? $spec['target'] : null,
        ], false);
        $step->save(false);

        return (int)$step->id;
    }

    /**
     * Deletes the runs of the workflow template $workflowId, with the jobs
     * their steps launched: a job's log, artifacts and approval request go
     * with it (foreign keys), its tasks do not.
     */
    private static function deleteRunsOf(int $workflowId): void
    {
        $runIds = WorkflowJob::find()->select('id')->where(['workflow_template_id' => $workflowId])->column();
        $jobIds = WorkflowJobStep::find()->select('job_id')
            ->where(['workflow_job_id' => $runIds])
            ->andWhere(['not', ['job_id' => null]])
            ->column();
        JobTask::deleteAll(['job_id' => $jobIds]);
        Job::deleteAll(['id' => $jobIds]);
        WorkflowJob::deleteAll(['id' => $runIds]);
    }
}
