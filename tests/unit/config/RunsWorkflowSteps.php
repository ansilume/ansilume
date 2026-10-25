<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\helpers\FileHelper;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the GitHub Actions workflows of the repository and runs the run
 * scripts of their steps as GitHub does, with stand-ins for the commands a
 * test replaces (docker, gh, curl) first on the PATH: runScript() as with
 * shell: bash, that is bash -eo pipefail; runScriptWithShell() from a file,
 * with the shell GitHub picks for the step (githubShell()), such as bash -e
 * for a step that names none.
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
        $command = ['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script];

        return $this->runCommand($command, $env, $stubs, $cwd);
    }

    /**
     * Runs $script as GitHub's runner runs the run script of a step: from a
     * file, whose path takes the place of {0} in $shell (githubShell()).
     *
     * @param list<string> $shell
     * @param array<string, string> $env
     * @param array<string, string> $stubs command => its bash script
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScriptWithShell(
        array $shell,
        string $script,
        array $env,
        array $stubs,
        ?string $cwd = null,
    ): array {
        $file = $this->directory() . '/run-script.sh';
        file_put_contents($file, $script);
        $command = array_map(static fn (string $word): string => $word === '{0}' ? $file : $word, $shell);

        return $this->runCommand($command, $env, $stubs, $cwd);
    }

    /**
     * The command GitHub runs the run script of $step of job $job with on
     * Linux, {0} for the file of the script: the shell of the step, else
     * defaults.run.shell of the job, else of the workflow. A step without
     * one runs with bash -e {0}, without pipefail; shell: bash is bash
     * --noprofile --norc -eo pipefail {0}.
     *
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $step
     * @return list<string>
     */
    private function githubShell(array $workflow, string $job, array $step): array
    {
        $shell = (string)($step['shell']
            ?? $workflow['jobs'][$job]['defaults']['run']['shell']
            ?? $workflow['defaults']['run']['shell']
            ?? '');
        $commands = [
            '' => 'bash -e {0}',
            'bash' => 'bash --noprofile --norc -eo pipefail {0}',
            'sh' => 'sh -e {0}',
        ];
        $command = $commands[$shell] ?? $shell;
        $this->assertStringContainsString('{0}', $command, "No command known for the shell {$shell} of job {$job}.");

        return preg_split('/\s+/', trim($command)) ?: [];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @param array<string, string> $stubs command => its bash script
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCommand(array $command, array $env, array $stubs, ?string $cwd): array
    {
        $bin = $this->directory();
        foreach ($stubs as $name => $body) {
            file_put_contents($bin . '/' . $name, $body);
            chmod($bin . '/' . $name, 0o755);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $bin, $env + [
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
