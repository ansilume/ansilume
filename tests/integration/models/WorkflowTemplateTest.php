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

    // -- inbound trigger tokens ------------------------------------------------

    public function testGenerateTriggerTokenReturnsRawAndStoresHash(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $this->assertNull($wt->trigger_token);

        $raw = $wt->generateTriggerToken((int)$wt->created_by);
        $wt->refresh();

        $this->assertNotSame('', $raw);
        $this->assertSame(64, strlen($raw), 'Raw token is 32 random bytes hex-encoded.');
        // Stored value is a SHA-256 hex of the raw token, never the raw value.
        $this->assertNotSame($raw, $wt->trigger_token);
        $this->assertSame(hash('sha256', $raw), $wt->trigger_token);
    }

    public function testRevokeTriggerTokenClearsStoredHash(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $wt->generateTriggerToken((int)$wt->created_by);
        $this->assertNotNull($wt->trigger_token);

        $wt->revokeTriggerToken();
        $wt->refresh();
        $this->assertNull($wt->trigger_token);
    }

    public function testFindByTriggerTokenRoundTrip(): void
    {
        $user = $this->createUser();
        $wt = $this->createWorkflowTemplate($user->id);
        $raw = $wt->generateTriggerToken((int)$wt->created_by);

        $found = WorkflowTemplate::findByTriggerToken($raw);
        $this->assertNotNull($found);
        $this->assertSame($wt->id, $found->id);
    }

    public function testFindByTriggerTokenRejectsEmptyAndUnknown(): void
    {
        $this->assertNull(WorkflowTemplate::findByTriggerToken(''));
        $this->assertNull(WorkflowTemplate::findByTriggerToken('does-not-exist'));
    }

    /**
     * Regression: the edit form could set created_by and trigger_token, so an
     * operator could make a workflow and its trigger run as an admin.
     */
    public function testCreatorAndTriggerTokenCannotBeSetFromAForm(): void
    {
        $owner = $this->createUser('wt_owner');
        $wt = $this->createWorkflowTemplate($owner->id);

        $wt->load(['WorkflowTemplate' => [
            'name' => 'renamed',
            'created_by' => 1,
            'trigger_token' => hash('sha256', 'chosen'),
            'trigger_token_created_by' => 1,
        ]]);

        $this->assertSame('renamed', $wt->name);
        $this->assertSame($owner->id, (int)$wt->created_by);
        $this->assertNull($wt->trigger_token);
        $this->assertNull($wt->trigger_token_created_by);
        $this->assertSame(['name', 'description'], $wt->safeAttributes());
    }

    public function testTheTriggerRunsAsWhoeverGeneratedTheToken(): void
    {
        $owner = $this->createUser('wt_trigger_owner');
        $operator = $this->createUser('wt_trigger_operator');
        $wt = $this->createWorkflowTemplate($owner->id);
        $this->assertSame($owner->id, $wt->getTriggerUserId(), 'no token: the creator');

        $wt->generateTriggerToken($operator->id);
        $wt->refresh();
        $this->assertSame($operator->id, (int)$wt->trigger_token_created_by);
        $this->assertSame($operator->id, $wt->getTriggerUserId());

        $wt->revokeTriggerToken();
        $wt->refresh();
        $this->assertNull($wt->trigger_token);
        $this->assertNull($wt->trigger_token_created_by);
    }

    public function testTokensFromBeforeTheCreatorWasRecordedRunAsTheWorkflowCreator(): void
    {
        $owner = $this->createUser('wt_legacy');
        $wt = $this->createWorkflowTemplate($owner->id);
        $wt->trigger_token = hash('sha256', 'legacy');
        $wt->save(false);

        $this->assertSame($owner->id, $wt->getTriggerUserId());
    }

    public function testHasTriggerTokenFollowsGenerationAndRevocation(): void
    {
        $owner = $this->createUser('wt_has_token');
        $wt = $this->createWorkflowTemplate($owner->id);
        $this->assertFalse($wt->hasTriggerToken());

        $wt->generateTriggerToken($owner->id);
        $this->assertTrue($wt->hasTriggerToken());

        $wt->revokeTriggerToken();
        $this->assertFalse($wt->hasTriggerToken());

        $wt->trigger_token = '';
        $this->assertFalse($wt->hasTriggerToken(), 'an empty value is no token');
    }

    /**
     * Regression: a soft-deleted workflow template kept its trigger token and
     * whom it runs as. Its page answers 404, so nobody could revoke the
     * token, and the foreign key on trigger_token_created_by kept the user
     * who generated it from being deleted.
     */
    public function testSoftDeleteRemovesTheTriggerTokenSoItsGeneratorCanBeDeleted(): void
    {
        $owner = $this->createUser('wt_soft_owner');
        $generator = $this->createUser('wt_soft_generator');
        $wt = $this->createWorkflowTemplate($owner->id);
        $raw = $wt->generateTriggerToken($generator->id);

        $this->assertTrue($wt->softDelete());

        $stored = WorkflowTemplate::findWithDeleted()->where(['id' => $wt->id])->one();
        $this->assertInstanceOf(WorkflowTemplate::class, $stored);
        $this->assertNotNull($stored->deleted_at);
        $this->assertNull($stored->trigger_token);
        $this->assertNull($stored->trigger_token_created_by);
        $this->assertNull(WorkflowTemplate::findByTriggerToken($raw));
        $this->assertSame(1, $generator->delete(), 'nothing else refers to the generator');
    }
}
