<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\helpers\FileHelper;
use PHPUnit\Framework\Assert;

/**
 * Test helper: bare git repositories in a temporary directory that real git
 * reaches through https URLs with credentials in their userinfo. Git
 * rewrites each URL to its repository (url.<base>.insteadOf, rewrites())
 * and keeps the URL as it was given in the .git/config of a checkout, as for
 * a remote host. So a test can clone from one URL, change the project's URL
 * and see where the next sync fetches from, without a network.
 */
final class GitRemoteFixture
{
    /** @var array<string, string> the bare repository of each URL */
    private array $remotes = [];

    public function __construct(public readonly string $dir)
    {
        mkdir($dir . '/home', 0o700, true);
    }

    /**
     * Adds a bare repository that git reaches through $url, whose main
     * branch has README.md = $readme: a new one, or, with $copyOf (the URL
     * of a repository added before), a copy of that one's history with one
     * more commit, as a repository that moved and kept its history.
     */
    public function add(string $url, string $readme, ?string $copyOf = null): void
    {
        $bare = $this->dir . '/remote-' . count($this->remotes) . '.git';
        if ($copyOf === null) {
            $this->git(['init', '-q', '--bare', '-b', 'main', $bare]);
        } else {
            $this->git(['clone', '-q', '--bare', $this->remotes[$copyOf], $bare]);
        }
        $this->commit($bare, $readme);
        $this->remotes[$url] = $bare;
    }

    /**
     * The git configuration that rewrites the URL of every repository to
     * the repository: its value by key.
     *
     * @return array<string, string>
     */
    public function rewrites(): array
    {
        $config = [];
        foreach ($this->remotes as $url => $bare) {
            $config['url.file://' . $bare . '.insteadOf'] = $url;
        }

        return $config;
    }

    /**
     * Runs git in the fixture's directory, with its own HOME and a committer;
     * what it wrote to stdout. Fails the test when git fails.
     *
     * @param list<string> $args
     */
    public function git(array $args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, [
            'PATH' => (string)getenv('PATH'),
            'HOME' => $this->dir . '/home',
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
        Assert::assertSame(0, proc_close($process), 'git ' . implode(' ', $args) . ': ' . $stderr);

        return $stdout;
    }

    /**
     * The files under $directory that hold $text, relative to it and
     * sorted: where a checkout keeps a URL with its credentials.
     *
     * @return list<string>
     */
    public static function filesHolding(string $directory, string $text): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (str_contains((string)file_get_contents($file->getPathname()), $text)) {
                $found[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /** Removes the repositories, and whatever else the test put into the directory. */
    public function remove(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * Pushes a commit that sets README.md to $readme to the main branch of
     * $bare.
     */
    private function commit(string $bare, string $readme): void
    {
        $work = $this->dir . '/work';
        $this->git(['clone', '-q', $bare, $work]);
        file_put_contents($work . '/README.md', $readme);
        $this->git(['-C', $work, 'add', 'README.md']);
        $this->git(['-C', $work, 'commit', '-q', '-m', 'change']);
        $this->git(['-C', $work, 'push', '-q', 'origin', 'HEAD:main']);
        FileHelper::removeDirectory($work);
    }
}
