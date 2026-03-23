<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\DbTestCase;

class RunnerIntegrationTest extends DbTestCase
{
    public function testFindByTokenReturnsNullForEmptyString(): void
    {
        $this->assertNull(Runner::findByToken(''));
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull(Runner::findByToken(str_repeat('a', 64)));
    }

    public function testFindByTokenReturnsCorrectRunner(): void
    {
        $runner = $this->makeRunner();
        $token  = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        $found = Runner::findByToken($token['raw']);

        $this->assertNotNull($found);
        $this->assertSame($runner->id, $found->id);
    }

    public function testFindByTokenDoesNotReturnRunnerWithDifferentToken(): void
    {
        $runner = $this->makeRunner();
        $tokenA = Runner::generateToken();
        $tokenB = Runner::generateToken();
        $runner->token_hash = $tokenA['hash'];
        $runner->save(false);

        $found = Runner::findByToken($tokenB['raw']);

        $this->assertNull($found);
    }

    public function testRunnerGroupCountTotalIncludesNewRunner(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $before = $group->countTotal();
        $this->makeRunnerInGroup($group->id, $user->id);
        $this->makeRunnerInGroup($group->id, $user->id);

        $this->assertSame($before + 2, $group->countTotal());
    }

    public function testRunnerGroupCountOnlineCountsRecentHeartbeats(): void
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);

        $onlineBefore  = $group->countOnline();
        $r1 = $this->makeRunnerInGroup($group->id, $user->id);
        $r2 = $this->makeRunnerInGroup($group->id, $user->id);

        // Mark r1 as online (recent heartbeat), r2 as stale
        $r1->last_seen_at = time() - 30;
        $r1->save(false);
        $r2->last_seen_at = time() - 300;
        $r2->save(false);

        $this->assertSame($onlineBefore + 1, $group->countOnline());
    }

    // -------------------------------------------------------------------------

    private function makeRunner(): Runner
    {
        $user  = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        return $this->makeRunnerInGroup($group->id, $user->id);
    }

    private function makeRunnerInGroup(int $groupId, int $createdBy): Runner
    {
        $r = new Runner();
        $r->runner_group_id = $groupId;
        $r->name            = 'test-runner-' . uniqid('', true);
        $r->token_hash      = hash('sha256', bin2hex(random_bytes(8)));
        $r->created_by      = $createdBy;
        $r->created_at      = time();
        $r->updated_at      = time();
        $r->save(false);
        return $r;
    }
}
