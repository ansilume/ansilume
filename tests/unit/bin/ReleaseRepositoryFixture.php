<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Assert;

/**
 * Test helper: a git repository in a temporary directory, with a bare
 * repository as its origin, in which a test runs bin/release or
 * bin/release-notes as a maintainer does, without a network. Every command
 * runs in the repository with its own HOME and a committer, and a script
 * runs only once git confirms that the repository is the fixture, so
 * neither script can touch the checkout the tests run in.
 */
final class ReleaseRepositoryFixture
{
    /** The working tree, where the scripts run. */
    public readonly string $work;

    /** The bare repository the working tree pushes to. */
    public readonly string $origin;

    public function __construct(public readonly string $dir)
    {
        mkdir($dir . '/home', 0o700, true);
        $this->work = $dir . '/work';
        $this->origin = $dir . '/origin.git';
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->origin], $dir);
        $this->git(['init', '-q', '-b', 'main', $this->work], $dir);
        $this->git(['remote', 'add', 'origin', $this->origin]);
    }

    /**
     * Writes $files (content by path) and commits every change with
     * $message, also when nothing changed.
     *
     * @param array<string, string> $files
     */
    public function commit(string $message, array $files = []): void
    {
        foreach ($files as $path => $content) {
            $file = $this->work . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o755, true);
            }
            file_put_contents($file, $content);
        }
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '--allow-empty', '-m', $message]);
    }

    /**
     * Runs git in the working tree, or in $cwd; what it wrote to stdout.
     * Fails the test when git fails.
     *
     * @param list<string> $args
     */
    public function git(array $args, ?string $cwd = null): string
    {
        [$exitCode, $stdout, $stderr] = $this->run(['git', ...$args], $cwd ?? $this->work);
        Assert::assertSame(0, $exitCode, 'git ' . implode(' ', $args) . ': ' . $stderr);

        return $stdout;
    }

    /**
     * Runs $script of the checkout the tests run in (such as bin/release)
     * with bash in the working tree.
     *
     * @param list<string> $args
     * @param array<string, string> $env added to the fixture's environment
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function runScript(string $script, array $args = [], array $env = []): array
    {
        Assert::assertSame(
            realpath($this->work),
            trim($this->git(['rev-parse', '--show-toplevel'])),
            'The script must run in the fixture repository.'
        );

        return $this->run(['bash', dirname(__DIR__, 3) . '/' . $script, ...$args], $this->work, $env);
    }

    /** Removes both repositories and whatever else the test put into the directory. */
    public function remove(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    private function run(array $command, string $cwd, array $env = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env + [
            'PATH' => (string)getenv('PATH'),
            'HOME' => $this->dir . '/home',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        Assert::assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
