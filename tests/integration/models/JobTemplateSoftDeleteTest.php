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
}
