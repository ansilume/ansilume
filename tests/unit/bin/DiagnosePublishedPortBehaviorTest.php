<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Executes the real published_nginx_port() from bin/diagnose in bash with a
 * stubbed docker and a scratch .env, locking its resolution order:
 * live compose binding → .env NGINX_PORT (last occurrence, CR stripped)
 * → 8080. Regression: diagnose read .env only and raised false
 * "reachability issues" alarms whenever the live binding differed.
 */
class DiagnosePublishedPortBehaviorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/diagnose_port_test_' . uniqid('', true);
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/.env') ?: [] as $f) {
            \app\helpers\FileHelper::safeUnlink($f);
        }
        \app\helpers\FileHelper::safeRmdir($this->workDir);
    }

    private function resolvePort(?string $composeAnswer, ?string $envContent): string
    {
        $diagnose = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertNotFalse($diagnose, 'Could not read bin/diagnose');
        $this->assertSame(
            1,
            preg_match('/^published_nginx_port\(\) \{.*?\n\}/ms', $diagnose, $m),
            'Could not extract published_nginx_port() from bin/diagnose'
        );

        if ($envContent !== null) {
            file_put_contents($this->workDir . '/.env', $envContent);
        }

        $dockerStub = $composeAnswer === null
            ? 'docker() { return 1; }'
            : 'docker() { printf \'%s\n\' "$STUB_COMPOSE_PORT"; }';
        $harness = $dockerStub . "\n" . $m[0] . "\npublished_nginx_port\n";

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['bash', '-c', $harness],
            $descriptors,
            $pipes,
            $this->workDir,
            ['STUB_COMPOSE_PORT' => (string)$composeAnswer, 'PATH' => (string)getenv('PATH')]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);

        return $out;
    }

    public function testLiveComposeBindingWinsOverEnvFile(): void
    {
        $this->assertSame('9911', $this->resolvePort('0.0.0.0:9911', "NGINX_PORT=8080\n"));
    }

    public function testIpv6BindingIsReducedToBarePort(): void
    {
        $this->assertSame('9911', $this->resolvePort('[::]:9911', null));
    }

    public function testFallsBackToLastEnvOccurrenceAndStripsCrAndBindPrefix(): void
    {
        $env = "NGINX_PORT=8080\r\nNGINX_PORT=127.0.0.1:8090\r\n";
        $this->assertSame('8090', $this->resolvePort(null, $env));
    }

    public function testDefaultsTo8080WhenNeitherComposeNorEnvKnowThePort(): void
    {
        $this->assertSame('8080', $this->resolvePort(null, "APP_ENV=prod\n"));
        $this->assertSame('8080', $this->resolvePort(null, null));
    }
}
