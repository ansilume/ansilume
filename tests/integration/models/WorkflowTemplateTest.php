<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;

class WorkflowTemplateTest extends DbTestCase
{
    // -- tableName / behaviors ---------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%workflow_template}}', WorkflowTemplate::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $wt = new WorkflowTemplate();
        $behaviors = $wt->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }

    // -- validation -------------------------------------------------------------

    public function testValidationRequiresName(): void
    {
        $wt = new WorkflowTemplate();
        $this->assertFalse($wt->validate());
        $this->assertArrayHasKey('name', $wt->getErrors());
    }

    public function testValidationPassesWithName(): void
    {
        $user = $this->createUser();
        $wt = new WorkflowTemplate();
        $wt->name = 'Deploy Pipeline';
        $wt->created_by = $user->id;
        $this->assertTrue($wt->validate());
    }

    public function testValidationRejectsNameOver128Chars(): void
    {
        $wt = new WorkflowTemplate();
        $wt->name = str_repeat('a', 129);
        $this->assertFalse($wt->validate(['name']));
    }

    public function testValidationAcceptsDescription(): void
    {
        $wt = new WorkflowTemplate();
        $wt->name = 'test';
        $wt->description = 'A long description about this workflow';
        $this->assertTrue($wt->validate(['name', 'description']));
    }

    // -- soft delete ------------------------------------------------------------

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $this->assertFalse($wt->isDeleted());
        $this->assertTrue($wt->softDelete());
        $this->assertTrue($wt->isDeleted());
        $this->assertNotNull($wt->deleted_at);
    }

    public function testSoftDeletedTemplateExcludedFromFind(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $id = $wt->id;

        $wt->softDelete();

        $this->assertNull(WorkflowTemplate::findOne($id));
    }

    public function testSoftDeletedTemplateVisibleWithFindWithDeleted(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $id = $wt->id;

        $wt->softDelete();

        $found = WorkflowTemplate::findWithDeleted()->where(['id' => $id])->one();
        $this->assertNotNull($found);
        $this->assertInstanceOf(WorkflowTemplate::class, $found);
        $this->assertTrue($found->isDeleted());
    }

    public function testIsDeletedReturnsFalseWhenNotDeleted(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->assertFalse($wt->isDeleted());
    }

    public function testFindExcludesSoftDeletedByDefault(): void
    {
        $user = $this->createUser();
        $wt1 = $this->createWorkflowTemplate($user->id);
        $wt2 = $this->createWorkflowTemplate($user->id);
        $wt2->softDelete();

        $found1 = WorkflowTemplate::find()->andWhere(['id' => $wt1->id])->one();
        $found2 = WorkflowTemplate::find()->andWhere(['id' => $wt2->id])->one();

        $this->assertNotNull($found1);
        $this->assertNull($found2);
    }

    public function testFindWithDeletedIncludesAll(): void
    {
        $user = $this->createUser();
        $wt1 = $this->createWorkflowTemplate($user->id);
        $wt2 = $this->createWorkflowTemplate($user->id);
        $wt2->softDelete();

        $found1 = WorkflowTemplate::findWithDeleted()->andWhere(['id' => $wt1->id])->one();
        $found2 = WorkflowTemplate::findWithDeleted()->andWhere(['id' => $wt2->id])->one();

        $this->assertNotNull($found1);
        $this->assertNotNull($found2);
    }

    // -- getStartStep -----------------------------------------------------------

    public function testGetStartStepReturnsFirstStepByOrder(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $wt = $this->createWorkflowTemplate($user->id);

        $step2 = $this->createWorkflowStep($wt->id, 2, WorkflowStep::TYPE_JOB, $tpl->id);
        $step1 = $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $tpl->id);

        $start = $wt->getStartStep();
        $this->assertNotNull($start);
        $this->assertSame($step1->id, $start->id);
    }

    public function testGetStartStepReturnsNullWhenNoSteps(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);

        $this->assertNull($wt->getStartStep());
    }

    // -- relations --------------------------------------------------------------

    public function testCreatorRelationReturnsUser(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->assertNotNull($wt->creator);
        $this->assertSame($user->id, $wt->creator->id);
    }

    public function testStepsRelationReturnsOrderedSteps(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $wt = $this->createWorkflowTemplate($user->id);

        $this->createWorkflowStep($wt->id, 3, WorkflowStep::TYPE_JOB, $tpl->id);
        $this->createWorkflowStep($wt->id, 1, WorkflowStep::TYPE_JOB, $tpl->id);
        $this->createWorkflowStep($wt->id, 2, WorkflowStep::TYPE_APPROVAL);

        $steps = $wt->steps;
        $this->assertCount(3, $steps);
        $this->assertSame(1, (int)$steps[0]->step_order);
        $this->assertSame(2, (int)$steps[1]->step_order);
        $this->assertSame(3, (int)$steps[2]->step_order);
    }

    public function testStepsRelationReturnsEmptyArrayWhenNoSteps(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->assertIsArray($wt->steps);
        $this->assertEmpty($wt->steps);
    }

    public function testWorkflowJobsRelationReturnsArray(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->assertIsArray($wt->workflowJobs);
        $this->assertEmpty($wt->workflowJobs);
    }

    // -- persistence round-trip ------------------------------------------------

    public function testSaveAndReloadPreservesFields(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wt->description = 'A test workflow';
        $wt->save(false);
        $wt->refresh();

        $this->assertSame('A test workflow', $wt->description);
        $this->assertSame($user->id, (int)$wt->created_by);
    }
}
