<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

class JobTemplateSoftDeleteTest extends DbTestCase
{
    public function testSoftDeleteSetsDeletedAt(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        $this->assertFalse($tpl->isDeleted());
        $tpl->softDelete();
        $this->assertTrue($tpl->isDeleted());
        $this->assertNotNull($tpl->deleted_at);
    }

    public function testSoftDeletedTemplateExcludedFromFind(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $id    = $tpl->id;

        $tpl->softDelete();

        $this->assertNull(JobTemplate::findOne($id));
    }

    public function testSoftDeletedTemplateVisibleWithFindWithDeleted(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $id    = $tpl->id;

        $tpl->softDelete();

        $found = JobTemplate::findWithDeleted()->where(['id' => $id])->one();
        $this->assertNotNull($found);
        $this->assertInstanceOf(JobTemplate::class, $found);
        $this->assertTrue($found->isDeleted());
    }

    public function testJobRetainsReferenceToDeletedTemplate(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj  = $this->createProject($user->id);
        $inv   = $this->createInventory($user->id);
        $tpl   = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);
        $job   = $this->createJob($tpl->id, $user->id);

        $tpl->softDelete();

        // Reload the job from DB
        $job->refresh();
        $this->assertNotNull($job->jobTemplate);
        $this->assertSame($tpl->id, $job->jobTemplate->id);
        $this->assertTrue($job->jobTemplate->isDeleted());
    }

    /**
     * Regression: a soft-deleted template kept its trigger token and whom it
     * runs as. Its page answers 404, so nobody could revoke the token, and
     * the foreign key on trigger_token_created_by kept the user who
     * generated it from being deleted.
     */
    public function testSoftDeleteRemovesTheTriggerTokenSoItsGeneratorCanBeDeleted(): void
    {
        $owner = $this->createUser('jt_soft_owner');
        $generator = $this->createUser('jt_soft_generator');
        $tpl = $this->createJobTemplate(
            $this->createProject($owner->id)->id,
            $this->createInventory($owner->id)->id,
            $this->createRunnerGroup($owner->id)->id,
            $owner->id
        );
        $raw = $tpl->generateTriggerToken($generator->id);

        $this->assertTrue($tpl->softDelete());

        $stored = JobTemplate::findWithDeleted()->where(['id' => $tpl->id])->one();
        $this->assertInstanceOf(JobTemplate::class, $stored);
        $this->assertNotNull($stored->deleted_at);
        $this->assertNull($stored->trigger_token);
        $this->assertNull($stored->trigger_token_created_by);
        $this->assertNull(JobTemplate::findByTriggerToken($raw));
        $this->assertSame(1, $generator->delete(), 'nothing else refers to the generator');
    }
}
