<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\helpers\FileHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the GitHub Actions workflows of the repository and runs the run
 * scripts of their steps as GitHub does with shell: bash, that is bash -eo
 * pipefail, with stand-ins for the commands a test replaces (docker, gh)
 * first on the PATH.
 */
trait RunsWorkflowSteps
{
    /** @var list<string> directories to remove after the test */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            FileHelper::removeDirectory($directory);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $stubs command => its bash script
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScript(string $script, array $env, array $stubs, ?string $cwd = null): array
    {
        $bin = $this->directory();
        foreach ($stubs as $command => $body) {
            file_put_contents($bin . '/' . $command, $body);
            chmod($bin . '/' . $command, 0o755);
        }
        $process = proc_open(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $bin, $env + [
            'PATH' => $bin . ':' . (string)getenv('PATH'),
            'HOME' => $bin,
            'GIT_CONFIG_NOSYSTEM' => '1',
        ]);
        $this->assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/workflow-step-' . uniqid('', true);
        mkdir($directory, 0o755, true);
        $this->directories[] = $directory;

        return $directory;
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(string $path): array
    {
        $file = dirname(__DIR__, 3) . '/' . $path;
        $this->assertFileExists($file);
        $workflow = Yaml::parseFile($file);
        $this->assertIsArray($workflow);

        return $workflow;
    }
}
