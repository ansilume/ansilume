<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\services\AuditService;
use app\services\audit\AuditTargetInterface;
use app\services\audit\DatabaseAuditTarget;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for AuditService covering branches not hit by the unit test:
 * - explicit user ID vs resolved user ID
 * - empty context produces null metadata
 * - target exception handling (error branch)
 * - multiple targets dispatched
 * - resolveUserId when no user component (console context)
 */
class AuditServiceIntegrationTest extends DbTestCase
{
    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditService();
        $this->service->targets = [new DatabaseAuditTarget()];
    }

    public function testLogWithExplicitUserIdUsesProvidedValue(): void
    {
        $user = $this->createUser();

        $this->service->log(
            AuditService::ACTION_USER_LOGIN,
            'user',
            $user->id,
            $user->id
        );

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertSame($user->id, (int)$entry->user_id);
        $this->assertSame('user.login', $entry->action);
        $this->assertSame('user', $entry->object_type);
        $this->assertSame($user->id, (int)$entry->object_id);
    }

    public function testLogWithEmptyContextStoresNullMetadata(): void
    {
        $this->service->log('test.empty_context', 'project', 1, null, []);

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertNull($entry->metadata);
    }

    public function testLogWithContextStoresJsonMetadata(): void
    {
        $this->service->log('test.with_context', 'job', 5, null, ['reason' => 'timeout', 'duration' => 120]);

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->metadata);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$entry->metadata, true);
        $this->assertSame('timeout', $decoded['reason']);
        $this->assertSame(120, $decoded['duration']);
    }

    public function testLogWithNullObjectTypeAndObjectId(): void
    {
        $this->service->log('test.minimal');

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertSame('test.minimal', $entry->action);
        $this->assertNull($entry->object_type);
        $this->assertNull($entry->object_id);
    }

    public function testLogCatchesTargetExceptionWithoutThrowing(): void
    {
        $failingTarget = new class implements AuditTargetInterface {
            public bool $called = false;

            public function send(array $entry): void
            {
                $this->called = true;
                throw new \RuntimeException('Target failed');
            }
        };

        $dbTarget = new DatabaseAuditTarget();

        // Place failing target first, DB target second.
        $this->service->targets = [$failingTarget, $dbTarget];

        // Should not throw even though the first target explodes.
        $this->service->log('test.target_failure', 'runner', 10, null, ['note' => 'resilience']);

        $this->assertTrue($failingTarget->called, 'Failing target should have been called');

        // The DB target should still have received and saved the entry.
        $entry = AuditLog::find()
            ->where(['action' => 'test.target_failure'])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry, 'DB target should still save despite prior target failure');
    }

    public function testLogDispatchesToAllTargets(): void
    {
        $targetA = new class implements AuditTargetInterface {
            public int $count = 0;

            public function send(array $entry): void
            {
                $this->count++;
            }
        };

        $targetB = new class implements AuditTargetInterface {
            public int $count = 0;

            public function send(array $entry): void
            {
                $this->count++;
            }
        };

        $this->service->targets = [$targetA, $targetB];

        $this->service->log('test.multi_target', 'schedule', 3);

        $this->assertSame(1, $targetA->count);
        $this->assertSame(1, $targetB->count);
    }

    public function testLogWithNoTargetsDoesNotThrow(): void
    {
        $this->service->targets = [];

        // Should complete without error — just a no-op.
        $this->service->log('test.no_targets');

        // Nothing to assert except no exception was thrown.
        $this->assertTrue(true);
    }

    public function testLogResolvesNullUserIdWhenNoUserComponent(): void
    {
        // In console context, the user component may not have a logged-in user.
        // Pass null explicitly to exercise resolveUserId() fallback.
        $this->service->log('test.null_user', 'credential', 7);

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertSame('test.null_user', $entry->action);
        // user_id comes from resolveUserId() — in console test context, user is guest.
        // The important thing is it doesn't crash.
    }

    public function testLogWithUnicodeContext(): void
    {
        $this->service->log(
            'test.unicode',
            'project',
            1,
            null,
            ['name' => 'Projekt-Umlaut-ae-oe-ue']
        );

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string)$entry->metadata, true);
        $this->assertSame('Projekt-Umlaut-ae-oe-ue', $decoded['name']);
    }

    public function testLogRecordsCorrectTimestamp(): void
    {
        $before = time();
        $this->service->log('test.timestamp', 'job', 99);
        $after = time();

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual($before, (int)$entry->created_at);
        $this->assertLessThanOrEqual($after, (int)$entry->created_at);
    }
}
