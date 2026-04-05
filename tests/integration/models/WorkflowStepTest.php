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

    public function testValidJsonExtraVarsTemplatePasses(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $step = new WorkflowStep();
        $step->workflow_template_id = $wt->id;
        $step->name = 'ok';
        $step->step_type = WorkflowStep::TYPE_JOB;
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
        $step->step_type = WorkflowStep::TYPE_JOB;
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
        $this->assertInstanceOf(WorkflowStep::class, $reloaded->onAlwaysStep);
        $this->assertSame($s2->id, $reloaded->onAlwaysStep->id);
    }
}
