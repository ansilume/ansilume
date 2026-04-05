<?php

declare(strict_types=1);

namespace app\tests\unit\services\audit;

use app\models\AuditLog;
use app\services\audit\DatabaseAuditTarget;
use app\tests\integration\DbTestCase;

class DatabaseAuditTargetTest extends DbTestCase
{
    public function testSendCreatesAuditLogRecord(): void
    {
        $target = new DatabaseAuditTarget();
        $before = (int)AuditLog::find()->count();

        $target->send([
            'action'      => 'test.db_target',
            'object_type' => 'test',
            'object_id'   => 42,
            'user_id'     => null,
            'metadata'    => json_encode(['key' => 'value']),
            'ip_address'  => '10.0.0.1',
            'user_agent'  => 'test-agent',
            'created_at'  => time(),
        ]);

        $after = (int)AuditLog::find()->count();
        $this->assertSame($before + 1, $after);

        $record = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertSame('test.db_target', $record->action);
        $this->assertSame('test', $record->object_type);
        $this->assertSame(42, (int)$record->object_id);
        $this->assertSame('10.0.0.1', $record->ip_address);
    }

    public function testSendLogsErrorWhenSaveFails(): void
    {
        $target = new DatabaseAuditTarget();
        $before = (int)AuditLog::find()->count();

        // Empty action fails the required-rule → save() returns false → error branch.
        $target->send([
            'action' => '',
            'object_type' => null,
            'object_id' => null,
            'user_id' => null,
            'metadata' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => time(),
        ]);

        $this->assertSame($before, (int)AuditLog::find()->count());
    }

    public function testSendWithNullOptionalFields(): void
    {
        $target = new DatabaseAuditTarget();

        $target->send([
            'action'      => 'test.minimal',
            'object_type' => null,
            'object_id'   => null,
            'user_id'     => null,
            'metadata'    => null,
            'ip_address'  => null,
            'user_agent'  => null,
            'created_at'  => time(),
        ]);

        $record = AuditLog::find()
            ->where(['action' => 'test.minimal'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        $this->assertNotNull($record);
        $this->assertNull($record->object_type);
        $this->assertNull($record->metadata);
    }
}
