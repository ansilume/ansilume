<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\AuditLog;
use app\services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditService.
 * Uses a full Yii2 application bootstrapped in tests/bootstrap.php.
 */
class AuditServiceTest extends TestCase
{
    public function testLogCreatesAuditRecord(): void
    {
        $service = new AuditService();
        $before  = (int)AuditLog::find()->count();

        $service->log('test.action', 'job', 42, null, ['key' => 'value']);

        $after = (int)AuditLog::find()->count();
        $this->assertSame($before + 1, $after);

        $entry = AuditLog::find()->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $this->assertSame('test.action', $entry->action);
        $this->assertSame('job', $entry->object_type);
        $this->assertSame(42, (int)$entry->object_id);

        $metadata = json_decode($entry->metadata, true);
        $this->assertSame('value', $metadata['key']);
    }

    public function testAuditLogIsImmutable(): void
    {
        $entry = new AuditLog();
        $entry->action     = 'test.immutable';
        $entry->created_at = time();
        $entry->save(false);

        $this->expectException(\LogicException::class);
        $entry->update();
    }
}
