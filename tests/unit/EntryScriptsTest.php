<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * The entry scripts yii and web/index.php read YII_DEBUG from the
 * environment with EnvFlag.
 *
 * Regression: both used (bool)$_ENV['YII_DEBUG'], and (bool)'false' is true,
 * so YII_DEBUG=false in .env turned debug mode on (error pages with stack
 * traces, the web E2E hooks).
 *
 * Each case runs the real entry script in a PHP subprocess. A prepended
 * probe file declares stand-ins for yii\console\Application and
 * yii\web\Application that print the YII_DEBUG constant and exit, so the
 * script runs up to the point where it starts the application and no further.
 */
class EntryScriptsTest extends TestCase
{
    private const PROBE = <<<'PHP'
        <?php

        namespace yii\console {
            class Application
            {
                public function __construct()
                {
                    echo 'YII_DEBUG=', var_export(YII_DEBUG, true), "\n";
                    exit(0);
                }
            }
        }

        namespace yii\web {
            class Application
            {
                public function __construct()
                {
                    echo 'YII_DEBUG=', var_export(YII_DEBUG, true), "\n";
                    exit(0);
                }
            }
        }

        PHP;

    private string $probeFile = '';

    protected function setUp(): void
    {
        $probeFile = tempnam(sys_get_temp_dir(), 'ansilume-entry-probe-');
        $this->assertIsString($probeFile);
        file_put_contents($probeFile, self::PROBE);
        $this->probeFile = $probeFile;
    }

    protected function tearDown(): void
    {
        if ($this->probeFile !== '' && file_exists($this->probeFile)) {
            unlink($this->probeFile);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function caseProvider(): array
    {
        return [
            'yii: false' => ['yii', 'false', false],
            'yii: off' => ['yii', 'off', false],
            'yii: 0' => ['yii', '0', false],
            'yii: 1' => ['yii', '1', true],
            'yii: true' => ['yii', 'true', true],
            'web/index.php: false' => ['web/index.php', 'false', false],
            'web/index.php: no' => ['web/index.php', 'no', false],
            'web/index.php: 0' => ['web/index.php', '0', false],
            'web/index.php: 1' => ['web/index.php', '1', true],
            'web/index.php: on' => ['web/index.php', 'on', true],
        ];
    }

    /**
     * @dataProvider caseProvider
     */
    public function testTheEntryScriptDefinesYiiDebugFromTheEnvironment(string $script, string $value, bool $expected): void
    {
        [$exitCode, $stdout, $stderr] = $this->runEntryScript($script, $value);

        $this->assertSame(0, $exitCode, "{$script} did not reach the application: {$stderr}");
        $this->assertSame('YII_DEBUG=' . var_export($expected, true) . "\n", $stdout, $stderr);
    }

    /**
     * Runs $script with YII_DEBUG=$value in its environment. The rest of the
     * environment is this process's, so .env and YII_ENV=test apply as usual;
     * YII_DEBUG from the environment wins over .env (Dotenv is immutable).
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runEntryScript(string $script, string $value): array
    {
        $root = dirname(__DIR__, 2);
        $env = getenv();
        $env['YII_DEBUG'] = $value;
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=' . $this->probeFile, $root . '/' . $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
