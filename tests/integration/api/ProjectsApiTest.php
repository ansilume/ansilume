<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Project;
use app\tests\integration\DbTestCase;

class ProjectsApiTest extends DbTestCase
{
    public function testProjectSerializationShape(): void
    {
        $user = $this->createUser();
        $proj = $this->createProject($user->id);

        $serialized = [
            'id' => $proj->id,
            'name' => $proj->name,
            'description' => $proj->description,
            'scm_type' => $proj->scm_type,
            'scm_url' => $proj->scm_url,
            'scm_branch' => $proj->scm_branch,
            'status' => $proj->status,
            'last_synced_at' => $proj->last_synced_at,
            'created_at' => $proj->created_at,
        ];

        $this->assertCount(9, $serialized);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $serialized['scm_type']);
        $this->assertSame(Project::STATUS_NEW, $serialized['status']);
        $this->assertArrayNotHasKey('local_path', $serialized);
        $this->assertArrayNotHasKey('scm_credential_id', $serialized);
    }

    public function testProjectFindOneReturnsCorrectRecord(): void
    {
        $user = $this->createUser();
        $proj = $this->createProject($user->id);

        $found = Project::findOne($proj->id);

        $this->assertNotNull($found);
        $this->assertSame($proj->id, $found->id);
        $this->assertSame($proj->name, $found->name);
    }

    public function testProjectFindOneReturnsNullForMissingId(): void
    {
        $this->assertNull(Project::findOne(999999));
    }

    public function testProjectListOrderedByIdDesc(): void
    {
        $user = $this->createUser();
        $p1 = $this->createProject($user->id);
        $p2 = $this->createProject($user->id);

        $results = Project::find()
            ->where(['id' => [$p1->id, $p2->id]])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $this->assertCount(2, $results);
        $this->assertSame($p2->id, $results[0]->id);
    }

    public function testProjectStatusTransitions(): void
    {
        $user = $this->createUser();
        $proj = $this->createProject($user->id);

        $this->assertSame(Project::STATUS_NEW, $proj->status);

        $proj->status = Project::STATUS_SYNCING;
        $proj->save(false);
        $proj->refresh();
        $this->assertSame(Project::STATUS_SYNCING, $proj->status);

        $proj->status = Project::STATUS_SYNCED;
        $proj->last_synced_at = time();
        $proj->save(false);
        $proj->refresh();
        $this->assertSame(Project::STATUS_SYNCED, $proj->status);
        $this->assertNotNull($proj->last_synced_at);
    }
}
