<?php

declare(strict_types=1);

namespace app\components;

use app\models\Project;

/**
 * Applies a project's vault password source on the runner.
 *
 * 'ansilume' (Ansilume only): Ansible gets the job template's vault password
 * and nothing from the repository. The repository's ansible.cfg can name a
 * vault password file or script, a vault identity list, ask_vault_pass and
 * vault_id_match. Environment variables beat ansible.cfg, so
 * {@see VaultIsolation} points the password file and the identity list at a
 * random decoy and turns the prompt off; the template's password still
 * arrives as --vault-password-file. vault_id_match cannot be switched off
 * through the environment array: the setting is untyped, so only an empty
 * value means off, and proc_open() drops empty variables. env(1) sets the
 * empty value instead, which is why the command gets a prefix.
 *
 * Anything else, including a missing field (older server) and values this
 * runner does not know: the command and environment stay as they are, and
 * the repository's vault settings apply as they did before 2.8.
 *
 * The isolation is prepared before the job's temp files exist, so a failure
 * fails the job without anything to clean up; it is applied last, after the
 * credential environment, so neither a Token credential nor an ANSIBLE_*
 * variable of the runner host can undo it.
 */
final class RunnerVaultMode
{
    /** Payload field set by servers since 2.8. */
    public const PAYLOAD_KEY = 'vault_password_source';

    public const ENV_BINARY = '/usr/bin/env';

    /** Prefix argument that runs the command with ANSIBLE_VAULT_ID_MATCH set to ''. */
    public const ID_MATCH_RESET = 'ANSIBLE_VAULT_ID_MATCH=';

    private const FAILURE = "This project uses only Ansilume's vault password, but the runner could not"
        . " neutralise the repository's vault settings, so the job did not run: ";

    private function __construct(
        private readonly ?VaultIsolation $isolation,
        private readonly string $envBinary,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the claim payload
     * @param string|null $decoyDirectory where the decoy goes; the system temp directory by default
     * @param string $envBinary env(1), which clears vault_id_match
     * @throws \RuntimeException when the project asks for 'ansilume' and the runner cannot provide it;
     *         any failure while creating the decoy (a PHP warning turned into an exception by the
     *         error handler, for example on a full disk) is reported this way, so the job fails
     *         instead of the runner
     */
    public static function fromPayload(
        array $payload,
        ?string $decoyDirectory = null,
        string $envBinary = self::ENV_BINARY,
    ): self {
        if (($payload[self::PAYLOAD_KEY] ?? null) !== Project::VAULT_SOURCE_ANSILUME) {
            return new self(null, $envBinary);
        }
        if (!is_executable($envBinary)) {
            throw new \RuntimeException(self::FAILURE . "{$envBinary} is missing or not executable.");
        }

        $isolation = new VaultIsolation($decoyDirectory);
        try {
            $isolation->overrides();
        } catch (\Throwable $e) {
            $directory = $decoyDirectory ?? sys_get_temp_dir();
            throw new \RuntimeException(
                self::FAILURE . $e->getMessage() . " Check that the runner user can write to {$directory}.",
                0,
                $e
            );
        }

        return new self($isolation, $envBinary);
    }

    /**
     * Whether the repository's vault settings are neutralised for this job.
     */
    public function isolates(): bool
    {
        return $this->isolation !== null;
    }

    /**
     * @param array<int, string> $command the command after credential injection
     * @param array<string, string> $env the environment after credential injection
     * @return array{command: list<string>, env: array<string, string>}
     */
    public function apply(array $command, array $env): array
    {
        if ($this->isolation === null) {
            return ['command' => array_values($command), 'env' => $env];
        }

        return [
            'command' => [$this->envBinary, self::ID_MATCH_RESET, ...array_values($command)],
            'env' => array_merge($env, $this->isolation->overrides()),
        ];
    }

    /**
     * Removes the decoy. Safe to call more than once and in every mode.
     */
    public function cleanup(): void
    {
        $this->isolation?->cleanup();
    }
}
