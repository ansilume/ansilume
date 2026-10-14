<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\WorkflowStepSerializer;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;

/**
 * The steps of a workflow as the API answers them (WorkflowStepSerializer):
 * the stored fields with only the target of each step's type, then the
 * position in run order and what the route map works out; and the
 * workflow's start step and success path.
 */
class WorkflowStepSerializerTest extends DbTestCase
{
    use CountsQueries;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('serializer')->id;
    }

    /**
     * Every field of every kind of step: a job step, an approval step, a
     * gated job step and a pause step, with their branches resolved as the
     * engine routes a run.
     */
    public function testEveryStepIsListedByPositionWithWhereItsBranchesLead(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $template = $this->template();
        $rule = $this->createApprovalRule($this->userId);
        $gated = $this->template((int)$rule->id);
        $build = $this->step($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$template->id);
        $approve = $this->step($workflow, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $deploy = $this->step($workflow, 'Deploy', 30, WorkflowStep::TYPE_JOB, (int)$gated->id);
        $wait = $this->step($workflow, 'Wait', 40, WorkflowStep::TYPE_PAUSE);
        WorkflowStep::updateAll(['on_failure_step_id' => $wait, 'extra_vars_template' => '{"env": "target_env"}'], ['id' => $deploy]);
        WorkflowStep::updateAll(['on_success_step_id' => $build], ['id' => $wait]);

        $steps = WorkflowStepSerializer::forTemplate($workflow)->steps();

        $this->assertSame(['Build', 'Approve', 'Deploy', 'Wait'], array_column($steps, 'name'));
        $this->assertSame([
            'id' => $deploy,
            'name' => 'Deploy',
            'step_order' => 30,
            'step_type' => 'job',
            'job_template_id' => (int)$gated->id,
            'approval_rule_id' => null,
            'on_success_step_id' => null,
            'on_failure_step_id' => $wait,
            'on_always_step_id' => null,
            'extra_vars_template' => ['env' => 'target_env'],
            'position' => 3,
            'is_start' => false,
            'reachable' => true,
            'cannot_run' => null,
            'routes' => [
                'success' => self::route('step', 'next', $wait, 4),
                'failure' => self::route('step', 'on_failure', $wait, 4),
                'closed_gate' => self::route('step', 'on_failure', $wait, 4),
            ],
        ], $steps[2]);
        $this->assertSame(
            [(int)$template->id, null, true, null],
            [$steps[0]['job_template_id'], $steps[0]['approval_rule_id'], $steps[0]['is_start'], $steps[0]['routes']['closed_gate']]
        );
        $this->assertSame([null, (int)$rule->id], [$steps[1]['job_template_id'], $steps[1]['approval_rule_id']]);
        $this->assertSame(
            ['success' => self::route('step', 'next', $deploy, 3), 'failure' => null, 'closed_gate' => self::route('end', 'on_failure', null, null, 'fails')],
            $steps[1]['routes']
        );
        $this->assertSame(
            ['success' => self::route('step', 'on_success', $build, 1, null, true), 'failure' => null, 'closed_gate' => null],
            $steps[3]['routes'],
            'a pause step goes on with On Success only; back to the start is a loop'
        );
        $this->assertSame([1, 2, 3, 4], array_column($steps, 'position'));
        $this->assertSame([$approve], [$steps[1]['id']]);
    }

    /**
     * A step returns only the target of its type: a job template that an
     * older version saved on a pause or an approval step, or an approval
     * rule on a job step, is never shown.
     */
    public function testOnlyTheTargetOfTheStepsTypeIsShown(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $template = $this->template();
        $rule = $this->createApprovalRule($this->userId);
        $this->step($workflow, 'Wait', 10, WorkflowStep::TYPE_PAUSE, (int)$template->id, (int)$rule->id);
        $this->step($workflow, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, (int)$template->id, (int)$rule->id);
        $this->step($workflow, 'Build', 30, WorkflowStep::TYPE_JOB, (int)$template->id, (int)$rule->id);

        $steps = WorkflowStepSerializer::forTemplate($workflow)->steps();

        $this->assertSame([null, null, (int)$template->id], array_column($steps, 'job_template_id'));
        $this->assertSame([null, (int)$rule->id, null], array_column($steps, 'approval_rule_id'));
    }

    /**
     * Steps that share a step order run in ID order (C1): positions follow
     * the run order, not the sort key.
     */
    public function testTiedStepsAreListedInIdOrder(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $first = $this->step($workflow, 'First', 5, WorkflowStep::TYPE_PAUSE);
        $second = $this->step($workflow, 'Second', 5, WorkflowStep::TYPE_PAUSE);
        $zero = $this->step($workflow, 'Zero', 0, WorkflowStep::TYPE_PAUSE);

        $serializer = WorkflowStepSerializer::forTemplate($workflow);

        $this->assertSame([$zero, $first, $second], array_column($serializer->steps(), 'id'));
        $this->assertSame([1, 2, 3], array_column($serializer->steps(), 'position'));
        $this->assertSame($zero, $serializer->startStepId());
        $this->assertSame(self::route('step', 'next', $second, 3), $serializer->step($first)['routes']['success']);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function successPathProvider(): array
    {
        return [
            'the end' => ['end', 'succeeds'],
            'back to the start' => ['loop', 'loop'],
            'a step that no longer exists' => ['missing', 'missing'],
            'a step that cannot run' => ['cannot run', 'cannot_run'],
        ];
    }

    /**
     * The start step and the success path: the steps a run passes when
     * every step succeeds, and how that path ends.
     *
     * @dataProvider successPathProvider
     */
    public function testTheStartStepAndTheSuccessPath(string $case, string $end): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $first = $this->step($workflow, 'First', 10, WorkflowStep::TYPE_PAUSE);
        $second = $this->step($workflow, 'Second', 20, match ($case) {
            'cannot run' => 'JOB',
            default => WorkflowStep::TYPE_PAUSE,
        });
        $route = match ($case) {
            'loop' => $first,
            'missing' => 987654321,
            default => null,
        };
        WorkflowStep::updateAll(['on_success_step_id' => $route], ['id' => $second]);

        $serializer = WorkflowStepSerializer::forTemplate($workflow);

        $this->assertSame($first, $serializer->startStepId());
        $this->assertSame([
            'step_ids' => [$first, $second],
            'end' => $end,
            'loop_to_step_id' => $case === 'loop' ? $first : null,
            'missing_step_id' => $case === 'missing' ? 987654321 : null,
        ], $serializer->successPath());
    }

    /**
     * Steps a run fails at say why (cannot_run); a step of an unknown type
     * takes no branch at all.
     *
     * regression: a step after one that cannot run is reachable only through
     * it. A run fails at the start step, whose job template is gone, without
     * following a branch, yet the steps after it were answered reachable.
     */
    public function testAStepThatCannotRunSaysWhy(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $gone = $this->template();
        $missingTemplate = $this->step($workflow, 'Gone', 10, WorkflowStep::TYPE_JOB, (int)$gone->id);
        $gone->softDelete();
        $noRule = $this->step($workflow, 'No rule', 20, WorkflowStep::TYPE_APPROVAL);
        $legacy = $this->step($workflow, 'Legacy', 30, '1');

        $serializer = WorkflowStepSerializer::forTemplate($workflow);

        $this->assertSame('job_template_missing', $serializer->step($missingTemplate)['cannot_run']);
        $this->assertSame('approval_rule_missing', $serializer->step($noRule)['cannot_run']);
        $this->assertSame(
            ['step_type' => '1', 'cannot_run' => 'unknown_type', 'routes' => ['success' => null, 'failure' => null, 'closed_gate' => null]],
            array_intersect_key($serializer->step($legacy), ['step_type' => 0, 'cannot_run' => 0, 'routes' => 0])
        );
        $this->assertTrue($serializer->step($missingTemplate)['reachable'], 'the start step');
        $this->assertFalse($serializer->step($noRule)['reachable'], 'runs fail at the step before it');
        $this->assertFalse($serializer->step($legacy)['reachable'], 'a step that cannot run passes on nothing');
    }

    public function testAWorkflowWithoutSteps(): void
    {
        $serializer = new WorkflowStepSerializer([]);

        $this->assertSame([], $serializer->steps());
        $this->assertNull($serializer->startStepId());
        $this->assertSame(['step_ids' => [], 'end' => null, 'loop_to_step_id' => null, 'missing_step_id' => null], $serializer->successPath());
    }

    /**
     * step() answers one step as steps() lists it, and refuses a step that
     * is not among the given ones.
     */
    public function testOneStepIsAnsweredAsTheListShowsIt(): void
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $first = $this->step($workflow, 'First', 10, WorkflowStep::TYPE_PAUSE);
        $second = $this->step($workflow, 'Second', 20, WorkflowStep::TYPE_PAUSE);
        $serializer = WorkflowStepSerializer::forTemplate($workflow);

        $this->assertSame($serializer->steps()[1], $serializer->step($second));
        $this->assertSame($serializer->steps()[0], $serializer->step($first));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(WorkflowStepSerializer::NOT_GIVEN, 987654321));
        $serializer->step(987654321);
    }

    /**
     * Reading a workflow's steps costs the same number of queries however
     * many steps it has.
     */
    public function testTheQueriesDoNotGrowWithTheSteps(): void
    {
        $small = $this->workflowOf(3);
        $large = $this->workflowOf(20);
        WorkflowStepSerializer::forTemplate($small)->steps();

        $this->assertSame(
            $this->queriesOf(static fn () => WorkflowStepSerializer::forTemplate($small)->steps()),
            $this->queriesOf(static fn () => WorkflowStepSerializer::forTemplate($large)->steps())
        );
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}
     */
    private static function route(string $kind, string $via, ?int $stepId, ?int $position, ?string $runOutcome = null, bool $loop = false): array
    {
        return ['kind' => $kind, 'via' => $via, 'step_id' => $stepId, 'position' => $position, 'run_outcome' => $runOutcome, 'loop' => $loop];
    }

    private function step(WorkflowTemplate $workflow, string $name, int $order, string $type, ?int $templateId = null, ?int $ruleId = null): int
    {
        $step = $this->createWorkflowStep((int)$workflow->id, $order, $type, $templateId, $ruleId);
        $step->name = $name;
        $step->save(false);

        return (int)$step->id;
    }

    private function template(?int $ruleId = null): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        if ($ruleId !== null) {
            JobTemplate::updateAll(['approval_rule_id' => $ruleId], ['id' => $template->id]);
        }

        return $template;
    }

    /**
     * A workflow of $count steps: job steps of one job template, every
     * third an approval step.
     */
    private function workflowOf(int $count): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $template = $this->template();
        $rule = $this->createApprovalRule($this->userId);
        for ($i = 0; $i < $count; $i++) {
            $approval = $i % 3 === 2;
            $this->step(
                $workflow,
                'Step ' . $i,
                ($i + 1) * 10,
                $approval ? WorkflowStep::TYPE_APPROVAL : WorkflowStep::TYPE_JOB,
                $approval ? null : (int)$template->id,
                $approval ? (int)$rule->id : null
            );
        }

        return $workflow;
    }
}
