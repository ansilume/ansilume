<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

class JobTemplatesApiTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        return [$user, $tpl, $proj, $inv];
    }

    public function testJobTemplateSerializationShape(): void
    {
        [$user, $tpl, $proj, $inv] = $this->scaffold();

        $serialized = [
            'id' => $tpl->id,
            'name' => $tpl->name,
            'description' => $tpl->description,
            'project_id' => $tpl->project_id,
            'project_name' => $tpl->project->name ?? null,
            'inventory_id' => $tpl->inventory_id,
            'inventory_name' => $tpl->inventory->name ?? null,
            'credential_id' => $tpl->credential_id,
            'playbook' => $tpl->playbook,
            'verbosity' => $tpl->verbosity,
            'forks' => $tpl->forks,
            'become' => (bool)$tpl->become,
            'become_method' => $tpl->become_method,
            'become_user' => $tpl->become_user,
            'limit' => $tpl->limit,
            'tags' => $tpl->tags,
            'skip_tags' => $tpl->skip_tags,
            'has_survey' => $tpl->hasSurvey(),
            'notify_on_failure' => (bool)$tpl->notify_on_failure,
            'notify_on_success' => (bool)$tpl->notify_on_success,
            'created_at' => $tpl->created_at,
            'updated_at' => $tpl->updated_at,
        ];

        $this->assertSame($proj->name, $serialized['project_name']);
        $this->assertSame($inv->name, $serialized['inventory_name']);
        $this->assertSame('site.yml', $serialized['playbook']);
        $this->assertFalse($serialized['become']);
    }

    public function testJobTemplateFindOneReturnsCorrectRecord(): void
    {
        [$user, $tpl] = $this->scaffold();

        $found = JobTemplate::findOne($tpl->id);

        $this->assertNotNull($found);
        $this->assertSame($tpl->id, $found->id);
        $this->assertSame($tpl->name, $found->name);
    }

    public function testJobTemplateFindOneReturnsNullForMissingId(): void
    {
        $this->assertNull(JobTemplate::findOne(999999));
    }

    public function testJobTemplateProjectRelationIsResolvable(): void
    {
        [$user, $tpl, $proj] = $this->scaffold();

        $this->assertNotNull($tpl->project);
        $this->assertSame($proj->id, $tpl->project->id);
    }

    public function testJobTemplateInventoryRelationIsResolvable(): void
    {
        [$user, $tpl, $proj, $inv] = $this->scaffold();

        $this->assertNotNull($tpl->inventory);
        $this->assertSame($inv->id, $tpl->inventory->id);
    }

    public function testJobTemplateListWithEagerLoading(): void
    {
        [$user, $tpl] = $this->scaffold();

        $results = JobTemplate::find()
            ->with(['project', 'inventory'])
            ->where(['id' => $tpl->id])
            ->all();

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->project);
        $this->assertNotNull($results[0]->inventory);
    }
}
