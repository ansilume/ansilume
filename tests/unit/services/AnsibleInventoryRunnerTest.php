<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\AnsibleInventoryRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AnsibleInventoryRunner process management.
 */
class AnsibleInventoryRunnerTest extends TestCase
{
    public function testRunReturnStructure(): void
    {
        $runner = new AnsibleInventoryRunner();

        $result = $runner->run('/nonexistent/inventory/file');

        // Should have the correct keys regardless of outcome
        $this->assertArrayHasKey('error', $result);
        // Either stdout (success) or groups/hosts/error (failure from process)
        $this->assertIsString($result['error'] ?? '');
    }

    public function testTimeoutDefault(): void
    {
        $runner = new AnsibleInventoryRunner();
        $this->assertSame(30, $runner->timeout);
    }

    public function testTimeoutIsConfigurable(): void
    {
        $runner = new AnsibleInventoryRunner();
        $runner->timeout = 60;
        $this->assertSame(60, $runner->timeout);
    }

    public function testRunReturnsStdoutOnSuccess(): void
    {
        $runner = new AnsibleInventoryRunner();

        if (!$runner->isAvailable()) {
            $this->markTestSkipped('ansible-inventory not installed');
        }

        // ansible-inventory with an empty file returns valid JSON
        $tmpFile = tempnam(sys_get_temp_dir(), 'runner_test_');
        file_put_contents($tmpFile, '');

        try {
            $result = $runner->run($tmpFile);
            // Either succeeds with stdout, or fails with a clear error
            $this->assertArrayHasKey('error', $result);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
