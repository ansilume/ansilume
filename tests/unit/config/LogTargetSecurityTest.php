<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Yii's default `logVars` on log targets appended the complete
 * $_SERVER context — including APP_SECRET_KEY, DB_PASSWORD, DB_ROOT_PASSWORD,
 * COOKIE_VALIDATION_KEY, and RUNNER_BOOTSTRAP_SECRET from the container
 * environment — to runtime/logs/app.log whenever a log entry was flushed.
 *
 * Every log target in every application config must disable context variable
 * dumping explicitly via `logVars => []` so secrets can never reach log files.
 */
class LogTargetSecurityTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function configFileProvider(): array
    {
        return [
            'web' => ['web.php'],
            'console' => ['console.php'],
            'test' => ['test.php'],
        ];
    }

    /**
     * @dataProvider configFileProvider
     */
    public function testAllLogTargetsDisableContextVariableDumping(string $file): void
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;

        $this->assertIsArray($config['components'] ?? null, "{$file}: components missing");
        $log = $config['components']['log'] ?? null;
        $this->assertIsArray($log, "{$file}: log component missing");
        $targets = $log['targets'] ?? null;
        $this->assertIsArray($targets, "{$file}: log targets missing");
        $this->assertNotEmpty($targets, "{$file}: log targets empty");

        foreach ($targets as $index => $target) {
            $this->assertIsArray($target, "{$file}: log target #{$index} is not an array config");
            $this->assertArrayHasKey(
                'logVars',
                $target,
                "{$file}: log target #{$index} does not set logVars — Yii's default dumps "
                . '$_SERVER (including all secret env vars) into the log file'
            );
            $this->assertSame(
                [],
                $target['logVars'],
                "{$file}: log target #{$index} must set logVars to an empty array"
            );
        }
    }
}
