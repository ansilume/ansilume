<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Runner;
use app\models\RunnerGroup;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class RunnerTest extends TestCase
{
    private function makeRunner(array $attributes): Runner
    {
        $r = $this->getMockBuilder(Runner::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($r, $attributes);
        return $r;
    }

    public function testTableName(): void
    {
        $this->assertSame('{{%runner}}', Runner::tableName());
    }

    // --- isOnline() ---

    public function testIsOnlineWhenHeartbeatWithinThreshold(): void
    {
        $runner = $this->makeRunner(['last_seen_at' => time() - (RunnerGroup::STALE_AFTER - 10)]);
        $this->assertTrue($runner->isOnline());
    }

    public function testIsNotOnlineWhenHeartbeatExceedsThreshold(): void
    {
        $runner = $this->makeRunner(['last_seen_at' => time() - (RunnerGroup::STALE_AFTER + 1)]);
        $this->assertFalse($runner->isOnline());
    }

    public function testIsNotOnlineWhenLastSeenIsNull(): void
    {
        $runner = $this->makeRunner(['last_seen_at' => null]);
        $this->assertFalse($runner->isOnline());
    }

    public function testIsNotOnlineWhenHeartbeatExactlyAtThreshold(): void
    {
        // time() - last_seen_at === STALE_AFTER → not < STALE_AFTER → offline
        $runner = $this->makeRunner(['last_seen_at' => time() - RunnerGroup::STALE_AFTER]);
        $this->assertFalse($runner->isOnline());
    }

    // --- generateToken() ---

    public function testGenerateTokenReturnsRawAndHashKeys(): void
    {
        $result = Runner::generateToken();
        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('hash', $result);
    }

    public function testGenerateTokenRawIs64HexChars(): void
    {
        $result = Runner::generateToken();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['raw']);
    }

    public function testGenerateTokenHashIsSha256OfRaw(): void
    {
        $result = Runner::generateToken();
        $this->assertSame(hash('sha256', $result['raw']), $result['hash']);
    }

    public function testGenerateTokenProducesUniqueTokens(): void
    {
        $a = Runner::generateToken();
        $b = Runner::generateToken();
        $this->assertNotSame($a['raw'], $b['raw']);
        $this->assertNotSame($a['hash'], $b['hash']);
    }

    // --- validation ---

    public function testRulesRequireNameAndRunnerGroupId(): void
    {
        $runner = new Runner();
        $runner->validate();
        $this->assertArrayHasKey('name', $runner->errors);
        $this->assertArrayHasKey('runner_group_id', $runner->errors);
    }
}
