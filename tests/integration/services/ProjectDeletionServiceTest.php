<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\JobTemplate;
use app\models\Project;
use app\services\ProjectDeletionService;
use app\tests\integration\DbTestCase;

/**
 * Regression: deleting a project whose job templates had been soft-deleted
 * failed with "Integrity constraint violation" (HTTP 500). The guard counted
 * only visible templates (JobTemplate::find() hides deleted_at != null) while
 * the RESTRICT foreign key still saw the rows.
 */
class ProjectDeletionServiceTest extends DbTestCase
{
    private function service(): ProjectDeletionService
    {
        return new ProjectDeletionService();
    }

    public function testActiveTemplatesBlockDeletion(): void
    {
        $user = $this->createUser('pds-active');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);

        $svc = $this->service();
        $this->assertSame(1, $svc->blockingTemplateCount($project));
        $this->assertFalse($svc->delete($project));

        $this->assertNotNull(Project::findOne($project->id), 'project must survive');
        $this->assertNotNull(JobTemplate::findOne($template->id), 'active template must survive');
        $this->assertStringContainsString('1 job template(s)', $svc->refusalMessage($project, 1));
        $this->assertStringContainsString($project->name, $svc->refusalMessage($project, 1));
    }

    public function testSoftDeletedTemplatesDoNotBlockAndArePurgedWithTheProject(): void
    {
        $user = $this->createUser('pds-soft');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $t1 = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $t2 = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($t1->softDelete());
        $this->assertTrue($t2->softDelete());

        $svc = $this->service();
        $this->assertSame(0, $svc->blockingTemplateCount($project), 'soft-deleted templates must not block');
        $this->assertTrue($svc->delete($project));

        $this->assertNull(Project::findOne($project->id));
        $this->assertSame(
            0,
            (int)JobTemplate::findWithDeleted()->where(['id' => [$t1->id, $t2->id]])->count(),
            'soft-deleted rows must be purged so the RESTRICT foreign key is satisfied'
        );
    }

    public function testMixedActiveAndSoftDeletedTemplatesStillBlock(): void
    {
        $user = $this->createUser('pds-mixed');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $active = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $gone = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($gone->softDelete());

        $svc = $this->service();
        $this->assertSame(1, $svc->blockingTemplateCount($project));
        $this->assertFalse($svc->delete($project));

        $this->assertNotNull(Project::findOne($project->id));
        $this->assertNotNull(JobTemplate::findOne($active->id));
        // Nothing is purged while the deletion is refused.
        $this->assertNotNull(JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one());
    }

    public function testDeleteOfProjectWithoutTemplatesSucceeds(): void
    {
        $user = $this->createUser('pds-plain');
        $project = $this->createProject($user->id);

        $this->assertTrue($this->service()->delete($project));
        $this->assertNull(Project::findOne($project->id));
    }

    public function testFailureInsideTransactionRollsBackThePurge(): void
    {
        $user = $this->createUser('pds-rollback');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $gone = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($gone->softDelete());

        // A project double whose delete() blows up after the purge ran.
        $exploding = new class extends Project {
            public static function tableName(): string
            {
                return Project::tableName();
            }

            public function delete(): int|false
            {
                throw new \RuntimeException('boom');
            }
        };
        $exploding->setAttributes($project->getAttributes(), false);
        $exploding->setOldAttributes($project->getOldAttributes());
        $exploding->setIsNewRecord(false);

        try {
            $this->service()->delete($exploding);
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNotNull(Project::findOne($project->id), 'project must still exist after rollback');
        $this->assertNotNull(
            JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one(),
            'purged template rows must be restored by the rollback'
        );
    }
}
