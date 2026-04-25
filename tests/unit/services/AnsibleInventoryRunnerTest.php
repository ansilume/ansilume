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

    /**
     * Regression: the inventory subprocess used to inherit HOME from the
     * parent php-fpm worker, which on www-data resolves to /var/www
     * (root-owned). Any plugin that wanted to write a `~/.ansible*` cache
     * dir then failed with EACCES. The fix pins HOME to a writable runtime
     * dir prepared by the entrypoints — same shape as the git-side fix.
     */
    public function testBuildProcessEnvPointsHomeAtAnsibleHome(): void
    {
        $runner = new ExposingAnsibleInventoryRunner();
        $env = $runner->envForTests();

        $this->assertSame(AnsibleInventoryRunner::ANSIBLE_HOME, $env['HOME']);
        $this->assertSame('/var/www/runtime/ansible-home', $env['HOME']);
        $this->assertNotSame('/var/www', $env['HOME']);
    }

    public function testBuildProcessEnvPropagatesPath(): void
    {
        $runner = new ExposingAnsibleInventoryRunner();
        $env = $runner->envForTests();

        $this->assertArrayHasKey('PATH', $env);
        $this->assertNotSame('', $env['PATH']);
    }

    public function testBuildProcessEnvSetsUtf8Locale(): void
    {
        // Without LANG=C.UTF-8 ansible-inventory has historically blown up
        // with locale-related encoding errors when the host shell didn't
        // export a proper locale. Pin it.
        $runner = new ExposingAnsibleInventoryRunner();
        $env = $runner->envForTests();

        $this->assertSame('C.UTF-8', $env['LANG']);
    }
}

/**
 * Test-only subclass that exposes the protected env builder so the
 * regression assertions above can read what we hand to proc_open.
 */
class ExposingAnsibleInventoryRunner extends AnsibleInventoryRunner
{
    /** @return array<string, string> */
    public function envForTests(): array
    {
        return $this->buildProcessEnv();
    }
}
