<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\WorkflowStepMapping;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

class WorkflowStepTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%workflow_step}}', WorkflowStep::tableName());
    }

    public function testBehaviorsIncludesTimestamp(): void
    {
        $step = new WorkflowStep();
        $behaviors = $step->behaviors();
        $this->assertNotEmpty($behaviors);
    }

    public function testTypeLabels(): void
    {
        $labels = WorkflowStep::typeLabels();
        $this->assertSame('Job', $labels[WorkflowStep::TYPE_JOB]);
        $this->assertSame('Approval', $labels[WorkflowStep::TYPE_APPROVAL]);
        $this->assertSame('Pause', $labels[WorkflowStep::TYPE_PAUSE]);
    }

    public function testRequiredFields(): void
    {
        $step = new WorkflowStep();
        $this->assertFalse($step->validate());
        $this->assertArrayHasKey('workflow_template_id', $step->errors);
        $this->assertArrayHasKey('name', $step->errors);
        $this->assertArrayHasKey('step_type', $step->errors);
    }

    public function testStepTypeRangeValidation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'bad';
        $step->step_type = 'not-a-real-type';
        $this->assertFalse($step->validate());
        $this->assertArrayHasKey('step_type', $step->errors);
    }

    /**
     * Regression: the step type rule compared loosely, so true, which a JSON
     * body can post, matched every type and was stored as "1": a run that
     * reached such a step hung.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function stepTypeThatIsNoTypeProvider(): array
    {
        return [
            'true' => [true, 'Step Type must be a string.'],
            'the number 1' => [1, 'Step Type must be a string.'],
            'a list of a type' => [[WorkflowStep::TYPE_JOB], 'Step Type must be a string.'],
            'the text "1"' => ['1', 'Step Type is invalid.'],
        ];
    }

    /**
     * @dataProvider stepTypeThatIsNoTypeProvider
     */
    public function testAStepTypeThatIsNoTypeIsRefused(mixed $type, string $error): void
    {
        $step = new WorkflowStep();
        $step->step_type = $type;

        $this->assertFalse($step->validate(['step_type']));
        $this->assertSame([$error], $step->getErrors('step_type'));
    }

    public function testEveryStepTypeIsAccepted(): void
    {
        foreach (array_keys(WorkflowStep::typeLabels()) as $type) {
            $step = new WorkflowStep();
            $step->step_type = $type;

            $this->assertTrue($step->validate(['step_type']), $type);
        }
    }

    public function testValidJsonExtraVarsTemplatePasses(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'ok';
        $step->step_type = WorkflowStep::TYPE_PAUSE;
        $step->extra_vars_template = '{"foo":"bar"}';
        $this->assertTrue($step->validate());
    }

    public function testInvalidJsonExtraVarsTemplateRejected(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'bad-json';
        $step->step_type = WorkflowStep::TYPE_JOB;
        $step->extra_vars_template = '{not valid';
        $this->assertFalse($step->validate());
        $this->assertArrayHasKey('extra_vars_template', $step->errors);
    }

    public function testEmptyExtraVarsTemplateIsAllowed(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'empty-evt';
        $step->step_type = WorkflowStep::TYPE_PAUSE;
        $step->extra_vars_template = '';
        $this->assertTrue($step->validate());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noMappingProvider(): array
    {
        return [
            'a list of names' => ['["build_version"]'],
            'a list of numbers' => ['[1, 2]'],
            'a number' => ['5'],
            'text' => ['"text"'],
            'true' => ['true'],
            'an object as a value' => ['{"a": {"b": "c"}}'],
            'a number as a value' => ['{"a": 1}'],
            'a key PHP reads as a number' => ['{"0": "build_version"}'],
            'an empty key' => ['{"": "build_version"}'],
        ];
    }

    /**
     * regression: the rules took any JSON as the variable mapping. A new
     * step's mapping must be none or a JSON object of extra var names to the
     * names of output variables now.
     *
     * @dataProvider noMappingProvider
     */
    public function testRegressionANewStepRefusesAMappingOfAnotherShape(string $mapping): void
    {
        $step = $this->pauseStep($mapping);

        $this->assertFalse($step->validate());
        $this->assertSame([WorkflowStepMapping::INVALID], $step->getErrors('extra_vars_template'));
    }

    /**
     * None, and an object of names, are mappings a step takes.
     */
    public function testANewStepTakesNoneAndAnObjectOfNames(): void
    {
        foreach (['{}', '[]', 'null', '{"app_version": "build_version", "target": "hosts"}'] as $mapping) {
            $step = $this->pauseStep($mapping);

            $this->assertTrue($step->validate(), $mapping . ': ' . (string)json_encode($step->getErrors()));
        }
    }

    /**
     * Text that is no JSON keeps the JSON rule's message alone: the shape
     * is not checked then.
     */
    public function testTextThatIsNoJsonGetsOnlyTheJsonRulesMessage(): void
    {
        $step = $this->pauseStep('{not valid');

        $this->assertFalse($step->validate());
        $this->assertSame(['Extra_vars_template must be valid JSON.'], $step->getErrors('extra_vars_template'));
    }

    /**
     * A mapping of another shape that an older version stored stays valid
     * while it is not changed: the step's other fields can change (the
     * template page cannot change its mapping), and the same mapping in other
     * words is no change. Another mapping is checked.
     */
    public function testAMappingAnOlderVersionStoredIsCheckedOnlyWhenItChanges(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $stored = $this->createWorkflowStep((int)$wt->id, 1, WorkflowStep::TYPE_PAUSE);
        WorkflowStep::updateAll(['extra_vars_template' => '["build_version"]'], ['id' => $stored->id]);
        $step = WorkflowStep::findOne($stored->id);
        $this->assertNotNull($step);

        $step->name = 'renamed';
        $this->assertTrue($step->validate(), 'the stored mapping stays: ' . (string)json_encode($step->getErrors()));
        $step->extra_vars_template = '[ "build_version" ]';
        $this->assertTrue($step->validate(), 'the same mapping in other words');
        $step->extra_vars_template = '["release_version"]';
        $this->assertFalse($step->validate(), 'another mapping of that shape');
        $this->assertSame([WorkflowStepMapping::INVALID], $step->getErrors('extra_vars_template'));
        $step->extra_vars_template = '{"app_version": "build_version"}';
        $this->assertTrue($step->validate(), 'a mapping of names');
    }

    public function testGetParsedExtraVarsTemplateReturnsDecodedArray(): void
    {
        $step = new WorkflowStep();
        $step->extra_vars_template = '{"key":"value","n":42}';
        $parsed = $step->getParsedExtraVarsTemplate();
        $this->assertSame('value', $parsed['key']);
        $this->assertSame(42, $parsed['n']);
    }

    public function testGetParsedExtraVarsTemplateEmptyReturnsEmptyArray(): void
    {
        $step = new WorkflowStep();
        $step->extra_vars_template = null;
        $this->assertSame([], $step->getParsedExtraVarsTemplate());
    }

    public function testGetParsedExtraVarsTemplateNonObjectJsonReturnsEmpty(): void
    {
        $step = new WorkflowStep();
        // Valid JSON, but scalar — cast to array becomes empty.
        $step->extra_vars_template = '"just a string"';
        $this->assertSame([], $step->getParsedExtraVarsTemplate());
    }

    public function testWorkflowTemplateRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep((int)$wt->id, 1);
        $this->assertInstanceOf(WorkflowTemplate::class, $step->workflowTemplate);
        $this->assertSame($wt->id, $step->workflowTemplate->id);
    }

    public function testJobTemplateRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $wt = $this->createWorkflowTemplate($user->id);
        $step = $this->createWorkflowStep((int)$wt->id, 1, WorkflowStep::TYPE_JOB, (int)$tpl->id);

        $this->assertInstanceOf(JobTemplate::class, $step->jobTemplate);
        $this->assertSame($tpl->id, $step->jobTemplate->id);
    }

    public function testApprovalRuleRelationIsQuery(): void
    {
        $step = new WorkflowStep();
        $this->assertInstanceOf(\yii\db\ActiveQuery::class, $step->getApprovalRule());
    }

    public function testOnSuccessStepRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $s1 = $this->createWorkflowStep((int)$wt->id, 1);
        $s2 = $this->createWorkflowStep((int)$wt->id, 2);
        $s1->on_success_step_id = $s2->id;
        $s1->save(false);

        $reloaded = WorkflowStep::findOne($s1->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->onSuccessStep);
        $this->assertSame($s2->id, $reloaded->onSuccessStep->id);
    }

    public function testOnFailureStepRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $s1 = $this->createWorkflowStep((int)$wt->id, 1);
        $s2 = $this->createWorkflowStep((int)$wt->id, 2);
        $s1->on_failure_step_id = $s2->id;
        $s1->save(false);

        $reloaded = WorkflowStep::findOne($s1->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->onFailureStep);
        $this->assertSame($s2->id, $reloaded->onFailureStep->id);
    }

    public function testOnAlwaysStepRelation(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $s1 = $this->createWorkflowStep((int)$wt->id, 1);
        $s2 = $this->createWorkflowStep((int)$wt->id, 2);
        $s1->on_always_step_id = $s2->id;
        $s1->save(false);

        $reloaded = WorkflowStep::findOne($s1->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->onAlwaysStep);
        $this->assertSame($s2->id, $reloaded->onAlwaysStep->id);
    }

    public function testAJobStepNeedsAnExistingJobTemplate(): void
    {
        $user = $this->createUser('step_tpl');
        $wt = $this->createWorkflowTemplate($user->id);
        $template = $this->createJobTemplate(
            $this->createProject($user->id)->id,
            $this->createInventory($user->id)->id,
            $this->createRunnerGroup($user->id)->id,
            $user->id
        );

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'deploy';
        $step->step_type = WorkflowStep::TYPE_JOB;
        $this->assertFalse($step->validate());
        $this->assertSame('A job step needs a job template.', $step->getFirstError('job_template_id'));

        $step->job_template_id = 987654321;
        $this->assertFalse($step->validate());
        $this->assertSame('The selected job template does not exist.', $step->getFirstError('job_template_id'));

        $template->softDelete();
        $step->job_template_id = $template->id;
        $this->assertFalse($step->validate(), 'a deleted template cannot be used');

        $template->deleted_at = null;
        $template->save(false);
        $this->assertTrue($step->validate());
    }

    public function testTheJobTemplateMessagesAreTheConstants(): void
    {
        $this->assertSame('A job step needs a job template.', WorkflowStep::JOB_TEMPLATE_REQUIRED);
        $this->assertSame('The selected job template does not exist.', WorkflowStep::JOB_TEMPLATE_NOT_FOUND);
    }

    /**
     * regression: a posted step_order was mass-assigned. A form or a body
     * could then decide where a step runs (and, in 2.10.0, a pre-filled
     * number put new steps first). Placement sets it now; load() and
     * setAttributes() leave it as it is.
     */
    public function testRegressionAPostedStepOrderIsNotMassAssigned(): void
    {
        $step = new WorkflowStep();
        $step->load(['WorkflowStep' => ['step_order' => '5', 'name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE]]);

        $this->assertNull($step->step_order);
        $this->assertSame('x', $step->name);
        $this->assertNotContains('step_order', $step->safeAttributes());

        $stored = $this->createWorkflowStep($this->createWorkflowTemplate($this->createUser('step_order_mass')->id)->id, 20);
        $stored->setAttributes(['step_order' => 0]);
        $this->assertSame(20, $stored->step_order, 'a saved step keeps its order too');
    }

    /**
     * Code still sets step_order (placement, the API's bulk list): the rule
     * still checks the values the column holds.
     *
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function stepOrderProvider(): array
    {
        return [
            'empty, as a new step has it' => [null, null],
            '0' => [0, null],
            'the largest the column holds' => [WorkflowStep::MAX_STEP_ORDER, null],
            'below 0' => [-1, 'Step Order must be no less than 0.'],
            'more than the column holds' => [WorkflowStep::MAX_STEP_ORDER + 1, 'Step Order must be no greater than 2147483647.'],
            'not a number' => ['abc', 'Step Order must be an integer.'],
        ];
    }

    /**
     * @dataProvider stepOrderProvider
     */
    public function testAStepOrderSetInCodeIsStillValidated(mixed $order, ?string $error): void
    {
        $step = new WorkflowStep();
        $step->step_order = $order;

        $this->assertSame($error === null, $step->validate(['step_order']));
        $this->assertSame($error, $step->getFirstError('step_order'));
    }

    /**
     * Regression: add-step loaded the posted form after setting the step's
     * workflow, so a form field could plant a step in another workflow.
     */
    public function testTheWorkflowCannotBeSetFromAForm(): void
    {
        $user = $this->createUser('step_mass');
        $mine = $this->createWorkflowTemplate($user->id);
        $other = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $mine->id;
        $step->load(['WorkflowStep' => ['workflow_template_id' => $other->id, 'name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE]]);

        $this->assertSame($mine->id, $step->workflow_template_id);
        $this->assertSame('x', $step->name);
        $this->assertNotContains('workflow_template_id', $step->safeAttributes());
    }

    /**
     * A pause step of a new workflow, not saved, with $mapping.
     */
    private function pauseStep(string $mapping): WorkflowStep
    {
        $user = $this->createUser();
        $step = new WorkflowStep();
        $step->workflow_template_id = (int)$this->createWorkflowTemplate($user->id)->id;
        $step->name = 'mapped';
        $step->step_type = WorkflowStep::TYPE_PAUSE;
        $step->extra_vars_template = $mapping;

        return $step;
    }
}
