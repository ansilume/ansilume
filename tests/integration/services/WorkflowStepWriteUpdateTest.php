<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepWriteService;
use app\tests\integration\DbTestCase;

/**
 * WorkflowStepWriteService::update() (contract C5): a step changes in place
 * and keeps its ID. The checks run in their order: 404 for a step the
 * workflow does not have, 422 for another type or a pause step's unused
 * routes, 403 per rule 12, the job template only when it changes, the
 * model's rules, then, with the workflow locked, the guard, the step again
 * (404), the rules again and the move (422). An update that changes nothing
 * writes nothing.
 */
class WorkflowStepWriteUpdateTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    private User $admin;
    private WorkflowTemplate $workflow;
    private JobTemplate $template;
    private WorkflowStep $build;
    private WorkflowStep $test;
    private WorkflowStep $deploy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('write-update-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->template = $this->jobTemplate((int)$this->admin->id);
        $id = (int)$this->workflow->id;
        $this->build = $this->namedStep($id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->test = $this->namedStep($id, 'Test', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->deploy = $this->namedStep($id, 'Deploy', 30, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->build->on_failure_step_id = (int)$this->deploy->id;
        $this->build->save(false);
    }

    /**
     * regression: a branch could only be changed by removing and re-adding
     * the step. Removing a step that another step routes to is refused, so
     * Deploy, which Build's On Failure leads to, could not change at all;
     * and a step added again got a new ID, which dropped the branches that
     * led to it and its link to the runs that executed it. An update keeps
     * the step's ID, the routes that lead to it and its run history.
     */
    public function testRegressionABranchChangesInPlaceAndTheStepKeepsItsIdAndTheRoutesToIt(): void
    {
        $execution = $this->executionOf($this->deploy);
        $this->assertSame(409, $this->refusalOf(fn () => $this->writer()->remove(
            $this->workflow,
            (int)$this->deploy->id,
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        ))->status, 'removing it is still refused');

        $deploy = $this->writer()->update(
            $this->workflow,
            (int)$this->deploy->id,
            $this->apiCommand(['name' => 'Deploy to prod', 'on_failure_step_id' => (int)$this->test->id], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );
        $build = $this->writer()->update(
            $this->workflow,
            (int)$this->build->id,
            $this->formCommand([
                'name' => 'Build',
                'job_template_id' => (string)$this->template->id,
                'on_success_step_id' => '',
                'on_failure_step_id' => (string)$this->test->id,
                'on_always_step_id' => '',
            ], 'keep', true),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame([WorkflowStepChange::UPDATED, WorkflowStepChange::UPDATED], [$deploy->kind, $build->kind]);
        $this->assertSame((int)$this->deploy->id, (int)$deploy->step->id);
        $this->assertSame((int)$this->build->id, (int)$build->step->id);
        $storedDeploy = WorkflowStep::findOne($this->deploy->id);
        $storedBuild = WorkflowStep::findOne($this->build->id);
        $this->assertNotNull($storedDeploy);
        $this->assertNotNull($storedBuild);
        $this->assertSame('Deploy to prod', $storedDeploy->name);
        $this->assertSame((int)$this->test->id, $storedDeploy->on_failure_step_id);
        $this->assertSame((int)$this->test->id, $storedBuild->on_failure_step_id, 'the branch changed in place');
        $this->assertSame((int)$this->deploy->id, WorkflowJobStep::findOne($execution->id)?->workflow_step_id, 'the run history stays');
        $this->assertSame(['Build', 'Test', 'Deploy to prod'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame(3, (int)WorkflowStep::find()->where(['workflow_template_id' => $this->workflow->id])->count());
    }

    public function testAnUpdateSetsOnlyWhatTheCommandSets(): void
    {
        $before = $this->stepRows((int)$this->workflow->id);

        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->build->id,
            $this->apiCommand(['name' => 'Compile'], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame(WorkflowStepChange::UPDATED, $change->kind);
        $this->assertSame(['name' => ['from' => 'Build', 'to' => 'Compile']], $change->changes);
        $this->assertSame([1, 1], [$change->from, $change->to]);
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->names($change->stepsBefore));
        $this->assertSame(['Compile', 'Test', 'Deploy'], $this->names($change->stepsAfter));
        $this->assertSame(WorkflowStepWriteService::SOURCE_API, $change->source);
        $after = $this->stepRows((int)$this->workflow->id);
        $this->assertSame('Compile', $after[(int)$this->build->id]['name']);
        $unchanged = static fn (array $row): array => array_diff_key($row, ['name' => true, 'updated_at' => true]);
        $this->assertSame(array_map($unchanged, $before), array_map($unchanged, $after), 'nothing else changed');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
     */
    public static function nothingChangesProvider(): array
    {
        return [
            'an empty API body' => ['api', [], false],
            'the stored values from the API' => ['api', ['name' => 'Test', 'on_success_step_id' => null, 'step_type' => WorkflowStep::TYPE_JOB], false],
            'its own position' => ['api', ['position' => 2], false],
            'after the step before it' => ['api', ['after_step_id' => 'build'], false],
            'the stored values from the form' => ['form', ['name' => 'Test', 'job_template_id' => 'template', 'on_success_step_id' => ''], true],
            'the job template as "05"' => ['form', ['name' => 'Test', 'job_template_id' => '0template'], true],
            // regression: the mapping was compared as text, so [] (as the API answers no mapping) was a change.
            'no mapping, as the API answers it' => ['api', ['extra_vars_template' => []], false],
            'no mapping, as the text {}' => ['api', ['extra_vars_template' => '{}'], false],
            'no mapping, as null' => ['api', ['extra_vars_template' => null], false],
        ];
    }

    /**
     * An update that would change nothing writes nothing and is not
     * audited (UNCHANGED).
     *
     * @dataProvider nothingChangesProvider
     * @param array<string, mixed> $data
     */
    public function testAnUpdateThatChangesNothingWritesNothing(string $from, array $data, bool $edit): void
    {
        $data = array_map(fn (mixed $value): mixed => match ($value) {
            'build' => (int)$this->build->id,
            'template' => (string)$this->template->id,
            '0template' => '0' . $this->template->id,
            default => $value,
        }, $data);
        $command = $from === 'api' ? $this->apiCommand($data, false) : $this->formCommand($data, 'keep', $edit);
        $change = null;

        $writes = $this->writesOf(function () use (&$change, $command): void {
            $change = $this->writer()->update($this->workflow, (int)$this->test->id, $command, (int)$this->admin->id, 'api');
        });

        $this->assertInstanceOf(WorkflowStepChange::class, $change);
        $this->assertSame(WorkflowStepChange::UNCHANGED, $change->kind);
        $this->assertSame([2, 2], [$change->from, $change->to]);
        $this->assertSame([], $change->changes);
        $this->assertSame($change->stepsBefore, $change->stepsAfter);
        $this->assertSame('Test', $change->step->name);
        $this->assertSame([], $writes);
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testAnUpdateWithAPositionMovesTheStepToo(): void
    {
        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->deploy->id,
            $this->apiCommand(['name' => 'Ship', 'position' => 'start'], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame(WorkflowStepChange::UPDATED, $change->kind);
        $this->assertSame([3, 1], [$change->from, $change->to]);
        $this->assertTrue($change->moved());
        $this->assertSame(
            ['name' => ['from' => 'Deploy', 'to' => 'Ship'], 'position' => ['from' => 3, 'to' => 1]],
            $change->changes
        );
        $this->assertSame(['Ship', 'Build', 'Test'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame(['Ship', 'Build', 'Test'], $this->names($change->stepsAfter));
        $this->assertSame(10, WorkflowStep::findOne($this->deploy->id)?->step_order);
    }

    /**
     * An update that only moves the step lists only its position, and the
     * change messages read it as a move.
     */
    public function testAnUpdateThatOnlyMovesTheStepListsOnlyItsPosition(): void
    {
        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->deploy->id,
            $this->apiCommand(['after_step_id' => (int)$this->build->id], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame(WorkflowStepChange::UPDATED, $change->kind);
        $this->assertSame(['position' => ['from' => 3, 'to' => 2]], $change->changes);
        $this->assertSame(['Build', 'Deploy', 'Test'], $this->runOrder((int)$this->workflow->id));
        $this->assertStringStartsWith(
            sprintf(WorkflowStepChangeSummary::MOVED, 'Deploy', 2, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'Build')),
            WorkflowStepChangeSummary::of($change)->message()
        );
    }

    public function testTheStepTypeCannotChange(): void
    {
        foreach ([['api', WorkflowStep::TYPE_PAUSE], ['api', 'JOB'], ['form', WorkflowStep::TYPE_APPROVAL]] as [$from, $type]) {
            $command = $from === 'api'
                ? $this->apiCommand(['step_type' => $type], false)
                : $this->formCommand(['name' => 'Build', 'step_type' => $type], 'keep', true);
            $refusal = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $command, (int)$this->admin->id, $from));
            $this->assertSame([422, WorkflowStepCommand::TYPE_LOCKED], [$refusal->status, $refusal->getMessage()], $type);
        }

        $same = $this->writer()->update(
            $this->workflow,
            (int)$this->build->id,
            $this->apiCommand(['step_type' => WorkflowStep::TYPE_JOB, 'name' => 'Compile'], false),
            (int)$this->admin->id,
            'api'
        );
        $this->assertSame(['name' => ['from' => 'Build', 'to' => 'Compile']], $same->changes, 'the same type is no change');
    }

    /**
     * Branches on a pause step: the API refuses the ones it never takes;
     * the form ignores them.
     */
    public function testAPauseStepsUnusedBranchesAreRefusedFromTheApiAndIgnoredFromTheForm(): void
    {
        $wait = $this->namedStep((int)$this->workflow->id, 'Wait', 40);

        foreach (['on_failure_step_id' => (int)$this->build->id, 'on_always_step_id' => 0] as $route => $value) {
            $refusal = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$wait->id, $this->apiCommand([$route => $value], false), (int)$this->admin->id, 'api'));
            $this->assertSame([422, WorkflowStepCommand::PAUSE_BRANCHES_REFUSED], [$refusal->status, $refusal->getMessage()], $route);
        }

        $cleared = $this->writer()->update(
            $this->workflow,
            (int)$wait->id,
            $this->apiCommand(['on_success_step_id' => (int)$this->build->id, 'on_failure_step_id' => null], false),
            (int)$this->admin->id,
            'api'
        );
        $this->assertSame(['on_success_step_id' => ['from' => null, 'to' => (int)$this->build->id]], $cleared->changes);

        $form = $this->writer()->update(
            $this->workflow,
            (int)$wait->id,
            $this->formCommand(['name' => 'Wait', 'on_success_step_id' => '', 'on_failure_step_id' => (string)$this->test->id, 'on_always_step_id' => '0'], 'keep', true),
            (int)$this->admin->id,
            'web'
        );
        $this->assertSame(['on_success_step_id' => ['from' => (int)$this->build->id, 'to' => null]], $form->changes);
        $stored = WorkflowStep::findOne($wait->id);
        $this->assertNull($stored?->on_failure_step_id);
        $this->assertNull($stored?->on_always_step_id);
    }

    /**
     * An Always route that an older version saved on a pause step: the
     * edit form shows it under On Success, and saving moves it there (the
     * run takes it on Resume either way).
     */
    public function testAnOldAlwaysRouteOfAPauseStepMovesToOnSuccessThroughTheForm(): void
    {
        $wait = $this->namedStep((int)$this->workflow->id, 'Wait', 40);
        $wait->on_always_step_id = (int)$this->test->id;
        $wait->save(false);

        $change = $this->writer()->update(
            $this->workflow,
            (int)$wait->id,
            $this->formCommand(['name' => 'Wait', 'on_success_step_id' => (string)$this->test->id], 'keep', true),
            (int)$this->admin->id,
            'web'
        );

        $this->assertSame([
            'on_success_step_id' => ['from' => null, 'to' => (int)$this->test->id],
            'on_always_step_id' => ['from' => (int)$this->test->id, 'to' => null],
        ], $change->changes);
    }

    /**
     * An approval step has no Always route: one an older version saved is
     * refused until it is cleared (the API) or moved to On Success (the
     * form, which does not post Always for an approval step).
     */
    public function testAnApprovalStepThatKeepsAnOldAlwaysRouteIsRefused(): void
    {
        $gate = $this->namedStep((int)$this->workflow->id, 'Gate', 40, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
        $gate->on_always_step_id = (int)$this->test->id;
        $gate->save(false);

        $refusal = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$gate->id, $this->apiCommand(['name' => 'Approve'], false), (int)$this->admin->id, 'api'));
        $this->assertSame([422, WorkflowStep::APPROVAL_ALWAYS_REFUSED], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame('Gate', WorkflowStep::findOne($gate->id)?->name);

        $cleared = $this->writer()->update(
            $this->workflow,
            (int)$gate->id,
            $this->apiCommand(['on_always_step_id' => null, 'on_success_step_id' => (int)$this->test->id], false),
            (int)$this->admin->id,
            'api'
        );
        $this->assertSame(WorkflowStepChange::UPDATED, $cleared->kind);
        $this->assertNull(WorkflowStep::findOne($gate->id)?->on_always_step_id);

        $gate->refresh();
        $gate->on_always_step_id = (int)$this->build->id;
        $gate->save(false);
        $form = $this->writer()->update(
            $this->workflow,
            (int)$gate->id,
            $this->formCommand(['name' => 'Gate', 'on_success_step_id' => (string)$this->build->id, 'on_failure_step_id' => ''], 'keep', true),
            (int)$this->admin->id,
            'web'
        );
        $this->assertSame([
            'on_success_step_id' => ['from' => (int)$this->test->id, 'to' => (int)$this->build->id],
            'on_always_step_id' => ['from' => (int)$this->build->id, 'to' => null],
        ], $form->changes);
    }

    /**
     * A step may lead to itself (a retry loop) and to a later step, which a
     * step added after it is: edit the branch once that step exists.
     */
    public function testRoutesToTheStepItselfAndToALaterStepAreAllowed(): void
    {
        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->build->id,
            $this->apiCommand(['on_failure_step_id' => (int)$this->build->id, 'on_success_step_id' => (int)$this->deploy->id], false),
            (int)$this->admin->id,
            'api'
        );

        $this->assertSame([
            'on_success_step_id' => ['from' => null, 'to' => (int)$this->deploy->id],
            'on_failure_step_id' => ['from' => (int)$this->deploy->id, 'to' => (int)$this->build->id],
        ], $change->changes);
    }

    public function testARouteToAStepOfAnotherWorkflowIsRefused(): void
    {
        $elsewhere = $this->namedStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 'Elsewhere', 10);

        $refusal = $this->refusalOf(fn () => $this->writer()->update(
            $this->workflow,
            (int)$this->build->id,
            $this->apiCommand(['on_success_step_id' => (int)$elsewhere->id], false),
            (int)$this->admin->id,
            'api'
        ));

        $this->assertSame([422, WorkflowStep::ROUTE_TARGET_OUTSIDE], [$refusal->status, $refusal->getMessage()]);
    }

    /**
     * The job template an edit chooses is checked as an add checks it: 403
     * for one the user may see but not operate, 422 for one that does not
     * exist for them.
     */
    public function testAnotherJobTemplateIsCheckedAsForAnAdd(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $step = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$s['open']->id);
        $member = (int)$s['member']->id;
        $cases = [
            'one the member may see but not operate' => [(int)$s['viewed']->id, [403, WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN]],
            'one of another team' => [(int)$s['foreign']->id, [422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND]],
            'an unknown one' => [987654321, [422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND]],
        ];

        foreach ($cases as $case => [$templateId, $expected]) {
            $refusal = $this->refusalOf(fn () => $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['job_template_id' => $templateId], false), $member, 'api'));
            $this->assertSame($expected, [$refusal->status, $refusal->getMessage()], $case);
            $this->assertSame(['job_template_ids' => [$templateId]], $refusal->details, $case);
        }
        $this->assertSame((int)$s['open']->id, WorkflowStep::findOne($step->id)?->job_template_id);

        $own = $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['job_template_id' => (int)$s['own']->id], false), $member, 'api');
        $this->assertSame(['job_template_id' => ['from' => (int)$s['open']->id, 'to' => (int)$s['own']->id]], $own->changes);
    }

    /**
     * The template a step has is not checked again when an edit keeps it:
     * whether the user may change a workflow with it is the team scope's
     * question (operator access to every job step's project), which the
     * caller asks first and the guard again under the lock, and a deleted
     * one fails the model's rules. So an edit that keeps a job template the
     * member may only view is refused by the guard (403, no details), not as
     * a job template they may not use (JOB_TEMPLATE_FORBIDDEN with its ID).
     */
    public function testTheJobTemplateIsCheckedOnlyWhenItChanges(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $step = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$s['viewed']->id);

        $kept = $this->refusalOf(fn () => $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['name' => 'Compile'], false), (int)$s['member']->id, 'api'));

        $this->assertSame([403, WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, []], [$kept->status, $kept->getMessage(), $kept->details]);
        $this->assertSame('Build', WorkflowStep::findOne($step->id)?->name);
        $renamed = $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['name' => 'Compile'], false), (int)$s['admin']->id, 'api');
        $this->assertSame(['name' => ['from' => 'Build', 'to' => 'Compile']], $renamed->changes);

        $s['viewed']->softDelete();
        $refusal = $this->refusalOf(fn () => $this->writer()->update($workflow, (int)$step->id, $this->apiCommand(['name' => 'Report'], false), (int)$s['admin']->id, 'api'));
        $this->assertSame([422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND, []], [$refusal->status, $refusal->getMessage(), $refusal->details]);
    }

    // -- The order of the checks ----------------------------------------------

    public function testAStepTheWorkflowDoesNotHaveIsNotFoundFirst(): void
    {
        $elsewhere = $this->namedStep((int)$this->createWorkflowTemplate((int)$this->admin->id)->id, 'Elsewhere', 10);
        $operator = $this->createUserWithRole('write-update-operator', 'operator');
        $this->lockWithAnApprovalStep();

        foreach ([987654321, (int)$elsewhere->id] as $stepId) {
            $refusal = $this->refusalOf(fn () => $this->writer()->update(
                $this->workflow,
                $stepId,
                $this->apiCommand(['step_type' => WorkflowStep::TYPE_PAUSE], false),
                (int)$operator->id,
                'api'
            ));
            $this->assertSame(404, $refusal->status);
            $this->assertSame(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, $stepId), $refusal->getMessage());
        }
        $this->assertSame('Elsewhere', WorkflowStep::findOne($elsewhere->id)?->name);
    }

    /**
     * The request's problems (422) come before the policy (403): another
     * type, and a pause step's unused branches.
     */
    public function testTheRequestComesBeforeThePolicy(): void
    {
        $operator = $this->createUserWithRole('write-update-operator', 'operator');
        $this->lockWithAnApprovalStep();
        $wait = $this->namedStep((int)$this->workflow->id, 'Wait', 50);

        $type = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['step_type' => 'pause'], false), (int)$operator->id, 'api'));
        $pause = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$wait->id, $this->apiCommand(['on_failure_step_id' => 0], false), (int)$operator->id, 'api'));
        $policy = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['job_template_id' => 987654321, 'name' => ''], false), (int)$operator->id, 'api'));

        $this->assertSame([422, WorkflowStepCommand::TYPE_LOCKED], [$type->status, $type->getMessage()]);
        $this->assertSame([422, WorkflowStepCommand::PAUSE_BRANCHES_REFUSED], [$pause->status, $pause->getMessage()]);
        $this->assertSame([403, ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED], [$policy->status, $policy->getMessage()]);
    }

    public function testTheJobTemplateComesBeforeTheModelsRulesAndTheyComeBeforeTheGuard(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $before = $this->stepRows((int)$this->workflow->id);

        $template = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['job_template_id' => 987654321, 'name' => ''], false), (int)$this->admin->id, 'api'));
        $rules = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['name' => ''], false), (int)$this->admin->id, 'api'));
        $guard = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['name' => 'Compile'], false), (int)$this->admin->id, 'api'));

        $this->assertSame([422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND], [$template->status, $template->getMessage()]);
        $this->assertSame([422, 'Name cannot be blank.'], [$rules->status, $rules->getMessage()]);
        $this->assertSame([409, WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id])], [$guard->status, $guard->getMessage()]);
        $this->assertSame(['workflow_job_ids' => [(int)$run->id]], $guard->details);
        $this->assertSame($before, $this->stepRows((int)$this->workflow->id), 'nothing written');
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testAWorkflowDeletedMeanwhileIsNotFound(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowTemplate::findOne($this->workflow->id)?->softDelete(),
            fn () => $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['name' => 'x'], false), (int)$this->admin->id, 'api'))
        );

        $this->assertSame([404, sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$this->workflow->id)], [$refusal->status, $refusal->getMessage()]);
    }

    public function testAStepRemovedMeanwhileIsNotFound(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowStep::deleteAll(['id' => $this->test->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->test->id, $this->apiCommand(['name' => 'x'], false), (int)$this->admin->id, 'api'))
        );

        $this->assertSame(404, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, (int)$this->test->id), $refusal->getMessage());
    }

    /**
     * Under the lock the rules run again: a step the edit routes to that
     * another request removed meanwhile.
     */
    public function testARouteTargetRemovedMeanwhileIsRefusedAndNothingIsWritten(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowStep::deleteAll(['id' => $this->test->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->update(
                $this->workflow,
                (int)$this->build->id,
                $this->apiCommand(['name' => 'Compile', 'on_success_step_id' => (int)$this->test->id], false),
                (int)$this->admin->id,
                'api'
            ))
        );

        $this->assertSame([422, WorkflowStep::ROUTE_TARGET_OUTSIDE], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame('Build', WorkflowStep::findOne($this->build->id)?->name);
    }

    /**
     * The step to place it after that another request removed: the move
     * refuses it, and the edit's other fields are not saved.
     */
    public function testAnAnchorRemovedMeanwhileIsRefusedAndNothingIsWritten(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowStep::deleteAll(['id' => $this->test->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->update(
                $this->workflow,
                (int)$this->build->id,
                $this->apiCommand(['name' => 'Compile', 'after_step_id' => (int)$this->test->id], false),
                (int)$this->admin->id,
                'api'
            ))
        );

        $this->assertSame([422, sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, (int)$this->test->id)], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame('Build', WorkflowStep::findOne($this->build->id)?->name);
        $this->assertSame(['Build', 'Deploy'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testAPositionTheStepCannotTakeIsRefused(): void
    {
        $refusal = $this->refusalOf(fn () => $this->writer()->update($this->workflow, (int)$this->build->id, $this->apiCommand(['name' => 'x', 'position' => 4], false), (int)$this->admin->id, 'api'));

        $this->assertSame([422, sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3)], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame('Build', WorkflowStep::findOne($this->build->id)?->name);
    }

    // -- The audit entry ------------------------------------------------------

    /**
     * regression: the variable mapping was compared as text, so the stored
     * mapping sent back in other words (other spaces, other order of keys)
     * changed it and rewrote the column. It is the same mapping: unchanged,
     * nothing written, nothing audited.
     */
    public function testTheSameMappingInOtherWordsIsNoChange(): void
    {
        WorkflowStep::updateAll(['extra_vars_template' => '{"app_version": "build_version", "target": "hosts"}'], ['id' => $this->test->id]);
        $change = null;

        $writes = $this->writesOf(function () use (&$change): void {
            $change = $this->writer()->update(
                $this->workflow,
                (int)$this->test->id,
                $this->apiCommand(['extra_vars_template' => ['target' => 'hosts', 'app_version' => 'build_version']], false),
                (int)$this->admin->id,
                WorkflowStepWriteService::SOURCE_API
            );
        });

        $this->assertInstanceOf(WorkflowStepChange::class, $change);
        $this->assertSame(WorkflowStepChange::UNCHANGED, $change->kind);
        $this->assertSame([], $writes);
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    /**
     * A change of other fields leaves the same mapping in other words
     * alone: the column keeps its stored text, and only the changed fields
     * are listed.
     */
    public function testAChangeOfOtherFieldsKeepsTheStoredTextOfTheSameMapping(): void
    {
        $spaced = '{"app_version":  "build_version"}';
        WorkflowStep::updateAll(['extra_vars_template' => $spaced], ['id' => $this->test->id]);

        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->test->id,
            $this->apiCommand(['name' => 'Unit tests', 'extra_vars_template' => ['app_version' => 'build_version']], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame(['name' => ['from' => 'Test', 'to' => 'Unit tests']], $change->changes);
        $this->assertSame($spaced, $change->step->extra_vars_template);
        $this->assertSame($spaced, WorkflowStep::findOne($this->test->id)?->extra_vars_template);
    }

    /**
     * The template page cannot change a step's mapping: a mapping of another
     * shape that an older version stored does not stop an edit there, and
     * stays as it was.
     */
    public function testAMappingAnOlderVersionStoredDoesNotStopAnEditFromTheForm(): void
    {
        WorkflowStep::updateAll(['extra_vars_template' => '["build_version"]'], ['id' => $this->test->id]);

        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->test->id,
            $this->formCommand(['name' => 'Unit tests', 'job_template_id' => (string)$this->template->id], 'keep', true),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(WorkflowStepChange::UPDATED, $change->kind);
        $this->assertSame(['name'], array_keys($change->changes));
        $this->assertSame('["build_version"]', WorkflowStep::findOne($this->test->id)?->extra_vars_template);
    }

    /**
     * The variable mapping is listed as changed, never with its values.
     */
    public function testTheAuditEntryOfAnUpdate(): void
    {
        $change = $this->writer()->update(
            $this->workflow,
            (int)$this->deploy->id,
            $this->apiCommand([
                'name' => 'Ship',
                'on_failure_step_id' => 0,
                'extra_vars_template' => ['target' => 'prod-cluster-7'],
                'position' => 1,
            ], false),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame(['from' => null, 'to' => '{"target":"prod-cluster-7"}'], $change->changes['extra_vars_template']);
        $this->assertSame([[
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
            'user_id' => (int)$this->admin->id,
            'context' => [
                'step_id' => (int)$this->deploy->id,
                'step_name' => 'Ship',
                'step_type' => WorkflowStep::TYPE_JOB,
                'job_template_id' => (int)$this->template->id,
                'approval_rule_id' => null,
                'changes' => [
                    'name' => ['from' => 'Deploy', 'to' => 'Ship'],
                    'on_failure_step_id' => ['from' => null, 'to' => 0],
                    'extra_vars_template' => ['changed' => true],
                    'position' => ['from' => 3, 'to' => 1],
                ],
                'source' => 'api',
            ],
        ]], $this->stepAudits((int)$this->workflow->id));
        $this->assertFalse(
            AuditLog::find()->where(['like', 'metadata', 'prod-cluster-7'])->exists(),
            'the mapping\'s values are never audited'
        );
    }

    /**
     * An execution of $step in a finished run.
     */
    private function executionOf(WorkflowStep $step): WorkflowJobStep
    {
        $run = $this->createWorkflowJob((int)$step->workflow_template_id, (int)$this->admin->id, WorkflowJob::STATUS_SUCCEEDED);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->status = WorkflowJobStep::STATUS_SUCCEEDED;
        $execution->save(false);

        return $execution;
    }

    /**
     * Give the workflow an approval step, which locks its steps for
     * everyone but admins.
     */
    private function lockWithAnApprovalStep(): void
    {
        $this->namedStep((int)$this->workflow->id, 'Approve', 40, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
    }
}
