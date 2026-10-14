<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowRouteStep;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * WorkflowRouteMap read from the database (contract C7): forTemplate() lists
 * the steps in run order (by step order, ties by ID) at the same cost for 3
 * and for 20 steps, and each step says what a run meets there: a gated job
 * step names its rule, and a step whose job template was deleted, whose
 * rule is gone or whose type is unknown (compared exactly: "JOB") cannot
 * run. The map's logic on steps in memory is WorkflowRouteMapTest.
 */
class WorkflowRouteMapLoadTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operator('route-map');
    }

    public function testTheStepsComeInRunOrderWithTiesById(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $late = $this->namedStep($workflow, 20, 'late');
        $tiedFirst = $this->namedStep($workflow, 10, 'tied first');
        $tiedSecond = $this->namedStep($workflow, 10, 'tied second');
        $first = $this->namedStep($workflow, 5, 'first');
        $this->namedStep($this->createWorkflowTemplate((int)$this->user->id), 1, 'another workflow');

        $map = WorkflowRouteMap::forTemplate($workflow);

        $this->assertSame(
            [(int)$first->id, (int)$tiedFirst->id, (int)$tiedSecond->id, (int)$late->id],
            array_map(static fn (WorkflowRouteStep $step): int => $step->id, $map->steps())
        );
        $this->assertSame(['first', 'tied first', 'tied second', 'late'], array_map(
            static fn (WorkflowRouteStep $step): string => $step->name,
            $map->steps()
        ));
        $this->assertSame([2, 3], [$map->position((int)$tiedFirst->id), $map->position((int)$tiedSecond->id)]);
        $this->assertSame(
            ['kind' => 'step', 'via' => 'next', 'step_id' => (int)$tiedSecond->id, 'position' => 3, 'run_outcome' => null, 'loop' => false],
            $map->route($map->steps()[1], WorkflowRouteMap::OUTCOME_SUCCESS)?->toArray(),
            'a step that shares its step order with the next one does not skip it'
        );
    }

    public function testAWorkflowWithoutStepsHasAnEmptyMap(): void
    {
        $map = WorkflowRouteMap::forTemplate($this->createWorkflowTemplate((int)$this->user->id));

        $this->assertSame([], $map->steps());
        $this->assertNull($map->start());
    }

    /**
     * The template page used to load each step's job template and rule one
     * by one (an N+1); the map costs the same for 3 and for 20 steps.
     */
    public function testTheCostDoesNotGrowWithTheSteps(): void
    {
        $three = $this->mixedWorkflow(1);
        $twenty = $this->mixedWorkflow(6);
        $this->assertCount(3, WorkflowRouteMap::forTemplate($three)->steps());
        $this->assertCount(20, WorkflowRouteMap::forTemplate($twenty)->steps());

        $forThree = $this->queriesOf(static fn () => WorkflowRouteMap::forTemplate($three));
        $forTwenty = $this->queriesOf(static fn () => WorkflowRouteMap::forTemplate($twenty));

        $this->assertSame($forThree, $forTwenty);
        $this->assertLessThanOrEqual(4, $forTwenty, 'steps, job templates, their rules, the approval steps\' rules');
    }

    public function testAGatedJobStepNamesItsRule(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->user->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->user->id, $rule)->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$this->user->id)->id);

        [$gated, $open] = WorkflowRouteMap::forTemplate($workflow)->steps();

        $this->assertSame([$rule->name, null], [$gated->gatedRuleName, $gated->problem]);
        $this->assertSame([null, null], [$open->gatedRuleName, $open->problem]);
        $this->assertSame(
            ['success', 'failure', 'closed_gate'],
            WorkflowRouteMap::forTemplate($workflow)->outcomes($gated)
        );
    }

    /**
     * A deleted job template runs nothing: the step cannot run, and it is no
     * gated step, even when the template had an approval rule (as
     * ApprovalRuleAssignmentPolicy::hasGatedJobStep() counts it).
     */
    public function testAStepWhoseJobTemplateIsGoneCannotRun(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->user->id);
        $deleted = $this->gatedTemplate((int)$this->user->id, $rule);
        $deleted->softDelete();
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$deleted->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB);

        $map = WorkflowRouteMap::forTemplate($workflow);
        [$gone, $none] = $map->steps();

        $this->assertSame([null, 'job_template_missing'], [$gone->gatedRuleName, $gone->problem]);
        $this->assertSame(['success', 'failure'], $map->outcomes($gone));
        $this->assertSame([null, 'job_template_missing'], [$none->gatedRuleName, $none->problem], 'no job template at all');
        $this->assertSame('cannot_run', $map->successPath()['end']);
    }

    /**
     * A rule cannot be deleted while a job template uses it (m000083); should
     * the row be gone all the same, the launch of the job is refused, so the
     * step cannot run.
     */
    public function testAGatedJobStepWhoseRuleIsGoneCannotRun(): void
    {
        $template = $this->openTemplate((int)$this->user->id);
        $this->withoutForeignKeyChecks(static function () use ($template): void {
            JobTemplate::updateAll(['approval_rule_id' => 987654321], ['id' => $template->id]);
        });
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);

        [$step] = WorkflowRouteMap::forTemplate($workflow)->steps();

        $this->assertSame(['', 'approval_rule_missing'], [$step->gatedRuleName, $step->problem]);
    }

    public function testAnApprovalStepNeedsItsRule(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->user->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL);
        $gone = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_APPROVAL);
        $this->withoutForeignKeyChecks(static function () use ($gone): void {
            WorkflowStep::updateAll(['approval_rule_id' => 987654321], ['id' => $gone->id]);
        });
        $this->createWorkflowStep((int)$workflow->id, 3, WorkflowStep::TYPE_PAUSE);

        [$ruled, $ruleless, $ruleGone, $pause] = WorkflowRouteMap::forTemplate($workflow)->steps();

        $this->assertSame([null, null], [$ruled->gatedRuleName, $ruled->problem]);
        $this->assertSame([null, 'approval_rule_missing'], [$ruleless->gatedRuleName, $ruleless->problem]);
        $this->assertSame([null, 'approval_rule_missing'], [$ruleGone->gatedRuleName, $ruleGone->problem]);
        $this->assertSame([null, null], [$pause->gatedRuleName, $pause->problem]);
    }

    /**
     * The type is compared exactly, as dispatch compares it: the column's
     * collation would let "JOB" pass for "job" in SQL.
     */
    public function testAnUnknownTypeIsComparedExactly(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, 'JOB', (int)$this->openTemplate((int)$this->user->id)->id);

        $map = WorkflowRouteMap::forTemplate($workflow);
        [$step] = $map->steps();

        $this->assertSame(['JOB', null, 'unknown_type'], [$step->type, $step->gatedRuleName, $step->problem]);
        $this->assertSame([], $map->outcomes($step));
    }

    public function testAStepReadsItsBranchesAsStored(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->namedStep($workflow, 0, 'first');
        $second = $this->namedStep($workflow, 1, 'second');
        $this->branchTo($first, 'on_success_step_id', (int)$second->id);
        $this->branchTo($first, 'on_failure_step_id', 0);
        $this->branchTo($second, 'on_always_step_id', (int)$first->id);

        [$one, $two] = WorkflowRouteMap::forTemplate($workflow)->steps();

        $this->assertSame([(int)$first->id, 'first', 'job'], [$one->id, $one->name, $one->type]);
        $this->assertSame([(int)$second->id, 0, null], [$one->onSuccess, $one->onFailure, $one->onAlways]);
        $this->assertSame([null, null, (int)$first->id], [$two->onSuccess, $two->onFailure, $two->onAlways]);
    }

    /**
     * A step that is not saved yet (an add preview) gets NEW_STEP_ID, and
     * branches as a form posts them are read as numbers, empty as none.
     */
    public function testAStepThatIsNotSavedYetIsTheNewStep(): void
    {
        $step = new WorkflowStep();
        $step->name = 'preview';
        $step->step_type = WorkflowStep::TYPE_PAUSE;
        $step->on_success_step_id = '';
        $step->on_failure_step_id = '12';

        $route = WorkflowRouteStep::fromModel($step);

        $this->assertSame([WorkflowRouteStep::NEW_STEP_ID, 'preview', 'pause'], [$route->id, $route->name, $route->type]);
        $this->assertSame([null, 12, null], [$route->onSuccess, $route->onFailure, $route->onAlways]);
        $this->assertSame([null, null], [$route->gatedRuleName, $route->problem]);
    }

    /**
     * fromModels() loads what the map reads in one batch for the models that
     * lack it, and reads nothing for models that have it: stepsOf() loads it
     * with the steps, so a page that shows them builds the map for free.
     */
    public function testFromModelsLoadsWhatIsMissingInOneBatch(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->user->id);
        $workflow = $this->mixedWorkflow(2, $rule);
        WorkflowRouteMap::forTemplate($workflow);

        $loaded = WorkflowRouteMap::stepsOf($workflow);
        $bare = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])->all();
        $this->assertCount(8, $loaded);
        foreach ($loaded as $model) {
            $this->assertTrue($model->isRelationPopulated('jobTemplate') && $model->isRelationPopulated('approvalRule'));
        }

        $this->assertSame(0, $this->queriesOf(static fn () => WorkflowRouteMap::fromModels($loaded)));
        $this->assertSame(3, $this->queriesOf(static fn () => WorkflowRouteMap::fromModels($bare)), 'job templates, their rules, rules');
        $this->assertEquals(WorkflowRouteMap::fromModels($loaded), WorkflowRouteMap::fromModels($bare));
    }

    /**
     * A model whose job template is loaded without its approval rule gets
     * that rule loaded too, rather than one query per step.
     */
    public function testAJobTemplateWithoutItsRuleLoadedIsCompleted(): void
    {
        $rule = $this->usersRuleOf((int)$this->user->id, (int)$this->user->id);
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate((int)$this->user->id, $rule)->id);
        $models = $workflow->getSteps()->with(['jobTemplate', 'approvalRule'])->all();
        $this->assertCount(1, $models);
        $this->assertInstanceOf(WorkflowStep::class, $models[0]);

        $map = WorkflowRouteMap::fromModels([$models[0]]);

        $this->assertSame($rule->name, $map->steps()[0]->gatedRuleName);
        $this->assertNotNull($models[0]->jobTemplate);
        $this->assertTrue($models[0]->jobTemplate->isRelationPopulated('approvalRule'));
    }

    // -- Helpers ------------------------------------------------------------------------

    private function namedStep(WorkflowTemplate $workflow, int $order, string $name): WorkflowStep
    {
        $step = $this->createWorkflowStep((int)$workflow->id, $order);
        WorkflowStep::updateAll(['name' => $name], ['id' => $step->id]);
        $step->refresh();

        return $step;
    }

    /**
     * A workflow of $rounds rounds of three steps: a job step, a gated job
     * step and an approval step; with more than one round, a pause step and
     * a job step without a job template follow (3 steps for one round, 20
     * for six). Every job step has a job template of its own, and every
     * gated job step and approval step a rule of its own, unless $rule is
     * given for all of them.
     */
    private function mixedWorkflow(int $rounds, ?ApprovalRule $rule = null): WorkflowTemplate
    {
        $userId = (int)$this->user->id;
        $workflow = $this->createWorkflowTemplate($userId);
        $order = 0;
        for ($round = 0; $round < $rounds; $round++) {
            $gateRule = $rule ?? $this->usersRuleOf($userId, $userId);
            $this->createWorkflowStep((int)$workflow->id, $order++, WorkflowStep::TYPE_JOB, (int)$this->openTemplate($userId)->id);
            $this->createWorkflowStep((int)$workflow->id, $order++, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate($userId, $gateRule)->id);
            $this->createWorkflowStep((int)$workflow->id, $order++, WorkflowStep::TYPE_APPROVAL, null, (int)($rule ?? $this->usersRuleOf($userId, $userId))->id);
        }
        if ($rounds > 1) {
            $this->createWorkflowStep((int)$workflow->id, $order++, WorkflowStep::TYPE_PAUSE);
            $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB);
        }

        return $workflow;
    }

    /**
     * Run $change with the foreign keys unchecked, to store a reference
     * that the schema refuses (a row gone as only older versions left it).
     */
    private function withoutForeignKeyChecks(callable $change): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $change();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }
}
