<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\db\Query;

/**
 * Workflow fixtures of the E2E specs for approval gates, launch extra vars,
 * step changes while a run is active, deleting a workflow template and
 * canceling a run (tests/e2e/tests/workflow-templates/, workflow-jobs/ and
 * trigger/):
 *
 * - e2e-steps-workflow, never launched: the job steps e2e-steps-build and
 *   e2e-steps-cleanup. The build step's On Failure branch leads to the
 *   cleanup step, so the cleanup step cannot be removed.
 * - e2e-approval-steps-workflow, never launched: the job step
 *   e2e-approval-steps-job; an admin adds steps of every type through the
 *   form and removes them again.
 * - e2e-operator-steps-workflow, never launched: the job step
 *   e2e-operator-steps-job; e2e-operator does the same with job and pause
 *   steps.
 * - e2e-locked-workflow and e2e-delete-workflow: a pause step, then a job
 *   step, with a run paused at the pause step. That run locks the steps of
 *   e2e-locked-workflow; a spec deletes e2e-delete-workflow, which cancels
 *   its run.
 * - e2e-empty-workflow: no steps, so a launch is refused.
 * - e2e-trigger-vars-workflow: the job steps e2e-trigger-vars-first and
 *   e2e-trigger-vars-second on e2e-secret-template. No runner serves that
 *   template's runner group, so their jobs stay queued, in CI and in a dev
 *   stack.
 * - e2e-gate-rule: e2e-admin and e2e-operator approve, one approval needed,
 *   no timeout. Whichever of the two launches, the other one may decide.
 * - e2e-gate-continue-workflow: the approval step e2e-gate-continue-approve
 *   on e2e-gate-rule WITHOUT an On Failure branch, then the job step
 *   e2e-gate-continue-job. A rejection ends the run as failed, and the job
 *   step never starts (workflow-jobs/approval-flow.spec.ts).
 * - e2e-cancel-workflow: the same shape (e2e-cancel-approve, e2e-cancel-job),
 *   for the spec that cancels a run while it waits for its approval.
 * - e2e-gated-job-template, gated by e2e-gate-rule: the project, inventory
 *   and playbook of e2e-template, in the runner group e2e-gate-runner-group,
 *   which no runner serves, so an approved job stays queued.
 *   e2e-gated-job-workflow: the job step e2e-gated-job-step on it, then the
 *   pause step e2e-gated-job-after. Its job waits for approval; approved, it
 *   is queued and the step waits for it; rejected, the run ends as failed.
 *   Only admins may change the steps of a workflow with such a step, so
 *   e2e-operator finds them locked (workflow-templates/rbac.spec.ts).
 * - e2e-solo-gate-rule: only e2e-admin approves. e2e-solo-gate-workflow: the
 *   approval step e2e-solo-gate-approve on it, then the job step
 *   e2e-solo-gate-job. The launcher never decides, so a launch by e2e-admin
 *   is refused before a run starts.
 * - e2e-late-gate-template: like e2e-gated-job-template, but without an
 *   approval rule. e2e-late-gate-workflow: the job step e2e-late-gate-job on
 *   it. A spec gives the template a rule, which locks the steps of the
 *   workflow for operators, and takes it away again
 *   (workflow-templates/rbac.spec.ts).
 *
 * Every other job step uses the job template given to seed() (e2e-template).
 *
 * Every seed restores each fixture by name, soft-deleted ones too: its steps,
 * no trigger token, and no runs besides the paused ones above. The runs the
 * specs started go with the jobs their steps launched, and so with their
 * approval requests. A fixture whose job template or approver is missing is
 * skipped, with a line in the log.
 */
final class E2eWorkflowGateSeeder
{
    private const STEPS = 'e2e-steps-workflow';
    private const APPROVAL_STEPS = 'e2e-approval-steps-workflow';
    private const OPERATOR_STEPS = 'e2e-operator-steps-workflow';
    private const LOCKED = 'e2e-locked-workflow';
    private const DELETE = 'e2e-delete-workflow';
    private const EMPTY = 'e2e-empty-workflow';
    private const TRIGGER_VARS = 'e2e-trigger-vars-workflow';
    private const RULE = 'e2e-gate-rule';
    private const GATE_CONTINUE = 'e2e-gate-continue-workflow';
    private const CANCEL = 'e2e-cancel-workflow';
    private const APPROVER = 'e2e-operator';
    private const GATED_TEMPLATE = 'e2e-gated-job-template';
    private const GATE_RUNNER_GROUP = 'e2e-gate-runner-group';
    private const GATED_JOB = 'e2e-gated-job-workflow';
    private const SOLO_RULE = 'e2e-solo-gate-rule';
    private const SOLO_GATE = 'e2e-solo-gate-workflow';
    private const LATE_GATE_TEMPLATE = 'e2e-late-gate-template';
    private const LATE_GATE = 'e2e-late-gate-workflow';

