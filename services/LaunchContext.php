<?php

declare(strict_types=1);

namespace app\services;

/**
 * Launch data only server code can supply, passed to JobLaunchService::launch()
 * as $overrides[LaunchContext::KEY]:
 * - carriedSecrets: password answers carried over from an earlier launch (a
 *   relaunch reuses the job's answers, a schedule its stored ones, a job step
 *   of a workflow run the run's). They stay secret even if the template's
 *   survey changed since, and even if the template has no such password field.
 * - auditContext: added to the job.launched audit entry; names, never values.
 *
 * The launch service takes it only as an instance of this class, and request
 * data cannot produce an object, so a request can never mark a variable as
 * secret or supply a stored answer.
 */
final class LaunchContext
{
    /** The $overrides key that carries the context. */
    public const KEY = 'launch_context';

    /**
     * @param array<string, mixed> $carriedSecrets password answers by key, any JSON type
     * @param array<string, scalar|list<string>|null> $auditContext
     */
    private function __construct(
        #[\SensitiveParameter] public readonly array $carriedSecrets,
        public readonly array $auditContext
    ) {
    }

    /**
     * A relaunch of job #$sourceJobId with its password answers.
     *
     * @param array<string, mixed> $secrets
     */
    public static function forRelaunch(int $sourceJobId, #[\SensitiveParameter] array $secrets): self
    {
        return new self($secrets, ['relaunch_of' => $sourceJobId, 'carried_secret_keys' => self::keysOf($secrets)]);
    }

    /**
     * A launch by schedule #$scheduleId with its stored password answers.
     *
     * @param array<string, mixed> $secrets
     */
    public static function forSchedule(int $scheduleId, #[\SensitiveParameter] array $secrets): self
    {
        return new self($secrets, ['schedule_id' => $scheduleId, 'carried_secret_keys' => self::keysOf($secrets)]);
    }

    /**
     * The launch of a job step of workflow run #$workflowJobId, with the
     * password answers the run was launched with: every job step of the run
     * keeps them sealed, whatever its template's survey says.
     *
     * @param array<string, mixed> $secrets
     */
    public static function forWorkflow(int $workflowJobId, #[\SensitiveParameter] array $secrets): self
    {
        return new self($secrets, ['workflow_job_id' => $workflowJobId, 'carried_secret_keys' => self::keysOf($secrets)]);
    }

    /**
     * Only audit context, no carried answers.
     *
     * @param array<string, scalar|list<string>|null> $context
     */
    public static function withAudit(array $context): self
    {
        return new self([], $context);
    }

    /**
     * Debug dumps (var_dump, print_r, the debug toolbar) show the keys of
     * the carried answers, never their values.
     *
     * @return array{carriedSecretKeys: list<string>, auditContext: array<string, scalar|list<string>|null>}
     */
    public function __debugInfo(): array
    {
        return ['carriedSecretKeys' => self::keysOf($this->carriedSecrets), 'auditContext' => $this->auditContext];
    }

    /**
     * @param array<string, mixed> $secrets
     * @return list<string>
     */
    private static function keysOf(#[\SensitiveParameter] array $secrets): array
    {
        return array_map('strval', array_keys($secrets));
    }
}
