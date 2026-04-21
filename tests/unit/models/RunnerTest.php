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

    // --- isOutdated() / hasKnownVersion() ---
    //
    // Regression coverage for the runner-version telemetry feature:
    // operators need to see when a runner is lagging behind the server
    // so they know to pull a newer image or rebuild. version_compare()
    // is semver-aware (2.2.9 < 2.2.10 correctly); these tests pin that
    // the wiring around it matches what the UI relies on.

    public function testIsOutdatedReturnsFalseWhenVersionIsNull(): void
    {
        $this->setServerVersion('2.2.16');
        $runner = $this->makeRunner(['software_version' => null]);
        $this->assertFalse($runner->isOutdated(), 'Null version is "unknown", not "outdated".');
        $this->assertFalse($runner->hasKnownVersion());
    }

    public function testIsOutdatedReturnsFalseWhenVersionIsEmpty(): void
    {
        $this->setServerVersion('2.2.16');
        $runner = $this->makeRunner(['software_version' => '']);
        $this->assertFalse($runner->isOutdated());
        $this->assertFalse($runner->hasKnownVersion());
    }

    public function testIsOutdatedReturnsTrueWhenRunnerIsOlderThanServer(): void
    {
        $this->setServerVersion('2.2.16');
        $runner = $this->makeRunner(['software_version' => '2.2.15']);
        $this->assertTrue($runner->isOutdated());
        $this->assertTrue($runner->hasKnownVersion());
    }

    public function testIsOutdatedUsesSemverComparison(): void
    {
        // version_compare is semver-aware — 2.2.9 < 2.2.10, not the other way.
        $this->setServerVersion('2.2.10');
        $runner = $this->makeRunner(['software_version' => '2.2.9']);
        $this->assertTrue($runner->isOutdated());
    }

    public function testIsOutdatedReturnsFalseWhenVersionsMatch(): void
    {
        $this->setServerVersion('2.2.16');
        $runner = $this->makeRunner(['software_version' => '2.2.16']);
        $this->assertFalse($runner->isOutdated());
    }

    public function testIsOutdatedReturnsFalseWhenRunnerIsNewer(): void
    {
        // Shouldn't happen in practice, but treat a newer runner as "not
        // outdated" — the operator can decide whether that's a problem.
        $this->setServerVersion('2.2.15');
        $runner = $this->makeRunner(['software_version' => '2.2.16']);
        $this->assertFalse($runner->isOutdated());
    }

    public function testIsOutdatedReturnsFalseOnDevServer(): void
    {
        // A dev checkout has no meaningful version ("dev"); every numbered
        // runner would otherwise look outdated. Mute the badge in that case.
        $this->setServerVersion('dev');
        $runner = $this->makeRunner(['software_version' => '2.2.0']);
        $this->assertFalse($runner->isOutdated());
    }

    private function setServerVersion(string $version): void
    {
        if (\Yii::$app !== null) {
            \Yii::$app->params['version'] = $version;
        } else {
            // RunnerTest runs in pure-unit mode without a Yii app; fall
            // back to a stub so ['version'] is always readable.
            new \yii\console\Application(['id' => 'test', 'basePath' => sys_get_temp_dir(), 'params' => ['version' => $version]]);
        }
    }
}
