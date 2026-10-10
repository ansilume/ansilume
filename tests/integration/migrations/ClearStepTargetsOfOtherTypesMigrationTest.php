<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;

/**
 * m000078_000000_clear_step_targets_of_other_types: approval and pause steps
 * lose a job template left on them by older versions, job and pause steps an
 * approval rule; every other value stays, and so do both targets of a step of
 * an unknown type. The updates run inside the test's transaction and are
 * rolled back with it.
 */
class ClearStepTargetsOfOtherTypesMigrationTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000078_000000_clear_step_targets_of_other_types.php';
    }

    public function testUpClearsTheTargetsThatDoNotBelongToTheStepType(): void
    {
        [$template, $rule, $steps] = $this->legacySteps();

        $this->up();

        $this->assertSame([$template->id, null], $this->targets($steps['job']), 'a job step keeps its job template');
        $this->assertSame([null, $rule->id], $this->targets($steps['approval']), 'an approval step keeps its rule');
        $this->assertSame([null, null], $this->targets($steps['pause']), 'a pause step has no target');
        $this->assertSame([null, null], $this->targets($steps['dangling']), 'an unknown job template ID goes too');
        $this->assertSame([$template->id, null], $this->targets($steps['clean']));
        $this->assertSame([null, $rule->id], $this->targets($steps['cleanApproval']));
    }

    /**
     * Regression: the column's collation ignores case and trailing spaces, so
     * a type that dispatch does not know, such as JOB or "approval ", lost one
     * target and kept the other, and 1 or an empty type lost both. Runs fail
     * at such steps, and the operator needs both targets to tell what to add
     * again in their place.
     */
    public function testUpKeepsBothTargetsOfAStepOfAnUnknownType(): void
    {
        [$template, $rule] = $this->legacySteps();
        $workflow = $this->createWorkflowTemplate((int)$template->created_by);
        $steps = [];
        foreach (['1', '', 'JOB', 'Pause', 'approval '] as $order => $type) {
            $steps[$type] = $this->createWorkflowStep($workflow->id, $order, $type, $template->id, $rule->id);
        }

        $this->up();

        foreach ($steps as $type => $step) {
            $this->assertSame([$template->id, $rule->id], $this->targets($step), "type '{$type}'");
        }
    }

    public function testUpChangesNothingElse(): void
    {
        [, , $steps] = $this->legacySteps();
        $before = array_map(static fn (WorkflowStep $step): array => self::otherColumns($step), $steps);

        $this->up();

        foreach ($steps as $key => $step) {
            $this->assertSame($before[$key], self::otherColumns($step), $key);
        }
    }

    public function testRunningItAgainChangesNothing(): void
    {
        [, , $steps] = $this->legacySteps();
        $this->up();
        $once = array_map(fn (WorkflowStep $step): array => $this->targets($step), $steps);

        $this->up();

        $this->assertSame($once, array_map(fn (WorkflowStep $step): array => $this->targets($step), $steps));
    }

    /**
     * Down succeeds without touching data: dispatch never used the cleared
     * values, so there is nothing to put back.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        [$template, , $steps] = $this->legacySteps();
        $this->up();

        $result = $this->migration()->safeDown();

        $this->assertTrue($result);
        $this->assertSame([null, null], $this->targets($steps['pause']));
        $this->assertSame([$template->id, null], $this->targets($steps['job']));
    }

    /**
     * Steps as older versions could save them, plus clean ones.
     *
     * @return array{0: JobTemplate, 1: ApprovalRule, 2: array<string, WorkflowStep>}
     */
    private function legacySteps(): array
    {
        $owner = $this->createUser('step_targets');
        $group = $this->createRunnerGroup($owner->id);
        $template = $this->createJobTemplate(
            $this->createProject($owner->id)->id,
            $this->createInventory($owner->id)->id,
            $group->id,
            $owner->id
        );
        $rule = $this->createApprovalRule($owner->id);
        $workflow = $this->createWorkflowTemplate($owner->id);
        $steps = [
            'job' => $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, $template->id, $rule->id),
            'approval' => $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, $template->id, $rule->id),
            'pause' => $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_PAUSE, $template->id, $rule->id),
            'dangling' => $this->createWorkflowStep($workflow->id, 3, WorkflowStep::TYPE_APPROVAL, 987654321),
            'clean' => $this->createWorkflowStep($workflow->id, 4, WorkflowStep::TYPE_JOB, $template->id),
            'cleanApproval' => $this->createWorkflowStep($workflow->id, 5, WorkflowStep::TYPE_APPROVAL, null, $rule->id),
        ];

        return [$template, $rule, $steps];
    }

    private function migration(): \m000078_000000_clear_step_targets_of_other_types
    {
        return new \m000078_000000_clear_step_targets_of_other_types(['db' => \Yii::$app->db, 'compact' => true]);
    }

    private function up(): void
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @return array{0: int|null, 1: int|null} job template and approval rule as stored
     */
    private function targets(WorkflowStep $step): array
    {
        $step->refresh();

        return [
            $step->job_template_id === null ? null : (int)$step->job_template_id,
            $step->approval_rule_id === null ? null : (int)$step->approval_rule_id,
        ];
    }

    /**
     * @return array<string, mixed> every stored column except the two targets
     */
    private static function otherColumns(WorkflowStep $step): array
    {
        $step->refresh();
        $attributes = $step->getAttributes();
        unset($attributes['job_template_id'], $attributes['approval_rule_id']);

        return $attributes;
    }
}
