<?php

declare(strict_types=1);

namespace app\tests\integration\models;

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
}
