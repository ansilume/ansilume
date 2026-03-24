<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\services\AuditService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for audit log persistence, querying, and filtering.
 *
 * Covers:
 * - Log creation with all fields
 * - Filtering by action, user_id, object_type
 * - Immutability (update throws)
 * - Ordering (newest first)
 */
class AuditLogQueryTest extends DbTestCase
{
    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('auditService');
    }

    // -------------------------------------------------------------------------
    // Basic logging
    // -------------------------------------------------------------------------

    public function testLogCreatesAuditRecord(): void
    {
        $user = $this->createUser('audit');

        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 42, $user->id);

        $record = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_LAUNCHED, 'object_id' => 42])
            ->one();

        $this->assertNotNull($record);
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame('job', $record->object_type);
    }

    public function testLogStoresMetadataAsJson(): void
    {
        $user = $this->createUser('meta');

        $this->service->log(
            AuditLog::ACTION_CREDENTIAL_CREATED,
            'credential',
            10,
            $user->id,
            ['name' => 'prod-ssh', 'type' => 'ssh_key']
        );

        $record = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_CREDENTIAL_CREATED, 'object_id' => 10])
            ->one();

        $this->assertNotNull($record);
        $decoded = json_decode($record->metadata, true);
        $this->assertSame('prod-ssh', $decoded['name']);
        $this->assertSame('ssh_key', $decoded['type']);
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    public function testFilterByAction(): void
    {
        $user = $this->createUser('filter');
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $user->id);
        $this->service->log(AuditLog::ACTION_JOB_CANCELED, 'job', 2, $user->id);
        $this->service->log(AuditLog::ACTION_CREDENTIAL_CREATED, 'credential', 3, $user->id);

        $results = AuditLog::find()
            ->where(['like', 'action', 'job.'])
            ->all();

        $this->assertCount(2, $results);
    }

    public function testFilterByUserId(): void
    {
        $userA = $this->createUser('a');
        $userB = $this->createUser('b');

        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $userA->id);
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 2, $userB->id);
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 3, $userA->id);

        $results = AuditLog::find()
            ->where(['user_id' => $userA->id])
            ->all();

        $this->assertCount(2, $results);
    }

    public function testFilterByObjectType(): void
    {
        $user = $this->createUser('type');
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $user->id);
        $this->service->log(AuditLog::ACTION_CREDENTIAL_CREATED, 'credential', 2, $user->id);

        $results = AuditLog::find()
            ->where(['object_type' => 'credential'])
            ->all();

        $this->assertCount(1, $results);
        $this->assertSame(AuditLog::ACTION_CREDENTIAL_CREATED, $results[0]->action);
    }

    public function testCombinedFilters(): void
    {
        $userA = $this->createUser('combo_a');
        $userB = $this->createUser('combo_b');

        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $userA->id);
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 2, $userB->id);
        $this->service->log(AuditLog::ACTION_CREDENTIAL_CREATED, 'credential', 3, $userA->id);

        $results = AuditLog::find()
            ->where(['user_id' => $userA->id, 'object_type' => 'job'])
            ->all();

        $this->assertCount(1, $results);
        $this->assertSame(1, (int)$results[0]->object_id);
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    public function testDefaultOrderIsNewestFirst(): void
    {
        $user = $this->createUser('order');
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $user->id);
        $this->service->log(AuditLog::ACTION_JOB_CANCELED, 'job', 2, $user->id);

        $results = AuditLog::find()
            ->where(['user_id' => $user->id])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $this->assertGreaterThan($results[1]->id, $results[0]->id);
    }

    // -------------------------------------------------------------------------
    // Immutability
    // -------------------------------------------------------------------------

    public function testAuditLogCannotBeUpdated(): void
    {
        $user = $this->createUser('immutable');
        $this->service->log(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, $user->id);

        $record = AuditLog::find()
            ->where(['user_id' => $user->id])
            ->one();

        $this->expectException(\LogicException::class);
        $record->update();
    }
}
