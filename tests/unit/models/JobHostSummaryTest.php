<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\JobHostSummary;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JobHostSummary::aggregate() — pure static method, no DB required.
 */
class JobHostSummaryTest extends TestCase
{
    public function testAggregateEmptyReturnsZeroes(): void
    {
        $result = JobHostSummary::aggregate([]);
        $this->assertSame(0, $result['ok']);
        $this->assertSame(0, $result['changed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['unreachable']);
        $this->assertSame(0, $result['rescued']);
        $this->assertSame(0, $result['hosts']);
    }

    public function testAggregateCountsHosts(): void
    {
        $result = JobHostSummary::aggregate([
            $this->makeSummary(),
            $this->makeSummary(),
            $this->makeSummary(),
        ]);
        $this->assertSame(3, $result['hosts']);
    }

    public function testAggregateSumsAllCounters(): void
    {
        $result = JobHostSummary::aggregate([
            $this->makeSummary(ok: 3, changed: 1, failed: 0, skipped: 2, unreachable: 0, rescued: 1),
            $this->makeSummary(ok: 1, changed: 0, failed: 2, skipped: 0, unreachable: 1, rescued: 0),
        ]);
        $this->assertSame(4, $result['ok']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['unreachable']);
        $this->assertSame(1, $result['rescued']);
        $this->assertSame(2, $result['hosts']);
    }

    public function testAggregateSingleHost(): void
    {
        $result = JobHostSummary::aggregate([
            $this->makeSummary(ok: 10, changed: 2),
        ]);
        $this->assertSame(10, $result['ok']);
        $this->assertSame(2, $result['changed']);
        $this->assertSame(1, $result['hosts']);
    }

    public function testAggregateReturnsAllExpectedKeys(): void
    {
        $result = JobHostSummary::aggregate([]);
        foreach (['ok', 'changed', 'failed', 'skipped', 'unreachable', 'rescued', 'hosts'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    private function makeSummary(
        int $ok = 0,
        int $changed = 0,
        int $failed = 0,
        int $skipped = 0,
        int $unreachable = 0,
        int $rescued = 0,
    ): object {
        // aggregate() only reads public properties — a simple stdClass stub is enough.
        return (object) compact('ok', 'changed', 'failed', 'skipped', 'unreachable', 'rescued');
    }
}
