<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\AuditLog;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for AuditLog model queries used by AuditLogController.
 *
 * Tests the filter logic (action, user_id, object_type) and the view lookup
 * against a real database, without bootstrapping the full web stack.
 */
class AuditLogControllerTest extends DbTestCase
{
    public function testFilterByAction(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'user.created', 'user', 1);
        $this->createAuditEntry($user->id, 'project.created', 'project', 2);
        $this->createAuditEntry($user->id, 'user.updated', 'user', 1);

        $results = AuditLog::find()
            ->andWhere(['like', 'action', 'user'])
            ->all();

        $this->assertCount(2, $results);
    }

    public function testFilterByUserId(): void
    {
        $user1 = $this->createUser('a');
        $user2 = $this->createUser('b');
        $this->createAuditEntry($user1->id, 'user.created', 'user', 1);
        $this->createAuditEntry($user2->id, 'user.created', 'user', 2);

        $results = AuditLog::find()
            ->andWhere(['user_id' => $user1->id])
            ->all();

        $this->assertCount(1, $results);
        $this->assertSame($user1->id, $results[0]->user_id);
    }

    public function testFilterByObjectType(): void
    {
        $user = $this->createUser();
        $this->createAuditEntry($user->id, 'project.created', 'project', 1);
        $this->createAuditEntry($user->id, 'user.created', 'user', 2);
        $this->createAuditEntry($user->id, 'project.updated', 'project', 3);

        $results = AuditLog::find()
            ->andWhere(['object_type' => 'project'])
            ->all();

        $this->assertCount(2, $results);
    }

    public function testFilterByAllCriteria(): void
    {
        $user1 = $this->createUser('x');
        $user2 = $this->createUser('y');
        $this->createAuditEntry($user1->id, 'user.created', 'user', 1);
        $this->createAuditEntry($user1->id, 'project.created', 'project', 2);
        $this->createAuditEntry($user2->id, 'user.created', 'user', 3);

        $results = AuditLog::find()
            ->andWhere(['like', 'action', 'user'])
            ->andWhere(['user_id' => $user1->id])
            ->andWhere(['object_type' => 'user'])
            ->all();

        $this->assertCount(1, $results);
    }

    public function testViewLookupReturnsEntry(): void
    {
        $user  = $this->createUser();
        $entry = $this->createAuditEntry($user->id, 'test.action', 'test', 99);

        $found = AuditLog::findOne($entry->id);
        $this->assertNotNull($found);
        $this->assertSame('test.action', $found->action);
    }

    public function testViewLookupReturnsNullForMissing(): void
    {
        $found = AuditLog::findOne(999999);
        $this->assertNull($found);
    }

    public function testOrderByIdDesc(): void
    {
        $user = $this->createUser();
        $e1 = $this->createAuditEntry($user->id, 'first', 'test', 1);
        $e2 = $this->createAuditEntry($user->id, 'second', 'test', 2);

        $results = AuditLog::find()
            ->orderBy(['id' => SORT_DESC])
            ->limit(2)
            ->all();

        $this->assertGreaterThanOrEqual($results[1]->id, $results[0]->id);
    }

    public function testUserRelationLoads(): void
    {
        $user  = $this->createUser();
        $entry = $this->createAuditEntry($user->id, 'test.action', 'test', 1);

        $found = AuditLog::find()
            ->with('user')
            ->where(['id' => $entry->id])
            ->one();

        $this->assertNotNull($found);
        $this->assertNotNull($found->user);
        $this->assertSame($user->id, $found->user->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createAuditEntry(int $userId, string $action, string $objectType, int $objectId): AuditLog
    {
        $log = new AuditLog();
        $log->user_id     = $userId;
        $log->action      = $action;
        $log->object_type = $objectType;
        $log->object_id   = $objectId;
        $log->created_at  = time();
        $log->save(false);
        return $log;
    }
}
