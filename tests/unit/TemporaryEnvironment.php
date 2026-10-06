<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Test helper: sets process environment variables (putenv) and restores the
 * previous values afterwards, so a canary secret never leaks into later tests
 * and real settings such as APP_SECRET_KEY survive.
 */
final class TemporaryEnvironment
{
    /** @var array<string, string|false> */
    private array $previous = [];

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values)
    {
        foreach ($values as $name => $value) {
            $this->previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    public function restore(): void
    {
        foreach ($this->previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->previous = [];
    }
}
