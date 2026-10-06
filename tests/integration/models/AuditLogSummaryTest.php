<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\AuditLog;
use app\tests\integration\DbTestCase;

class AuditLogSummaryTest extends DbTestCase
{
    private function entry(string $action, string $type, int $objectId, int $createdAt): void
    {
        \Yii::$app->db->createCommand()->insert(AuditLog::tableName(), [
            'action' => $action,
            'object_type' => $type,
            'object_id' => $objectId,
            'created_at' => $createdAt,
        ])->execute();
    }

    public function testSummaryHasTheLatestEntryAndTheRecentCountPerObject(): void
    {
        $now = time();
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', 901, $now - 100_000);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', 901, $now - 500);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', 901, $now - 50);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', 902, $now - 200_000);

        $summary = AuditLog::summarizeByObject(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', [901, 902, 903], $now - 86400);

        $this->assertSame([
            901 => ['last_at' => $now - 50, 'recent' => 2],
            902 => ['last_at' => $now - 200_000, 'recent' => 0],
        ], $summary);
    }

    public function testOtherActionsObjectTypesAndIdsAreIgnored(): void
    {
        $now = time();
        $this->entry(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, 'runner', 911, $now);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner_group', 911, $now);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', 912, $now);

        $this->assertSame([], AuditLog::summarizeByObject(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', [911], $now - 60));
    }

    public function testNoIdsMeansNoQuery(): void
    {
        $this->assertSame([], AuditLog::summarizeByObject(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', [], 0));
    }
}
