<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveySecretException;
use app\components\SurveySecrets;
use app\models\Job;
use app\models\Runner;

/**
 * The claim step for password answers: the values a launch stored sealed in
 * job.secret_extra_vars, while job.extra_vars and the runner payload hold
 * SurveySecrets::PLACEHOLDER in their place.
 *
 * The answers are opened here, on the server; runners never get
 * APP_SECRET_KEY. What the claiming runner receives depends on its build:
 * - A runner reporting Runner::CAPABILITY_SECRET_EXTRA_VARS gets the answers
 *   in payload['secret_extra_vars'] and passes them to ansible-playbook as a
 *   0600 file (--extra-vars @file). payload['extra_vars'] and the command
 *   carry the other extra vars only.
 * - Any other runner gets them inline in the command: runners 1.0 to 2.8.1
 *   run payload['command'] as sent and ignore fields they do not know.
 *   ansible-playbook prints every command-line argument above verbosity 3,
 *   and its effective verbosity is the -v flags plus the configured default
 *   (a repository's ansible.cfg `verbosity`, or an ANSIBLE_VERBOSITY a Token
 *   credential injects). So the flags are capped at 3 and the command starts
 *   with `/usr/bin/env ANSIBLE_VERBOSITY=0`, which beats both. Runners since
 *   2.8 put their own env(1) prefix in front, which chains.
 *
 * The stored execution_command is built with the placeholders, the
 * verbosity actually used and the prefix: it never contains an answer.
 * A job without answers is claimed exactly as before.
 */
final class JobClaimSecrets
{
    public const ENV_BINARY = '/usr/bin/env';

    public const VERBOSITY_PIN = 'ANSIBLE_VERBOSITY=0';

    /** The highest verbosity at which ansible-playbook does not print its command line. */
    public const MAX_INLINE_VERBOSITY = 3;

    private const VERBOSITY_NOTE = '[ansilume] Verbosity was lowered from %d to %d: this runner does not support '
        . 'encrypted survey answers and would print them at higher verbosity. Update the runner image.';

    /**
     * What the claim payload and the commands get for $job and the claiming
     * runner:
     * - extra_vars and verbosity: the payload fields the runner command is
     *   built from;
     * - stored_extra_vars: the extra vars of the stored command;
     * - prefix: put in front of both commands;
     * - secret_extra_vars: the answers for payload['secret_extra_vars'], or
     *   null when the payload gets no such field.
     *
     * @param string|null $extraVars the runner payload's extra vars (JSON, answers as placeholders)
     * @param int $verbosity the requested verbosity
     * @param Runner|null $runner the claiming runner; null counts as a runner without the capability
     * @return array{extra_vars: string|null, stored_extra_vars: string|null, verbosity: int, prefix: list<string>, secret_extra_vars: array<string, mixed>|null}
     * @throws CredentialResolutionException when the answers cannot be opened
     */
    public static function resolve(Job $job, ?string $extraVars, int $verbosity, ?Runner $runner): array
    {
        $secrets = self::open($job);
        if ($secrets === []) {
            return self::view($extraVars, $extraVars, $verbosity, [], null);
        }
        $keys = array_keys($secrets);
        $public = self::publicVars($extraVars, $keys);
        $stored = self::encode($public);
        if ($runner !== null && $runner->supports(Runner::CAPABILITY_SECRET_EXTRA_VARS)) {
            $rest = SurveySecrets::withoutKeys($public, $keys);

            return self::view($rest === [] ? null : self::encode($rest), $stored, $verbosity, [], $secrets);
        }

        return self::view(
            self::encode(SurveySecrets::reveal($public, $secrets)),
            $stored,
            min($verbosity, self::MAX_INLINE_VERBOSITY),
            [self::ENV_BINARY, self::VERBOSITY_PIN],
            null
        );
    }

    /**
     * The job.started audit fields: whether the runner reads the answers
     * from a file, and the requested verbosity when the claim caps it for a
     * runner that does not.
     *
     * @param array<string, mixed> $raw the job's runner payload
     * @return array{runner_supports_secret_extra_vars: bool, verbosity_capped_from?: int}
     */
    public static function auditContext(Job $job, array $raw, Runner $runner): array
    {
        $supported = $runner->supports(Runner::CAPABILITY_SECRET_EXTRA_VARS);
        $verbosity = (int)($raw['verbosity'] ?? 0);
        if ($supported || $verbosity <= self::MAX_INLINE_VERBOSITY || $job->getSealedSecretExtraVars() === null) {
            return ['runner_supports_secret_extra_vars' => $supported];
        }

        return ['runner_supports_secret_extra_vars' => $supported, 'verbosity_capped_from' => $verbosity];
    }

    /**
     * The job log note for a job whose verbosity the claim lowered, or null.
     * Read back from the stored command, which starts with the verbosity pin
     * only for a runner without the capability, and from the requested
     * verbosity in the runner payload.
     */
    public static function verbosityNote(Job $job): ?string
    {
        $command = $job->getAttribute('execution_command');
        if (!is_string($command) || !str_starts_with($command, self::ENV_BINARY . ' ' . self::VERBOSITY_PIN . ' ')) {
            return null;
        }
        $requested = (int)($job->decodedRunnerPayload()['verbosity'] ?? 0);

        return $requested > self::MAX_INLINE_VERBOSITY
            ? sprintf(self::VERBOSITY_NOTE, $requested, self::MAX_INLINE_VERBOSITY)
            : null;
    }

    /**
     * @return array<string, mixed>
     * @throws CredentialResolutionException
     */
    private static function open(Job $job): array
    {
        try {
            return SurveySecrets::open($job->getSealedSecretExtraVars());
        } catch (SurveySecretException) {
            throw CredentialResolutionException::surveyAnswersUndecryptable();
        }
    }

    /**
     * The public extra vars with the placeholder under every answer's key.
     *
     * @param list<string> $keys
     * @return array<int|string, mixed>
     */
    private static function publicVars(?string $extraVars, array $keys): array
    {
        $public = json_decode((string)$extraVars, true);
        $public = is_array($public) ? $public : [];
        foreach ($keys as $key) {
            $public[$key] = SurveySecrets::PLACEHOLDER;
        }

        return $public;
    }

    /**
     * Extra vars as a JSON object, with the flags of the launch, so the
     * stored command and the command sent differ only in the answers.
     *
     * @param array<int|string, mixed> $vars
     * @throws CredentialResolutionException when they cannot be encoded, which
     *     values that came from JSON never cause
     */
    private static function encode(#[\SensitiveParameter] array $vars): string
    {
        // No JSON_THROW_ON_ERROR: its exception would carry the answers in the trace.
        $json = json_encode((object)$vars, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw CredentialResolutionException::surveyAnswersUndecryptable();
        }

        return $json;
    }

    /**
     * @param list<string> $prefix
     * @param array<string, mixed>|null $secretExtraVars
     * @return array{extra_vars: string|null, stored_extra_vars: string|null, verbosity: int, prefix: list<string>, secret_extra_vars: array<string, mixed>|null}
     */
    private static function view(
        ?string $extraVars,
        ?string $storedExtraVars,
        int $verbosity,
        array $prefix,
        #[\SensitiveParameter] ?array $secretExtraVars
    ): array {
        return [
            'extra_vars' => $extraVars,
            'stored_extra_vars' => $storedExtraVars,
            'verbosity' => $verbosity,
            'prefix' => $prefix,
            'secret_extra_vars' => $secretExtraVars,
        ];
    }
}
