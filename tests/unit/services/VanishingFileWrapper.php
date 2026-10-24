<?php

declare(strict_types=1);

namespace app\tests\unit\services;

/**
 * A stream wrapper whose files are gone once something opens them, as a file
 * that a sync or the artifact cleanup removes between a check and the open:
 * a path whose last part holds a dot is a file until stream_open() was
 * called, which fails; every other path is a directory that can be entered.
 */
final class VanishingFileWrapper
{
    public const PROTOCOL = 'ansilume-vanishing';

    /** @var resource|null set by PHP */
    public $context;

    /** Whether something opened a file, which is gone since. */
    private static bool $opened = false;

    /**
     * Registers the wrapper for PROTOCOL with its files there; $test runs
     * with it.
     *
     * @template T
     * @param callable(): T $test
     * @return T
     */
    public static function with(callable $test): mixed
    {
        self::$opened = false;
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

        return self::$opened ? false : ['mode' => 0o100644, 'size' => 1];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        self::$opened = true;

        return false;
    }
}
