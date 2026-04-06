<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\DbTestCase;

class RunnerGroupTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%runner_group}}', RunnerGroup::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $this->assertNotNull($group->id);
        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($group->name, $reloaded->name);
        $this->assertSame($user->id, $reloaded->created_by);
    }

    public function testValidationRequiresName(): void
    {
        $group = new RunnerGroup();
        $this->assertFalse($group->validate());
        $this->assertArrayHasKey('name', $group->errors);
    }

    public function testRunnersRelation(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner((int)$group->id, $user->id);

        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $runners = $reloaded->runners;
        $this->assertCount(1, $runners);
        $this->assertInstanceOf(Runner::class, $runners[0]);
        $this->assertSame($runner->id, $runners[0]->id);
    }

    public function testCreatorRelation(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $this->assertInstanceOf(User::class, $reloaded->creator);
        $this->assertSame($user->id, $reloaded->creator->id);
    }

    public function testJobTemplatesRelation(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $tpl = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $templates = $reloaded->jobTemplates;
        $this->assertCount(1, $templates);
        $this->assertInstanceOf(JobTemplate::class, $templates[0]);
        $this->assertSame($tpl->id, $templates[0]->id);
    }

    public function testCountTotalWithRunners(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $this->createRunner((int)$group->id, $user->id);
        $this->createRunner((int)$group->id, $user->id);

        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(2, $reloaded->countTotal());
    }

    public function testCountOnline(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $onlineRunner = $this->createRunner((int)$group->id, $user->id);
        $onlineRunner->last_seen_at = time();
        $onlineRunner->save(false);

        $offlineRunner = $this->createRunner((int)$group->id, $user->id);
        $offlineRunner->last_seen_at = time() - 300;
        $offlineRunner->save(false);

        $reloaded = RunnerGroup::findOne($group->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->countOnline());
    }

    public function testCountTotalNoRunners(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $this->assertSame(0, $group->countTotal());
    }
}
