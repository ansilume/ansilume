<?php

declare(strict_types=1);

namespace app\components;

/**
 * Immutable result of credential injection: CLI args, env vars, and temp files to clean up.
 */
class CredentialInjectionResult
{
    /**
     * @param string[] $args CLI args to append to ansible-playbook command
     * @param array<string, string> $env Env vars to merge into process environment
     * @param string[] $tempFiles Temp file paths to clean up after execution
     */
    public function __construct(
        public readonly array $args,
        public readonly array $env,
        public readonly array $tempFiles,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }
}
