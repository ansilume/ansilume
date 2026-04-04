<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for the runner self-registration flow.
 * Tests the model/service layer that the RegisterController relies on.
 */
class RunnerRegisterApiTest extends DbTestCase
{
    public function testRunnerGroupCanBeCreated(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $this->assertNotNull($group->id);
        $this->assertNotNull($group->name);
    }

    public function testRunnerCanBeCreatedInGroup(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $this->assertNotNull($runner->id);
        $this->assertSame($group->id, $runner->runner_group_id);
    }

    public function testRunnerTokenGeneration(): void
    {
        $token = Runner::generateToken();

        $this->assertArrayHasKey('raw', $token);
        $this->assertArrayHasKey('hash', $token);
        $this->assertNotEmpty($token['raw']);
        $this->assertNotEmpty($token['hash']);
        $this->assertNotSame($token['raw'], $token['hash']);
    }

    public function testRunnerTokenHashVerification(): void
    {
        $token = Runner::generateToken();

        // The hash should be verifiable against the raw token
        $this->assertTrue(hash_equals($token['hash'], hash('sha256', $token['raw'])));
    }

    public function testRunnerGroupRelation(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $this->assertNotNull($runner->group);
        $this->assertSame($group->id, $runner->group->id);
    }

    public function testFindRunnerByGroupAndName(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $found = Runner::findOne([
            'runner_group_id' => $group->id,
            'name' => $runner->name,
        ]);

        $this->assertNotNull($found);
        $this->assertSame($runner->id, $found->id);
    }

    public function testDefaultGroupLookupByName(): void
    {
        $result = RunnerGroup::findOne(['name' => 'nonexistent-group-' . uniqid('', true)]);
        $this->assertNull($result);
    }
}
