<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepChange;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepWriteService;
use app\tests\integration\DbTestCase;
use yii\base\ModelEvent;
use yii\base\Event;
use yii\db\ActiveRecord;

/**
 * WorkflowStepWriteService::add() (contract C5): the step as the command
 * describes it, where its position puts it, after the checks in their order:
 * the policy (403), the job template (422 or 403), the model's rules (422),
 * then, with the workflow locked, the guard (404, 403, 409), the save
 * (validated again) and the placement (422). A refusal writes nothing.
 */
class WorkflowStepWriteAddTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    private User $admin;
    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('write-add-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
    }

    public function testAStepIsAddedAtTheEndByDefaultAndReportedAsAdded(): void
    {
        $build = $this->namedStep((int)$this->workflow->id, 'Build', 10);
        $test = $this->namedStep((int)$this->workflow->id, 'Test', 20);

        $change = $this->writer()->add(
            $this->workflow,
            $this->formCommand(['name' => 'Deploy', 'step_type' => WorkflowStep::TYPE_PAUSE]),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(WorkflowStepChange::ADDED, $change->kind);
        $this->assertSame('Deploy', $change->step->name);
        $this->assertFalse($change->step->getIsNewRecord());
        $this->assertSame(0, $change->from);
        $this->assertSame(3, $change->to);
        $this->assertSame(3, $change->count());
        $this->assertSame(['Build', 'Test'], $this->names($change->stepsBefore));
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->names($change->stepsAfter));
        $this->assertSame([], $change->changes);
        $this->assertSame(WorkflowStepWriteService::SOURCE_WEB, $change->source);
        $this->assertSame(['Build', 'Test', 'Deploy'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame(
            [(int)$build->id => 10, (int)$test->id => 20, (int)$change->step->id => 30],
            array_map('intval', WorkflowStep::find()->select('step_order')->where(['workflow_template_id' => $this->workflow->id])->indexBy('id')->column())
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: int}>
     */
    public static function positionProvider(): array
    {
        return [
            'at the start' => ['start', ['New', 'Build', 'Test'], 1],
            'at the end' => ['end', ['Build', 'Test', 'New'], 3],
            'after a step' => ['after:build', ['Build', 'New', 'Test'], 2],
            'at a position from the API' => ['2', ['Build', 'New', 'Test'], 2],
        ];
    }

    /**
     * @dataProvider positionProvider
     * @param list<string> $order
     */
    public function testAStepIsAddedWhereItsPositionPutsIt(string $position, array $order, int $to): void
    {
        $build = $this->namedStep((int)$this->workflow->id, 'Build', 10);
        $this->namedStep((int)$this->workflow->id, 'Test', 20);
        $body = ['name' => 'New', 'step_type' => WorkflowStep::TYPE_PAUSE];
        $body += match ($position) {
            'after:build' => ['after_step_id' => (int)$build->id],
            '2' => ['position' => 2],
            default => ['position' => $position],
        };

        $change = $this->writer()->add($this->workflow, $this->apiCommand($body), (int)$this->admin->id, WorkflowStepWriteService::SOURCE_API);

        $this->assertSame($order, $this->runOrder((int)$this->workflow->id));
        $this->assertSame($to, $change->to);
        $this->assertSame($order, $this->names($change->stepsAfter));
    }

    /**
     * A command without a position (an edit's) adds the step at the end.
     */
    public function testACommandWithoutAPositionAddsTheStepAtTheEnd(): void
    {
        $this->namedStep((int)$this->workflow->id, 'Build', 10);

        $this->writer()->add(
            $this->workflow,
            $this->formCommand(['name' => 'New', 'step_type' => WorkflowStep::TYPE_PAUSE], null, true),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(['Build', 'New'], $this->runOrder((int)$this->workflow->id));
    }

    /**
     * A step keeps only the target of its type: a job template left on a
     * pause step would still decide who may see the workflow.
     */
    public function testOnlyTheTargetOfTheStepsTypeIsKept(): void
    {
        $template = $this->jobTemplate((int)$this->admin->id);
        $rule = $this->createApprovalRule((int)$this->admin->id);
        $both = ['job_template_id' => (string)$template->id, 'approval_rule_id' => (string)$rule->id];

        foreach ([WorkflowStep::TYPE_PAUSE => [null, null], WorkflowStep::TYPE_JOB => [(int)$template->id, null], WorkflowStep::TYPE_APPROVAL => [null, (int)$rule->id]] as $type => [$templateId, $ruleId]) {
            $change = $this->writer()->add(
                $this->workflow,
                $this->formCommand(['name' => $type, 'step_type' => $type] + $both),
                (int)$this->admin->id,
                WorkflowStepWriteService::SOURCE_WEB
            );
            $stored = WorkflowStep::findOne($change->step->id);
            $this->assertNotNull($stored);
            $this->assertSame($templateId, $stored->job_template_id, $type);
            $this->assertSame($ruleId, $stored->approval_rule_id, $type);
        }
    }

    /**
     * IDs are read as the model's integer rule reads them and stored as the
     * int that was checked ("05" and "+5" are template 5).
     */
    public function testIdsAreStoredAsTheIntsTheChecksSaw(): void
    {
        $template = $this->jobTemplate((int)$this->admin->id);
        $rule = $this->createApprovalRule((int)$this->admin->id);

        foreach (['0' . $template->id, '+' . $template->id] as $index => $posted) {
            $change = $this->writer()->add(
                $this->workflow,
                $this->formCommand(['name' => 'job-' . $index, 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => $posted]),
                (int)$this->admin->id,
                WorkflowStepWriteService::SOURCE_WEB
            );
            $this->assertSame((int)$template->id, WorkflowStep::findOne($change->step->id)?->job_template_id, $posted);
        }
        $approval = $this->writer()->add(
            $this->workflow,
            $this->formCommand(['name' => 'gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => '00' . $rule->id]),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );
        $this->assertSame((int)$rule->id, WorkflowStep::findOne($approval->step->id)?->approval_rule_id);
    }

    /**
     * A job template that does not exist for the user is 422, whatever the
     * reason, so the answer does not tell whether it exists.
     */
    public function testAJobTemplateThatDoesNotExistForTheUserIsRefused(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $deleted = $this->jobTemplate((int)$s['admin']->id);
        $deleted->softDelete();

        foreach (['unknown' => 987654321, 'deleted' => (int)$deleted->id, 'in a project of another team' => (int)$s['foreign']->id] as $case => $id) {
            $refusal = $this->refusalOf(fn () => $this->writer()->add(
                $workflow,
                $this->apiCommand(['name' => 'x', 'job_template_id' => $id]),
                (int)$s['member']->id,
                WorkflowStepWriteService::SOURCE_API
            ));
            $this->assertSame(422, $refusal->status, $case);
            $this->assertSame(WorkflowStep::JOB_TEMPLATE_NOT_FOUND, $refusal->getMessage(), $case);
            $this->assertSame(['job_template_ids' => [$id]], $refusal->details, $case);
        }
        $this->assertSame([], $this->stepRows((int)$workflow->id));
    }

    public function testAJobTemplateTheUserMaySeeButNotOperateIsForbidden(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->add(
            $workflow,
            $this->apiCommand(['name' => 'x', 'job_template_id' => (int)$s['viewed']->id]),
            (int)$s['member']->id,
            WorkflowStepWriteService::SOURCE_API
        ));

        $this->assertSame(403, $refusal->status);
        $this->assertSame(WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN, $refusal->getMessage());
        $this->assertSame(['job_template_ids' => [(int)$s['viewed']->id]], $refusal->details);
        $this->assertSame([], $this->stepRows((int)$workflow->id));

        $change = $this->writer()->add(
            $workflow,
            $this->apiCommand(['name' => 'own', 'job_template_id' => (int)$s['own']->id]),
            (int)$s['member']->id,
            WorkflowStepWriteService::SOURCE_API
        );
        $this->assertSame((int)$s['own']->id, $change->step->job_template_id, 'one the member may operate');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidStepProvider(): array
    {
        return [
            'no name' => [['step_type' => WorkflowStep::TYPE_PAUSE], 'Name cannot be blank.'],
            'no type' => [['name' => 'x', 'step_type' => ''], 'Step Type cannot be blank.'],
            'a type that is none' => [['name' => 'x', 'step_type' => 'JOB'], 'Step Type is invalid.'],
            'a job step without a job template' => [['name' => 'x', 'step_type' => WorkflowStep::TYPE_JOB], WorkflowStep::JOB_TEMPLATE_REQUIRED],
            'a job template that is no ID' => [['name' => 'x', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => 'abc'], 'Job Template Id must be an integer.'],
            'an approval step without a rule' => [['name' => 'x', 'step_type' => WorkflowStep::TYPE_APPROVAL], WorkflowStep::APPROVAL_RULE_REQUIRED],
            'an unknown approval rule' => [['name' => 'x', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => '987654321'], WorkflowStep::APPROVAL_RULE_NOT_FOUND],
            'a route to no step of the workflow' => [['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'on_success_step_id' => '987654321'], WorkflowStep::ROUTE_TARGET_OUTSIDE],
        ];
    }

    /**
     * @dataProvider invalidStepProvider
     * @param array<string, mixed> $posted
     */
    public function testTheModelsRulesRefuseAStep(array $posted, string $message): void
    {
        $refusal = $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->formCommand($posted), (int)$this->admin->id, WorkflowStepWriteService::SOURCE_WEB));

        $this->assertSame(422, $refusal->status);
        $this->assertSame($message, $refusal->getMessage());
        $this->assertSame([], $refusal->details);
        $this->assertSame([], $this->stepRows((int)$this->workflow->id));
    }

    /**
     * An approval step has no Always route, not even one that ends the
     * workflow.
     */
    public function testAnApprovalStepWithAnAlwaysRouteIsRefused(): void
    {
        $rule = $this->createApprovalRule((int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->add(
            $this->workflow,
            $this->apiCommand(['name' => 'gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (int)$rule->id, 'on_always_step_id' => 0]),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        ));

        $this->assertSame(422, $refusal->status);
        $this->assertSame(WorkflowStep::APPROVAL_ALWAYS_REFUSED, $refusal->getMessage());
    }

    /**
     * A new job step may lead anywhere in the workflow: to the end, or to a
     * step that comes later in the run order.
     */
    public function testANewStepMayRouteToTheEndOrToALaterStep(): void
    {
        $template = $this->jobTemplate((int)$this->admin->id);
        $cleanup = $this->namedStep((int)$this->workflow->id, 'Cleanup', 10);

        $change = $this->writer()->add(
            $this->workflow,
            $this->formCommand([
                'name' => 'Build',
                'step_type' => WorkflowStep::TYPE_JOB,
                'job_template_id' => (string)$template->id,
                'on_success_step_id' => '',
                'on_failure_step_id' => (string)$cleanup->id,
            ], 'start'),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(['Build', 'Cleanup'], $this->runOrder((int)$this->workflow->id));
        $this->assertNull($change->step->on_success_step_id);
        $this->assertSame((int)$cleanup->id, $change->step->on_failure_step_id);
        $ending = $this->writer()->add(
            $this->workflow,
            $this->formCommand(['name' => 'Last', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$template->id, 'on_failure_step_id' => '0']),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );
        $this->assertSame(WorkflowStep::END_WORKFLOW, $ending->step->on_failure_step_id);
    }

    // -- The order of the checks ----------------------------------------------

    /**
     * The policy (403) comes before the job template's check: an operator
     * learns nothing about a template on a workflow they may not change.
     */
    public function testThePolicyComesBeforeTheJobTemplate(): void
    {
        $operator = $this->createUserWithRole('write-add-operator', 'operator');
        $this->namedStep((int)$this->workflow->id, 'Approve', 10, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->add(
            $this->workflow,
            $this->apiCommand(['name' => '', 'job_template_id' => 987654321]),
            (int)$operator->id,
            WorkflowStepWriteService::SOURCE_API
        ));

        $this->assertSame(403, $refusal->status);
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $refusal->getMessage());
    }

    public function testTheJobTemplateComesBeforeTheModelsRules(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);

        $missing = $this->refusalOf(fn () => $this->writer()->add($workflow, $this->apiCommand(['name' => '', 'job_template_id' => 987654321]), (int)$s['member']->id, 'api'));
        $forbidden = $this->refusalOf(fn () => $this->writer()->add($workflow, $this->apiCommand(['name' => '', 'job_template_id' => (int)$s['viewed']->id]), (int)$s['member']->id, 'api'));

        $this->assertSame([422, WorkflowStep::JOB_TEMPLATE_NOT_FOUND], [$missing->status, $missing->getMessage()]);
        $this->assertSame([403, WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN], [$forbidden->status, $forbidden->getMessage()]);
    }

    /**
     * The model's rules come before the guard: an invalid step is 422 even
     * while a run is active.
     */
    public function testTheModelsRulesComeBeforeTheGuard(): void
    {
        $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->formCommand(['step_type' => WorkflowStep::TYPE_PAUSE]), (int)$this->admin->id, 'web'));

        $this->assertSame([422, 'Name cannot be blank.'], [$refusal->status, $refusal->getMessage()]);
    }

    public function testAnActiveRunRefusesTheAddWith409NamingIt(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);

        $refusal = $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->formCommand(['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE]), (int)$this->admin->id, 'web'));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]), $refusal->getMessage());
        $this->assertSame(['workflow_job_ids' => [(int)$run->id]], $refusal->details);
        $this->assertSame([], $this->stepRows((int)$this->workflow->id));
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    public function testAWorkflowDeletedMeanwhileIsNotFound(): void
    {
        $refusal = $this->meanwhile(
            fn () => WorkflowTemplate::findOne($this->workflow->id)?->softDelete(),
            fn () => $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->formCommand(['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE]), (int)$this->admin->id, 'web'))
        );

        $this->assertSame(404, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, (int)$this->workflow->id), $refusal->getMessage());
    }

    /**
     * A step the new step routes to that another request removed after the
     * checks: the save, validated again under the lock, refuses it.
     */
    public function testARouteTargetRemovedMeanwhileIsRefusedAndNothingIsWritten(): void
    {
        $target = $this->namedStep((int)$this->workflow->id, 'Target', 10);

        $refusal = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $target->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->add(
                $this->workflow,
                $this->formCommand(['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'on_success_step_id' => (string)$target->id]),
                (int)$this->admin->id,
                'web'
            ))
        );

        $this->assertSame([422, WorkflowStep::ROUTE_TARGET_OUTSIDE], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame([], $this->stepRows((int)$this->workflow->id));
        $this->assertSame([], $this->stepAudits((int)$this->workflow->id));
    }

    /**
     * The step to place it after that another request removed: the
     * placement refuses it, and the saved step is rolled back.
     */
    public function testAnAnchorRemovedMeanwhileIsRefusedAndNothingIsWritten(): void
    {
        $anchor = $this->namedStep((int)$this->workflow->id, 'Anchor', 10);
        $other = $this->namedStep((int)$this->workflow->id, 'Other', 20);

        $refusal = $this->meanwhile(
            static fn () => WorkflowStep::deleteAll(['id' => $anchor->id]),
            fn () => $this->refusalOf(fn () => $this->writer()->add(
                $this->workflow,
                $this->formCommand(['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE], 'after:' . $anchor->id),
                (int)$this->admin->id,
                'web'
            ))
        );

        $this->assertSame(422, $refusal->status);
        $this->assertSame(sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, (int)$anchor->id), $refusal->getMessage());
        $this->assertSame([(int)$other->id], array_keys($this->stepRows((int)$this->workflow->id)), 'the new step was not kept');
    }

    public function testAPositionTheStepCannotTakeIsRefused(): void
    {
        $this->namedStep((int)$this->workflow->id, 'Build', 10);

        $refusal = $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->apiCommand(['name' => 'x', 'step_type' => 'pause', 'position' => 3]), (int)$this->admin->id, 'api'));

        $this->assertSame([422, sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 2)], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame(['Build'], $this->runOrder((int)$this->workflow->id));
    }

    /**
     * A save that an event vetoes without an error is refused, not lost.
     */
    public function testASaveAnEventVetoesIsRefused(): void
    {
        $handler = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        try {
            $refusal = $this->refusalOf(fn () => $this->writer()->add($this->workflow, $this->formCommand(['name' => 'vetoed', 'step_type' => WorkflowStep::TYPE_PAUSE]), (int)$this->admin->id, 'web'));
        } finally {
            Event::off(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        }

        $this->assertSame([422, 'Validation failed.'], [$refusal->status, $refusal->getMessage()]);
        $this->assertSame([], $this->stepRows((int)$this->workflow->id));
    }

    // -- The audit entry ------------------------------------------------------

    public function testTheAuditEntryOfAnAdd(): void
    {
        $template = $this->jobTemplate((int)$this->admin->id);
        $build = $this->namedStep((int)$this->workflow->id, 'Build', 10);

        $change = $this->writer()->add(
            $this->workflow,
            $this->apiCommand([
                'name' => 'Deploy',
                'job_template_id' => (int)$template->id,
                'on_failure_step_id' => (int)$build->id,
                'on_always_step_id' => null,
                'position' => 'start',
            ]),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_API
        );

        $this->assertSame([[
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
            'user_id' => (int)$this->admin->id,
            'context' => [
                'step_id' => (int)$change->step->id,
                'step_name' => 'Deploy',
                'step_type' => WorkflowStep::TYPE_JOB,
                'job_template_id' => (int)$template->id,
                'approval_rule_id' => null,
                'on_success_step_id' => null,
                'on_failure_step_id' => (int)$build->id,
                'on_always_step_id' => null,
                'position' => 1,
                'step_count' => 2,
                'source' => 'api',
            ],
        ]], $this->stepAudits((int)$this->workflow->id));
    }
}
