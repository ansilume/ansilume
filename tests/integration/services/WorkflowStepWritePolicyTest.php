<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepPosition;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\DbTestCase;

/**
 * Rule 12 on the step write path (contract C6): the steps of a workflow with
 * an approval step or a gated job step (a job step whose job template has an
 * approval rule) are for admins (approval-rule.update), and so are adding an
 * approval step and giving a job step a gated job template, by an add or an
 * edit. Every row of the table, as an operator and as an admin. An edit is
 * checked against the workflow's lock first, then as an add of the edited
 * step; under the lock the guard checks the stored lock again.
 */
class WorkflowStepWritePolicyTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    private User $admin;
    private User $operator;
    private JobTemplate $plainTemplate;
    private JobTemplate $gatedTemplate;
    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('write-policy-admin', 'admin');
        $this->operator = $this->createUserWithRole('write-policy-operator', 'operator');
        $this->ruleId = (int)$this->createApprovalRule((int)$this->admin->id)->id;
        $this->plainTemplate = $this->jobTemplate((int)$this->admin->id);
        $this->gatedTemplate = $this->jobTemplate((int)$this->admin->id, $this->ruleId);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function ruleProvider(): array
    {
        $approvalLocked = ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED;
        $gatedLocked = ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED;
        $approvalAdd = ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED;
        $gatedAdd = ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED;
        $rows = [
            'add a plain step' => [null, $approvalLocked, $gatedLocked],
            'edit a plain step' => [null, $approvalLocked, $gatedLocked],
            'move a plain step' => [null, $approvalLocked, $gatedLocked],
            'remove a plain step' => [null, $approvalLocked, $gatedLocked],
            'add an approval step' => [$approvalAdd, $approvalAdd, $approvalAdd],
            'add a gated job step' => [$gatedAdd, $gatedAdd, $gatedAdd],
            'edit a job step to a gated job template' => [$gatedAdd, $approvalLocked, $gatedLocked],
        ];
        $cases = [];
        foreach ($rows as $action => $operatorRefusals) {
            foreach (['plain', 'approval', 'gated job'] as $index => $workflow) {
                $cases["{$action}, workflow: {$workflow}, operator"] = [$action, $workflow, 'operator', $operatorRefusals[$index]];
                $cases["{$action}, workflow: {$workflow}, admin"] = [$action, $workflow, 'admin', null];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider ruleProvider
     */
    public function testEveryRowOfTheRule(string $action, string $kind, string $role, ?string $refusal): void
    {
        $workflow = $this->workflowOf($kind);
        $plain = $this->namedStep((int)$workflow->id, 'Plain', 5, WorkflowStep::TYPE_JOB, (int)$this->plainTemplate->id);
        $user = $role === 'admin' ? $this->admin : $this->operator;
        $before = $this->stepRows((int)$workflow->id);
        $act = fn (): ?WorkflowStepChange => $this->act($action, $workflow, $plain, (int)$user->id);

        if ($refusal !== null) {
            $refused = $this->refusalOf($act);
            $this->assertSame([403, $refusal], [$refused->status, $refused->getMessage()]);
            $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing written');
            $this->assertSame([], $this->stepAudits((int)$workflow->id));

            return;
        }
        $change = $act();
        $this->assertNotNull($change);
        $this->assertNotSame(WorkflowStepChange::UNCHANGED, $change->kind, 'the change was made');
        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
    }

    /**
     * Under the lock, the guard checks the workflow's lock again: an
     * approval step another request added after the checks, or a rule that
     * a job template of the workflow got, refuses the change.
     */
    public function testTheGuardChecksTheLockAgainUnderTheLock(): void
    {
        $workflow = $this->workflowOf('plain');
        $plain = $this->namedStep((int)$workflow->id, 'Plain', 5, WorkflowStep::TYPE_JOB, (int)$this->plainTemplate->id);
        $before = $this->stepRows((int)$workflow->id);
        $actions = ['add a plain step', 'edit a plain step', 'move a plain step', 'remove a plain step'];

        foreach ($actions as $action) {
            $refusal = $this->meanwhile(
                fn () => JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->plainTemplate->id]),
                fn () => $this->refusalOf(fn () => $this->act($action, $workflow, $plain, (int)$this->operator->id))
            );
            JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $this->plainTemplate->id]);
            $this->assertSame([403, ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED], [$refusal->status, $refusal->getMessage()], $action);
        }
        $this->assertSame($before, $this->stepRows((int)$workflow->id));

        $approval = $this->meanwhile(
            fn () => $this->namedStep((int)$workflow->id, 'Approve', 50, WorkflowStep::TYPE_APPROVAL, null, $this->ruleId),
            fn () => $this->refusalOf(fn () => $this->act('edit a plain step', $workflow, $plain, (int)$this->operator->id))
        );
        $this->assertSame([403, ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED], [$approval->status, $approval->getMessage()]);
        $this->assertSame('Plain', WorkflowStep::findOne($plain->id)?->name);
    }

    /**
     * Under the lock, the job template that an add or an edit gives a job
     * step is checked again after the guard's checks: a run that started
     * meanwhile answers 409 first, and a rule that the job template got
     * meanwhile 403 (GATED_JOB_STEP_ADD_REFUSED) once no run is active. (A
     * rule that the job template of an existing step gets meanwhile locks
     * the workflow, which the guard refuses first:
     * testTheGuardChecksTheLockAgainUnderTheLock().) The web and API races:
     * WorkflowStepGatedTemplateRaceTest, WorkflowStepGatedTemplateRaceApiTest.
     */
    public function testTheJobTemplateIsCheckedAgainAfterTheGuard(): void
    {
        $workflow = $this->workflowOf('plain');
        $plain = $this->namedStep((int)$workflow->id, 'Plain', 5, WorkflowStep::TYPE_JOB, (int)$this->plainTemplate->id);
        $other = $this->jobTemplate((int)$this->admin->id);
        $before = $this->stepRows((int)$workflow->id);
        $gate = fn () => JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $other->id]);
        $add = fn () => $this->writer()->add($workflow, $this->apiCommand(['name' => 'New', 'job_template_id' => (int)$other->id]), (int)$this->operator->id, 'api');
        $edit = fn () => $this->writer()->update($workflow, (int)$plain->id, $this->formCommand(['name' => 'Plain', 'job_template_id' => (string)$other->id], 'keep', true), (int)$this->operator->id, 'web');

        foreach (['add' => $add, 'edit' => $edit] as $case => $change) {
            $run = null;
            $busy = $this->meanwhile(
                function () use ($gate, $workflow, &$run): void {
                    $gate();
                    $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
                },
                fn () => $this->refusalOf($change)
            );
            $this->assertSame(409, $busy->status, $case . ': the active run first');
            $this->assertNotNull($run);
            $run->delete();
            JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $other->id]);

            $gated = $this->meanwhile($gate, fn () => $this->refusalOf($change));
            $this->assertSame([403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED], [$gated->status, $gated->getMessage()], $case);
            JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $other->id]);
        }
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * A gated job template the user may not see counts as no template: the
     * answer is that it does not exist (422), not that it is gated.
     */
    public function testAnInvisibleGatedTemplateIsNotFound(): void
    {
        $s = $this->teamScope();
        $s['foreign']->approval_rule_id = $this->ruleId;
        $s['foreign']->save(false);
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $step = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$s['own']->id);

        $add = $this->refusalOf(fn () => $this->writer()->add($workflow, $this->apiCommand(['name' => 'x', 'job_template_id' => (int)$s['foreign']->id]), (int)$s['member']->id, 'api'));
        $edit = $this->refusalOf(fn () => $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['job_template_id' => (int)$s['foreign']->id], false), (int)$s['member']->id, 'api'));

        foreach ([$add, $edit] as $refusal) {
            $this->assertSame([422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND], [$refusal->status, $refusal->getMessage()]);
            $this->assertSame(['job_template_ids' => [(int)$s['foreign']->id]], $refusal->details);
        }
    }

    /**
     * The rule reads the ID the step stores: "05" and "+5" are template 5,
     * so neither form of a gated template's ID gets past it.
     */
    public function testTheRuleReadsTheIdTheStepStores(): void
    {
        $workflow = $this->workflowOf('plain');
        $plain = $this->namedStep((int)$workflow->id, 'Plain', 5, WorkflowStep::TYPE_JOB, (int)$this->plainTemplate->id);
        $id = (int)$this->gatedTemplate->id;

        foreach (['0' . $id, '+' . $id, '00' . $id] as $posted) {
            $add = $this->refusalOf(fn () => $this->writer()->add(
                $workflow,
                $this->formCommand(['name' => 'x', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => $posted]),
                (int)$this->operator->id,
                'web'
            ));
            $edit = $this->refusalOf(fn () => $this->writer()->update(
                $workflow,
                (int)$plain->id,
                $this->formCommand(['name' => 'Plain', 'job_template_id' => $posted], 'keep', true),
                (int)$this->operator->id,
                'web'
            ));
            $this->assertSame([403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED], [$add->status, $add->getMessage()], $posted);
            $this->assertSame([403, ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED], [$edit->status, $edit->getMessage()], $posted);
        }
        $this->assertSame((int)$this->plainTemplate->id, WorkflowStep::findOne($plain->id)?->job_template_id);
    }

    /**
     * A workflow of the kind: plain (job steps without an approval rule),
     * with an approval step, or with a gated job step.
     */
    private function workflowOf(string $kind): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $id = (int)$workflow->id;
        $this->namedStep($id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->plainTemplate->id);
        if ($kind === 'approval') {
            $this->namedStep($id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, $this->ruleId);
        } elseif ($kind === 'gated job') {
            $this->namedStep($id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate->id);
        }

        return $workflow;
    }

    private function act(string $action, WorkflowTemplate $workflow, WorkflowStep $plain, int $userId): ?WorkflowStepChange
    {
        $writer = $this->writer();
        $id = (int)$plain->id;

        return match ($action) {
            'add a plain step' => $writer->add($workflow, $this->apiCommand(['name' => 'New', 'job_template_id' => (int)$this->plainTemplate->id]), $userId, 'api'),
            'edit a plain step' => $writer->update($workflow, $id, $this->apiCommand(['name' => 'Renamed'], false), $userId, 'api'),
            'move a plain step' => $writer->move($workflow, $id, WorkflowStepPosition::end(), $userId, 'api'),
            'remove a plain step' => $writer->remove($workflow, $id, $userId, 'api'),
            'add an approval step' => $writer->add($workflow, $this->apiCommand(['name' => 'Gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => $this->ruleId]), $userId, 'api'),
            'add a gated job step' => $writer->add($workflow, $this->apiCommand(['name' => 'Gated', 'job_template_id' => (int)$this->gatedTemplate->id]), $userId, 'api'),
            default => $writer->update($workflow, $id, $this->apiCommand(['job_template_id' => (int)$this->gatedTemplate->id], false), $userId, 'api'),
        };
    }
}