    private const DESCRIPTION = 'E2E fixture: approval gates, launch extra vars and step changes (E2eWorkflowGateSeeder)';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $jobTemplateId): void
    {
        $steps = $this->restore(self::STEPS, $userId, [
            ['e2e-steps-build', WorkflowStep::TYPE_JOB, $jobTemplateId],
            ['e2e-steps-cleanup', WorkflowStep::TYPE_JOB, $jobTemplateId],
        ]);
        // The cleanup runs when the build fails: a step that others route to.
        $steps[0]->updateAttributes(['on_failure_step_id' => (int)$steps[1]->id]);
        $this->restore(self::APPROVAL_STEPS, $userId, [['e2e-approval-steps-job', WorkflowStep::TYPE_JOB, $jobTemplateId]]);
        $this->restore(self::OPERATOR_STEPS, $userId, [['e2e-operator-steps-job', WorkflowStep::TYPE_JOB, $jobTemplateId]]);

        $restored = [self::STEPS, self::APPROVAL_STEPS, self::OPERATOR_STEPS];
        foreach ([self::LOCKED => 'e2e-locked', self::DELETE => 'e2e-delete'] as $name => $stepPrefix) {
            $paused = $this->restore($name, $userId, [
                [$stepPrefix . '-pause', WorkflowStep::TYPE_PAUSE, null],
                [$stepPrefix . '-job', WorkflowStep::TYPE_JOB, $jobTemplateId],
            ]);
            $restored[] = sprintf('%s (run #%d paused)', $name, $this->pausedRun($paused[0], $userId));
        }
        $this->restore(self::EMPTY, $userId, []);
        $restored[] = self::EMPTY;

        $restored = array_merge(
            $restored,
            $this->seedTriggerVars($userId),
            $this->seedGates($userId, $jobTemplateId),
            $this->seedSoloGate($userId, $jobTemplateId),
            $this->seedLateGate($userId, $jobTemplateId)
        );
        ($this->logger)('  Restored workflow gate fixtures: ' . implode(', ', $restored) . ".\n");
    }

    /**
     * e2e-trigger-vars-workflow, unless e2e-secret-template is missing.
     *
     * @return list<string> what was restored
     */
    private function seedTriggerVars(int $userId): array
    {
        $template = self::jobTemplateId(E2eSurveySecretSeeder::TEMPLATE);
        if ($template === null) {
            ($this->logger)(sprintf(
                "  Workflow gate fixture %s skipped: job template %s missing.\n",
                self::TRIGGER_VARS,
                E2eSurveySecretSeeder::TEMPLATE
            ));
            return [];
        }
        $this->restore(self::TRIGGER_VARS, $userId, [
            ['e2e-trigger-vars-first', WorkflowStep::TYPE_JOB, $template],
            ['e2e-trigger-vars-second', WorkflowStep::TYPE_JOB, $template],
        ]);

        return [self::TRIGGER_VARS];
    }

    /**
     * e2e-gate-rule and what it gates, unless e2e-operator, the approver
     * besides e2e-admin, is missing: without a second approver, the launches
     * would be refused.
     *
     * @return list<string> what was restored
     */
    private function seedGates(int $userId, int $jobTemplateId): array
    {
        $approverId = self::userId(self::APPROVER);
        if ($approverId === null) {
            ($this->logger)(sprintf(
                "  Workflow gate fixtures %s skipped: user %s missing.\n",
                implode(', ', [self::RULE, self::GATE_CONTINUE, self::CANCEL, self::GATED_TEMPLATE, self::GATED_JOB]),
                self::APPROVER
            ));
            return [];
        }
        $ruleId = self::rule(
            self::RULE,
            'E2E: e2e-admin and e2e-operator approve; the one who did not launch decides',
            $userId,
            [$userId, $approverId]
        );
        foreach ([self::GATE_CONTINUE => 'e2e-gate-continue', self::CANCEL => 'e2e-cancel'] as $name => $stepPrefix) {
            // No On Failure branch: a rejection ends the run as failed.
            $this->restore($name, $userId, [
                [$stepPrefix . '-approve', WorkflowStep::TYPE_APPROVAL, $ruleId],
                [$stepPrefix . '-job', WorkflowStep::TYPE_JOB, $jobTemplateId],
            ]);
        }
        $gated = self::template(
            self::GATED_TEMPLATE,
            'E2E: e2e-gate-rule gates every launch; no runner serves its group',
            $userId,
            $jobTemplateId,
            $ruleId
        );
        // A rejection of the job step's approval ends the run: the pause step never starts.
        $this->restore(self::GATED_JOB, $userId, [
            ['e2e-gated-job-step', WorkflowStep::TYPE_JOB, $gated],
            ['e2e-gated-job-after', WorkflowStep::TYPE_PAUSE, null],
        ]);

        return [self::RULE, self::GATE_CONTINUE, self::CANCEL, self::GATED_TEMPLATE, self::GATED_JOB];
    }

    /**
     * Restores the job template $name, gated by the rule $ruleId (null: no
     * rule): the project, inventory and playbook of the job template
     * $jobTemplateId, in the runner group e2e-gate-runner-group, which no
     * runner serves. Its ID.
     *
     * @throws \InvalidArgumentException when job template #$jobTemplateId does not exist
     */
    private static function template(string $name, string $description, int $userId, int $jobTemplateId, ?int $ruleId): int
    {
        $source = JobTemplate::findWithDeleted()->where(['id' => $jobTemplateId])->one();
        if (!$source instanceof JobTemplate) {
            throw new \InvalidArgumentException(sprintf('Job template #%d does not exist.', $jobTemplateId));
        }
        $group = RunnerGroup::findOne(['name' => self::GATE_RUNNER_GROUP]) ?? new RunnerGroup();
        $group->setAttributes([
            'name' => self::GATE_RUNNER_GROUP,
            'description' => 'E2E: no runner serves this group, so its jobs stay queued',
            'created_by' => $userId,
        ], false);
        $group->save(false);

        /** @var JobTemplate|null $existing */
        $existing = JobTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_ASC])->one();
        $template = $existing ?? new JobTemplate();
        $template->setAttributes([
            'name' => $name,
            'description' => $description,
            'project_id' => (int)$source->project_id,
            'inventory_id' => (int)$source->inventory_id,
            'credential_id' => null,
            'runner_group_id' => (int)$group->id,
            'playbook' => (string)$source->playbook,
            'extra_vars' => null,
            'survey_fields' => null,
            'verbosity' => 0,
            'forks' => 5,
            'become' => false,
            'timeout_minutes' => 30,
            'approval_rule_id' => $ruleId,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
            'deleted_at' => null,
            'created_by' => $userId,
        ], false);
        $template->save(false);

        return (int)$template->id;
    }

    /**
     * Restores the workflow template $name with exactly $steps, in this order
     * (step order 10, 20, ...), without branches, runs or trigger token.
     *
     * @param list<array{0: string, 1: string, 2: int|null}> $steps name, type,
     *     and the job template of a job step or the approval rule of an approval step
     * @return list<WorkflowStep>
     */
    private function restore(string $name, int $userId, array $steps): array
    {
        /** @var WorkflowTemplate|null $existing */
        $existing = WorkflowTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_ASC])->one();
        $template = $existing ?? new WorkflowTemplate();
        $template->setAttributes([
            'name' => $name,
            'description' => self::DESCRIPTION,
            'created_by' => $userId,
            'deleted_at' => null,
            'trigger_token' => null,
            'trigger_token_created_by' => null,
        ], false);
        $template->save(false);
        $templateId = (int)$template->id;

        self::deleteRuns($templateId);
        WorkflowStep::deleteAll(['workflow_template_id' => $templateId]);
        $restored = [];
        foreach ($steps as $index => [$stepName, $type, $targetId]) {
            $step = new WorkflowStep();
            $step->setAttributes([
                'workflow_template_id' => $templateId,
                'name' => $stepName,
                'step_order' => ($index + 1) * 10,
                'step_type' => $type,
                'job_template_id' => $type === WorkflowStep::TYPE_JOB ? $targetId : null,
                'approval_rule_id' => $type === WorkflowStep::TYPE_APPROVAL ? $targetId : null,
            ], false);
            $step->save(false);
            $restored[] = $step;
        }

        return $restored;
    }

    /**
     * Deletes the runs of a workflow template and the jobs their steps
     * launched.
     */
    private static function deleteRuns(int $templateId): void
    {
        $db = \Yii::$app->db;
        $runs = (new Query())->select('id')->from('{{%workflow_job}}')->where(['workflow_template_id' => $templateId]);
        $jobIds = (new Query())->select('job_id')->from('{{%workflow_job_step}}')
            ->where(['workflow_job_id' => $runs])
            ->andWhere(['not', ['job_id' => null]])
            ->column($db);
        if ($jobIds !== []) {
            // Logs, artifacts and approval requests go with their job (foreign keys); tasks do not.
            $db->createCommand()->delete('{{%job_task}}', ['job_id' => $jobIds])->execute();
            $db->createCommand()->delete('{{%job}}', ['id' => $jobIds])->execute();
        }
        // The executions go with their run.
        $db->createCommand()->delete('{{%workflow_job}}', ['workflow_template_id' => $templateId])->execute();
    }

    /**
     * A run of the workflow of $pause that $userId started two minutes ago,
     * paused at $pause; its ID.
     */
    private function pausedRun(WorkflowStep $pause, int $userId): int
    {
        $run = new WorkflowJob();
        $run->setAttributes([
            'workflow_template_id' => (int)$pause->workflow_template_id,
            'launched_by' => $userId,
            'status' => WorkflowJob::STATUS_RUNNING,
            'current_step_id' => (int)$pause->id,
            'started_at' => time() - 120,
        ], false);
        $run->save(false);

        $execution = new WorkflowJobStep();
        $execution->setAttributes([
            'workflow_job_id' => (int)$run->id,
            'workflow_step_id' => (int)$pause->id,
            'status' => WorkflowJobStep::STATUS_RUNNING,
            'started_at' => time() - 115,
        ], false);
        $execution->snapshotStep($pause);
        $execution->save(false);

        return (int)$run->id;
    }

    /**
     * e2e-solo-gate-rule, whose only approver is e2e-admin, and the workflow
     * it gates: e2e-admin may never launch it.
     *
     * @return list<string> what was restored
     */
    private function seedSoloGate(int $userId, int $jobTemplateId): array
    {
        $ruleId = self::rule(
            self::SOLO_RULE,
            'E2E: only e2e-admin approves; a launch by e2e-admin is refused',
            $userId,
            [$userId]
        );
        $this->restore(self::SOLO_GATE, $userId, [
            ['e2e-solo-gate-approve', WorkflowStep::TYPE_APPROVAL, $ruleId],
            ['e2e-solo-gate-job', WorkflowStep::TYPE_JOB, $jobTemplateId],
        ]);

        return [self::SOLO_RULE, self::SOLO_GATE];
    }

    /**
     * e2e-late-gate-template, without an approval rule, and
     * e2e-late-gate-workflow, whose one job step uses it.
     *
     * @return list<string> what was restored
     */
    private function seedLateGate(int $userId, int $jobTemplateId): array
    {
        $template = self::template(
            self::LATE_GATE_TEMPLATE,
            'E2E: no approval rule until a spec assigns one; no runner serves its group',
            $userId,
            $jobTemplateId,
            null
        );
        $this->restore(self::LATE_GATE, $userId, [['e2e-late-gate-job', WorkflowStep::TYPE_JOB, $template]]);

        return [self::LATE_GATE_TEMPLATE, self::LATE_GATE];
    }

    /**
     * Restores the approval rule $name: the users $approverIds approve, one
     * approval is needed, and a request never times out, so it stays pending
     * until a spec decides it. Its ID.
     *
     * @param list<int> $approverIds
     */
    private static function rule(string $name, string $description, int $userId, array $approverIds): int
    {
        $rule = ApprovalRule::findOne(['name' => $name]) ?? new ApprovalRule();
        $rule->setAttributes([
            'name' => $name,
            'description' => $description,
            'job_template_id' => null,
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => (string)json_encode(['user_ids' => $approverIds]),
            'required_approvals' => 1,
            'timeout_minutes' => null,
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
            'created_by' => $userId,
        ], false);
        $rule->save(false);

        return (int)$rule->id;
    }

    private static function jobTemplateId(string $name): ?int
    {
        $template = JobTemplate::findOne(['name' => $name]);

        return $template === null ? null : (int)$template->id;
    }

    private static function userId(string $username): ?int
    {
        $user = User::findOne(['username' => $username]);

        return $user === null ? null : (int)$user->id;
    }
}
