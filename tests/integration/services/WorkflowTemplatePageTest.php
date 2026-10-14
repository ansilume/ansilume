<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeRefusedException;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\services\WorkflowStepWriteService;
use app\services\WorkflowTemplatePage;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * WorkflowTemplatePage: what the workflow template page and the page that
 * edits a step show a user, and the requests their step forms post.
 * WorkflowTemplateController renders and handles the requests; its tests
 * (WorkflowTemplateController*Test, WorkflowTemplateEditStepTest,
 * WorkflowStepWebPathsTest) show the pages end to end, and W2-T1's tests
 * the steps table and the step form.
 */
class WorkflowTemplatePageTest extends DbTestCase
{
    use TeamScopeFixtures;

    /**
     * The texts keep their wording: docs/updating.md and the E2E specs
     * (tests/e2e/lib/workflows.ts) quote them as the page shows them, so a
     * change here must change them too. The route labels and the Step
     * Order texts of 2.10 are gone: the steps table (WorkflowStepsTable) and
     * the step form (WorkflowStepFormView) word the page now.
     */
    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            [
                'GATED_TEMPLATES_HINT' => 'Job templates with an approval rule are not listed: only admins may add a job step for them.',
                'STEPS_LOCKED_NOTE' => 'You may still rename the workflow, launch it and manage its trigger token.',
                'TEMPLATES_CRUMB' => 'Workflow Templates',
            ],
            (new \ReflectionClass(WorkflowTemplatePage::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC)
        );
    }

    /**
     * An admin is offered every job template they may operate, gated ones
     * included, and every approval rule by name, and may change the steps
     * of a workflow with a gated step; the page lists the active runs, which
     * lock the steps for everyone, and names whom the trigger runs as.
     */
    public function testAnAdminGetsEveryChoiceAndNoLock(): void
    {
        $s = $this->teamScope();
        $rule = $this->gate($s['own'], $s);
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_SUCCEEDED);
        $workflow->generateTriggerToken($s['member']->id);

        $params = (new WorkflowTemplatePage($s['admin']->id))->viewParams($workflow);

        $this->assertSame(
            [
                'model', 'canOperate', 'canChange', 'canChangeSteps', 'jobTemplateOptions', 'approvalRuleOptions',
                'mayAssignRules', 'stepsLock', 'activeRunIds', 'activeRuns', 'runStates', 'activeRunCount',
                'triggerUser', 'stepsTable', 'stepForm',
            ],
            array_keys($params),
            'the parameters views/workflow-template/view.php reads'
        );
        $this->assertSame($workflow, $params['model']);
        $this->assertTrue($params['canOperate']);
        $this->assertTrue($params['canChange']);
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $this->assertSame($s[$key]->name, $params['jobTemplateOptions'][$s[$key]->id] ?? null, $key);
        }
        $this->assertSame($rule->name, $params['approvalRuleOptions'][$rule->id] ?? null);
        $this->assertTrue($params['mayAssignRules']);
        $this->assertNull($params['stepsLock']);
        $this->assertSame([(int)$run->id], $params['activeRunIds'], 'running runs only');
        $this->assertSame([(int)$run->id], array_map(static fn (WorkflowJob $r): int => (int)$r->id, $params['activeRuns']));
        $this->assertTrue($params['activeRuns'][0]->isRelationPopulated('launcher'), 'the launcher is loaded with the runs');
        $this->assertSame([(int)$run->id], array_keys($params['runStates']));
        $this->assertSame(Display::STATE_RUNNING, $params['runStates'][(int)$run->id]->state);
        $this->assertSame(1, $params['activeRunCount']);
        $this->assertFalse($params['canChangeSteps'], 'an active run locks the steps, for admins too');
        $this->assertNull($params['stepForm'], 'no Add Step form while the steps are locked');
        $this->assertInstanceOf(WorkflowStepsTable::class, $params['stepsTable']);
        $this->assertInstanceOf(User::class, $params['triggerUser']);
        $this->assertSame($s['member']->id, $params['triggerUser']->id, 'the user who generated the token');
    }

    /**
     * A user who may not assign approval rules is offered neither approval
     * rules nor job templates with an approval rule, and the steps of a
     * workflow with a gated step are locked for them, with the reason.
     */
    public function testAnOperatorGetsNoGatedChoicesAndTheLock(): void
    {
        $s = $this->teamScope();
        $this->gate($s['own'], $s);
        $gated = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $open = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $page = new WorkflowTemplatePage($s['member']->id);

        $params = $page->viewParams($gated);

        $this->assertTrue($params['canOperate']);
        $this->assertTrue($params['canChange']);
        $this->assertSame([$s['open']->id => $s['open']->name], $params['jobTemplateOptions'], 'operable and without a rule');
        $this->assertSame([], $params['approvalRuleOptions']);
        $this->assertFalse($params['mayAssignRules']);
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $params['stepsLock']);
        $this->assertFalse($params['canChangeSteps']);
        $this->assertNull($params['stepForm']);
        $this->assertSame([], $params['activeRunIds']);
        $this->assertSame(0, $params['activeRunCount']);
        $this->assertNull($params['triggerUser'], 'no token');

        $open = $page->viewParams($open);
        $this->assertNull($open['stepsLock'], 'nothing gated, nothing locked');
        $this->assertTrue($open['canChangeSteps']);
        $this->assertInstanceOf(WorkflowStepFormView::class, $open['stepForm']);
    }

    /**
     * A user who may only view the workflow is offered nothing to add or
     * change.
     */
    public function testAViewerOfTheWorkflowIsOfferedNoSteps(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);

        $params = (new WorkflowTemplatePage($s['member']->id))->viewParams($workflow);

        $this->assertFalse($params['canOperate']);
        $this->assertFalse($params['canChange']);
        $this->assertFalse($params['canChangeSteps']);
        $this->assertSame([], $params['jobTemplateOptions']);
        $this->assertSame([], $params['approvalRuleOptions']);
        $this->assertNull($params['stepForm']);
    }

    /**
     * Changing the steps needs workflow-template.update besides operator
     * access to the projects: a member of the operating team whose role
     * only views changes nothing. A superadmin needs no role.
     */
    public function testChangingNeedsTheUpdatePermissionWhichSuperadminsHave(): void
    {
        $s = $this->teamScope('viewer');
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $superadmin = $this->createUser('page-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $viewer = (new WorkflowTemplatePage($s['member']->id))->viewParams($workflow);
        $this->assertTrue($viewer['canOperate'], 'the team operates the project');
        $this->assertFalse($viewer['canChange'], 'the viewer role has no workflow-template.update');
        $this->assertFalse($viewer['canChangeSteps']);
        $this->assertNull($viewer['stepForm']);

        $super = (new WorkflowTemplatePage((int)$superadmin->id))->viewParams($workflow);
        $this->assertTrue($super['canChange']);
        $this->assertTrue($super['canChangeSteps']);
    }

    /**
     * The runs, and what they do, are shown only to a user who may see runs
     * (workflow.view); the runs lock the steps all the same, and an editor
     * still gets their IDs for the notice that says so.
     */
    public function testRunsAreShownOnlyToUsersWhoMaySeeRuns(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $editor = $this->userWithPermissions('page-no-runs', ['workflow-template.view', 'workflow-template.update']);

        $params = (new WorkflowTemplatePage((int)$editor->id))->viewParams($workflow);

        $this->assertSame([], $params['activeRuns']);
        $this->assertSame([], $params['runStates']);
        $this->assertSame([(int)$run->id], $params['activeRunIds']);
        $this->assertSame(1, $params['activeRunCount']);
        $this->assertTrue($params['canChange']);
        $this->assertFalse($params['canChangeSteps'], 'the run locks the steps');
    }

    /**
     * The page lists the oldest active runs, as many as the notice names
     * (WorkflowStepChangeGuard::RUN_LIST_LIMIT), counts all of them, and
     * knows what every one of them is doing.
     */
    public function testTheOldestActiveRunsAreListedAndAllAreCounted(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $runs = [];
        for ($i = 0; $i < 12; $i++) {
            $runs[] = (int)$this->createWorkflowJob((int)$workflow->id, $s['admin']->id)->id;
        }
        $this->createWorkflowJob((int)$workflow->id, $s['admin']->id, WorkflowJob::STATUS_FAILED);

        $params = (new WorkflowTemplatePage($s['admin']->id))->viewParams($workflow);

        $listed = array_slice($runs, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT);
        $this->assertSame($listed, $params['activeRunIds']);
        $this->assertSame($listed, array_map(static fn (WorkflowJob $r): int => (int)$r->id, $params['activeRuns']));
        $this->assertSame(12, $params['activeRunCount']);
        $this->assertSame($runs, array_keys($params['runStates']));
    }

    /**
     * The steps table marks the steps runs wait at: the current step of a
     * run that waits for an approval, a resume or a runner. A run that runs
     * a step, one between two steps, and one waiting at a step removed since
     * mark nothing.
     */
    public function testWaitingStepsCountsTheRunsThatWaitAtEachStep(): void
    {
        $states = [
            1 => $this->runState(1, 5, Display::STATE_PAUSED, Display::WAITING_FOR_RESUME),
            2 => $this->runState(2, 5, Display::STATE_PAUSED, Display::WAITING_FOR_RESUME),
            3 => $this->runState(3, 7, Display::STATE_AWAITING_APPROVAL, Display::WAITING_FOR_APPROVAL),
            4 => $this->runState(4, 7, Display::STATE_RUNNING, null),
            5 => $this->runState(5, 9, Display::STATE_QUEUED, Display::WAITING_FOR_RUNNER),
            6 => new RunState(6, WorkflowJob::STATUS_RUNNING, Display::STATE_RUNNING, null, null, []),
            7 => $this->runState(7, null, Display::STATE_PAUSED, Display::WAITING_FOR_RESUME),
        ];

        $this->assertSame([5 => 2, 7 => 1, 9 => 1], WorkflowTemplatePage::waitingSteps($states));
        $this->assertSame([], WorkflowTemplatePage::waitingSteps([]));
    }

    // -- The edit page ----------------------------------------------------------------

    /**
     * The edit page gets the stored step (the model the route map read, with
     * its relations), its position, the stored step as the form's step, the
     * active runs, and, after a refusal, the step as typed and why.
     */
    public function testTheEditPageGetsTheStepItsPositionAndTheRuns(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $second = $this->stepAt($workflow, 1);
        $page = new WorkflowTemplatePage($s['admin']->id);

        $params = $page->editParams($workflow, $second, null, null);

        $this->assertSame(
            [
                'model', 'step', 'position', 'form', 'formModel', 'jobTemplateOptions', 'approvalRuleOptions',
                'mayAssignRules', 'error', 'routedBy', 'activeRunIds',
            ],
            array_keys($params),
            'the parameters views/workflow-template/edit-step.php reads'
        );
        $this->assertSame($workflow, $params['model']);
        $this->assertSame((int)$second->id, (int)$params['step']->id);
        $this->assertTrue($params['step']->isRelationPopulated('jobTemplate'), 'the step the route map read');
        $this->assertSame(2, $params['position']);
        $this->assertInstanceOf(WorkflowStepFormView::class, $params['form']);
        $this->assertSame($params['step'], $params['formModel'], 'the stored step');
        $this->assertSame($s['open']->name, $params['jobTemplateOptions'][$s['open']->id] ?? null);
        $this->assertTrue($params['mayAssignRules']);
        $this->assertNull($params['error']);
        $this->assertSame([], $params['activeRunIds']);

        $run = $this->createWorkflowJob((int)$workflow->id, $s['admin']->id);
        $typed = WorkflowTemplatePage::postedStep($second, ['name' => '']);
        $refused = $page->editParams($workflow, $second, $typed, 'Step not saved: Name cannot be blank.', WorkflowStepPosition::KIND_START);
        $this->assertSame($typed, $refused['formModel']);
        $this->assertSame('Step not saved: Name cannot be blank.', $refused['error']);
        $this->assertSame([(int)$run->id], $refused['activeRunIds']);

        $elsewhere = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $elsewhere);
        try {
            $page->editParams($workflow, $elsewhere, null, null);
            $this->fail('Expected a WorkflowStepChangeRefusedException.');
        } catch (WorkflowStepChangeRefusedException $e) {
            $this->assertSame(WorkflowStepChangeRefusedException::NOT_FOUND, $e->status, 'a step the workflow does not have (any more)');
            $this->assertSame(sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$workflow->id, (int)$elsewhere->id), $e->getMessage());
        }
    }

    /**
     * The edit page lists the other steps whose stored On Success, On
     * Failure or Always names the step, by position: exactly those that keep
     * it from being removed. A branch no run takes (a pause step's On
     * Failure, an On Success that Always overrides) is listed with a note
     * saying so; an empty branch that goes on with the next step and the
     * step's own loop are left out.
     */
    public function testTheEditPageNamesTheStepsThatRouteToTheStep(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $build = $this->named($this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id), 'Build');
        $test = $this->named($this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id), 'Test');
        $deploy = $this->named($this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_JOB, $s['own']->id), 'Deploy');
        $gate = $this->named($this->createWorkflowStep((int)$workflow->id, 40, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id), 'Gate');
        $wait = $this->named($this->createWorkflowStep((int)$workflow->id, 50, WorkflowStep::TYPE_PAUSE), 'Wait');
        $notify = $this->named($this->createWorkflowStep((int)$workflow->id, 60, WorkflowStep::TYPE_JOB, $s['own']->id), 'Notify <b>');
        $shadow = $this->named($this->createWorkflowStep((int)$workflow->id, 70, WorkflowStep::TYPE_JOB, $s['own']->id), 'Shadow');
        $this->route($build, ['on_failure_step_id' => (int)$deploy->id]);
        $this->route($deploy, ['on_failure_step_id' => (int)$deploy->id]);
        $this->route($gate, ['on_failure_step_id' => (int)$deploy->id]);
        $this->route($wait, ['on_failure_step_id' => (int)$deploy->id]);
        $this->route($notify, ['on_always_step_id' => (int)$deploy->id]);
        $this->route($shadow, ['on_success_step_id' => (int)$deploy->id, 'on_always_step_id' => WorkflowStep::END_WORKFLOW]);
        $page = new WorkflowTemplatePage($s['admin']->id);

        $this->assertSame(
            [
                ['label' => '1. Build', 'note' => null],
                ['label' => '4. Gate', 'note' => null],
                ['label' => '5. Wait', 'note' => WorkflowStepFormView::ROUTED_BY_PAUSE_FAILURE],
                ['label' => '6. Notify <b>', 'note' => null],
                ['label' => '7. Shadow', 'note' => WorkflowStepFormView::ROUTED_BY_ALWAYS_WINS],
            ],
            $page->editParams($workflow, $deploy, null, null)['routedBy'],
            'Test goes on with Deploy only through its empty branches'
        );
        $this->assertSame([], $page->editParams($workflow, $build, null, null)['routedBy']);
        $this->assertSame([], $page->editParams($workflow, $test, null, null)['routedBy'], 'only the empty branches of Build lead to Test');
    }

    /**
     * regression: Steps that route here left out steps that block the
     * removal. It listed only branches that runs take, while a removal is
     * refused for every stored branch: an On Success that the Always branch
     * overrides, a pause step's On Failure from an older version, a branch
     * of a step of an unknown type. The page read "No other step routes
     * here.", and Remove was refused naming these steps.
     */
    public function testRegressionStepsThatRouteHereAreTheStepsThatBlockTheRemoval(): void
    {
        $s = $this->teamScope();
        $gone = $this->createJobTemplate(
            (int)$this->createProject($s['admin']->id)->id,
            (int)$this->createInventory($s['admin']->id)->id,
            (int)$this->createRunnerGroup($s['admin']->id)->id,
            $s['admin']->id
        );
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $a = $this->named($this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id), 'A');
        $b = $this->named($this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id), 'B');
        $c = $this->named($this->createWorkflowStep((int)$workflow->id, 30, WorkflowStep::TYPE_JOB, $s['own']->id), 'C');
        $p = $this->named($this->createWorkflowStep((int)$workflow->id, 40, WorkflowStep::TYPE_PAUSE), 'P');
        $u = $this->named($this->createWorkflowStep((int)$workflow->id, 50, 'JOB', $s['own']->id), 'U');
        $g = $this->named($this->createWorkflowStep((int)$workflow->id, 60, WorkflowStep::TYPE_JOB, (int)$gone->id), 'G');
        $gone->softDelete();
        $this->route($a, ['on_success_step_id' => (int)$b->id, 'on_always_step_id' => (int)$c->id]);
        $this->route($p, ['on_failure_step_id' => (int)$b->id]);
        $this->route($u, ['on_success_step_id' => (int)$b->id]);
        $this->route($g, ['on_failure_step_id' => (int)$b->id]);

        $routedBy = (new WorkflowTemplatePage($s['admin']->id))->editParams($workflow, $b, null, null)['routedBy'];

        try {
            $this->writer()->remove($workflow, (int)$b->id, $s['admin']->id, WorkflowStepWriteService::SOURCE_WEB);
            $this->fail('Expected a WorkflowStepChangeRefusedException.');
        } catch (WorkflowStepChangeRefusedException $e) {
            $this->assertSame(WorkflowStepChangeRefusedException::CONFLICT, $e->status);
            $this->assertSame([(int)$a->id, (int)$p->id, (int)$u->id, (int)$g->id], $e->details['step_ids'] ?? null);
            $this->assertStringContainsString('(step 1 "A", step 4 "P", step 5 "U", step 6 "G")', $e->getMessage());
        }
        $this->assertSame(
            [
                ['label' => '1. A', 'note' => 'Not used: its Always branch is taken instead.'],
                ['label' => '4. P', 'note' => 'Not used: a pause step does not fail. Saving that step clears its On Failure.'],
                ['label' => '5. U', 'note' => 'Not used: that step has an unknown type and cannot be saved. Remove it first.'],
                ['label' => '6. G', 'note' => 'Not used: that step cannot run.'],
            ],
            $routedBy
        );
    }

    /**
     * The note under a pause step says what clears its On Failure: its
     * edit form has no such field, and a save of what it posts clears it.
     */
    public function testSavingThePauseStepClearsTheOnFailureItsNoteNames(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $wait = $this->named($this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE), 'Wait');
        $deploy = $this->named($this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id), 'Deploy');
        $this->route($wait, ['on_failure_step_id' => (int)$deploy->id]);
        $page = new WorkflowTemplatePage($s['admin']->id);
        $this->assertSame(
            [['label' => '1. Wait', 'note' => WorkflowStepFormView::ROUTED_BY_PAUSE_FAILURE]],
            $page->editParams($workflow, $deploy, null, null)['routedBy']
        );

        $this->writer()->update(
            $workflow,
            (int)$wait->id,
            WorkflowTemplatePage::formCommand(['name' => 'Wait', 'on_success_step_id' => ''], WorkflowStepPosition::WEB_KEEP, true),
            $s['admin']->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame([], $page->editParams($workflow, $deploy, null, null)['routedBy']);
    }

    // -- The requests of the step forms ------------------------------------------------

    /**
     * The step form's request is a WorkflowStepCommand; a value the form
     * does not offer is refused (422) with the reader's text.
     */
    public function testTheStepFormPostsACommand(): void
    {
        $command = WorkflowTemplatePage::formCommand(['name' => 'Build', 'step_type' => WorkflowStep::TYPE_PAUSE], '', false);
        $this->assertSame('Build', $command->value('name'));
        $this->assertSame(WorkflowStepPosition::KIND_END, $command->position()?->kind(), 'Add Step: at the end by default');

        $edit = WorkflowTemplatePage::formCommand(['name' => 'Build'], WorkflowStepPosition::WEB_KEEP, true);
        $this->assertNull($edit->position(), 'Edit Step: kept where it is');

        $this->assertFalse(WorkflowTemplatePage::formCommand('not a form', null, false)->has('name'), 'no fields');

        $this->assertRefused(
            sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'),
            static fn () => WorkflowTemplatePage::formCommand(['on_success_step_id' => 'abc'], null, false)
        );
        $this->assertRefused(
            WorkflowStepPosition::FORM_INVALID,
            static fn () => WorkflowTemplatePage::formCommand(['name' => 'x'], 'first', false)
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: string, 3: int|null}>
     */
    public static function moveProvider(): array
    {
        return [
            '▲' => ['up', null, WorkflowStepPosition::KIND_UP, null],
            '▼' => ['down', '', WorkflowStepPosition::KIND_DOWN, null],
            'to the start' => [null, 'start', WorkflowStepPosition::KIND_START, null],
            'to the end' => ['', 'end', WorkflowStepPosition::KIND_END, null],
            'after a step' => [null, 'after:12', WorkflowStepPosition::KIND_AFTER, 12],
        ];
    }

    /**
     * @dataProvider moveProvider
     */
    public function testAMoveTakesADirectionOrAPosition(mixed $direction, mixed $position, string $kind, ?int $after): void
    {
        $to = WorkflowTemplatePage::movePosition($direction, $position);

        $this->assertSame($kind, $to->kind());
        $this->assertSame($after, $to->stepId());
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: string}>
     */
    public static function refusedMoveProvider(): array
    {
        return [
            'neither' => [null, null, WorkflowStepPosition::FORM_INVALID],
            'both empty' => ['', '', WorkflowStepPosition::FORM_INVALID],
            'both' => ['up', 'start', WorkflowStepPosition::FORM_INVALID],
            'another direction' => ['sideways', null, WorkflowStepPosition::DIRECTION_INVALID],
            'a direction as a list' => [['up'], null, WorkflowStepPosition::DIRECTION_INVALID],
            'another position' => [null, 'middle', WorkflowStepPosition::FORM_INVALID],
            'keep is no move' => [null, WorkflowStepPosition::WEB_KEEP, WorkflowStepPosition::FORM_INVALID],
            'after no step' => [null, 'after:abc', WorkflowStepPosition::FORM_INVALID],
        ];
    }

    /**
     * Exactly one of direction and position, each with a value the page
     * offers; anything else is refused (422) before a step is looked up.
     *
     * @dataProvider refusedMoveProvider
     */
    public function testAMoveWithoutExactlyOneValidPlaceIsRefused(mixed $direction, mixed $position, string $message): void
    {
        $this->assertRefused($message, static fn () => WorkflowTemplatePage::movePosition($direction, $position));
    }

    /**
     * The step shown again after a refused edit: the name and targets as
     * typed, the branches as chosen (a branch left out or empty is none),
     * the stored type; the stored step stays as it is.
     */
    public function testThePostedStepIsTheStoredOneAsTyped(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $stored = $this->stepAt($workflow, 0);
        $this->route($stored, ['on_success_step_id' => (int)$stored->id, 'on_always_step_id' => WorkflowStep::END_WORKFLOW]);

        $posted = WorkflowTemplatePage::postedStep($stored, [
            'name' => '',
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'job_template_id' => (string)$s['open']->id,
            'approval_rule_id' => ['not', 'scalar'],
            'on_success_step_id' => '',
            'on_failure_step_id' => '0',
        ]);

        $this->assertNotSame($stored, $posted);
        $this->assertSame((int)$stored->id, (int)$posted->id);
        $this->assertSame('', $posted->name);
        $this->assertSame(WorkflowStep::TYPE_JOB, $posted->step_type, 'the type stays');
        $this->assertSame((string)$s['open']->id, (string)$posted->job_template_id);
        $this->assertNull($posted->approval_rule_id, 'a value the form cannot post is ignored');
        $this->assertNull($posted->on_success_step_id, 'chosen empty');
        $this->assertSame('0', (string)$posted->on_failure_step_id, 'end the workflow');
        $this->assertNull($posted->on_always_step_id, 'left out');
        $this->assertSame('step-0', $stored->name, 'the stored step is not changed');
        $this->assertSame((int)$stored->id, (int)$stored->on_success_step_id);

        $nothing = WorkflowTemplatePage::postedStep($stored, 'not a form');
        $this->assertSame('step-0', $nothing->name, 'no fields: the name stays');
        $this->assertNull($nothing->on_success_step_id, 'no branch chosen');
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Give $template an approval rule whose approvers are the admin and the
     * member of $s, which makes its job steps gated.
     *
     * @param array{member: User, admin: User} $s
     */
    private function gate(JobTemplate $template, array $s): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            $s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$s['admin']->id, $s['member']->id]])
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);

        return $rule;
    }

    /**
     * A user whose only role is a custom one with $permissions.
     *
     * @param list<string> $permissions
     */
    private function userWithPermissions(string $suffix, array $permissions): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('page-role-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    private function runState(int $runId, ?int $stepId, string $state, ?string $waitingFor): RunState
    {
        $step = new StepState(
            $runId * 10,
            $stepId,
            'step',
            WorkflowStep::TYPE_PAUSE,
            'running',
            $state,
            $waitingFor,
            null,
            null,
            null,
            time(),
            null,
            null,
            null,
            null,
            null
        );

        return new RunState($runId, WorkflowJob::STATUS_RUNNING, $state, $waitingFor, $step, []);
    }

    private function writer(): WorkflowStepWriteService
    {
        /** @var WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        return $writer;
    }

    private function stepAt(WorkflowTemplate $workflow, int $index): WorkflowStep
    {
        $step = $workflow->getSteps()->offset($index)->one();
        $this->assertInstanceOf(WorkflowStep::class, $step);

        return $step;
    }

    private function named(WorkflowStep $step, string $name): WorkflowStep
    {
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * @param array<string, int> $routes
     */
    private function route(WorkflowStep $step, array $routes): void
    {
        foreach ($routes as $field => $target) {
            $step->setAttribute($field, $target);
        }
        $step->save(false);
    }

    private function assertRefused(string $message, callable $read): void
    {
        try {
            $read();
            $this->fail('Expected a WorkflowStepChangeRefusedException.');
        } catch (WorkflowStepChangeRefusedException $e) {
            $this->assertSame(WorkflowStepChangeRefusedException::INVALID, $e->status);
            $this->assertSame($message, $e->getMessage());
        }
    }
}
