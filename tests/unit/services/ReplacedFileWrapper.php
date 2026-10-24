<?php

declare(strict_types=1);

namespace app\tests\unit\services;

/**
 * A stream wrapper with a project directory whose playbooks/site.yml changes
 * while a scan reads it. A path whose last part holds a dot is a file, every
 * other one a directory: the project holds playbooks/, which holds site.yml.
 * The changes:
 * - REWRITTEN: git removes a changed file and then writes it anew, so the
 *   first lstat() calls of it find nothing and every later one the new file;
 * - REOPENED: the file's first open fails, as in that gap, a later one works.
 * PHP keeps the last stat() and lstat() of a path of a stream wrapper (not of
 * a plain file) until clearstatcache(), so a call it answers from there does
 * not reach the wrapper.
 */
final class ReplacedFileWrapper
{
    public const PROTOCOL = 'ansilume-replaced';

    public const REWRITTEN = 'rewritten';

    public const REOPENED = 'reopened';

    /** How many lstat() calls of the file find it gone (REWRITTEN). */
    private const GONE_FOR = 2;

    /** The playbook git writes. */
    private const CONTENT = "- hosts: all\n  tasks: []\n";

    /** @var resource|null set by PHP */
    public $context;

    private static string $change = self::REWRITTEN;

    /** How many lstat() calls of the file came. */
    private static int $lstats = 0;

    /** How many opens of the file came. */
    private static int $opens = 0;

    /** @var list<string> the names of the directory listed */
    private array $listing = [];

    /** @var list<string> the names the listing has left */
    private array $names = [];

    /** What a read of the file has left. */
    private string $unread = '';

    /**
     * Registers the wrapper for PROTOCOL with the file before $change; $test
     * runs with it.
     *
     * @template T
     * @param callable(): T $test
     * @return T
     */
    public static function with(string $change, callable $test): mixed
    {
        self::$change = $change;
        self::$lstats = 0;
        self::$opens = 0;
        stream_wrapper_register(self::PROTOCOL, self::class);
        try {
            return $test();
        } finally {
            stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $name = basename($path);
        if ($name === '.' || !str_contains($name, '.')) {
            return ['mode' => 0o040755];
        }
        $link = ($flags & STREAM_URL_STAT_LINK) !== 0;
        if (self::$change === self::REWRITTEN && $link && ++self::$lstats <= self::GONE_FOR) {
            return false;
        }

        return ['mode' => 0o100644, 'size' => strlen(self::CONTENT)];
    }

    public function dir_opendir(string $path, int $options): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $this->listing = match (basename($path)) {
            'project' => ['playbooks'],
            'playbooks' => ['site.yml'],
            default => [],
        };
        $this->names = $this->listing;

        return true;
    }

    public function dir_readdir(): string|false // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return array_shift($this->names) ?? false;
    }

    public function dir_closedir(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return true;
    }

    public function dir_rewinddir(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $this->names = $this->listing;

        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $this->unread = self::CONTENT;

        return self::$change !== self::REOPENED || ++self::$opens > 1;
    }

    public function stream_read(int $count): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        $read = substr($this->unread, 0, $count);
        $this->unread = substr($this->unread, $count);

        return $read;
    }

    public function stream_eof(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return $this->unread === '';
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return ['mode' => 0o100644, 'size' => strlen(self::CONTENT)];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return false;
    }
}
