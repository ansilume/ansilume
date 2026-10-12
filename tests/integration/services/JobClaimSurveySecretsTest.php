<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\CredentialResolutionException;
use app\services\JobClaimSecrets;
use app\services\JobClaimService;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * Password survey answers on the claim path. A launch stores them sealed;
 * the claim opens them on the server and hands them over in the way the
 * claiming runner supports:
 * - runners reporting the secret_extra_vars capability get them in their own
 *   payload field, outside the command;
 * - every other runner gets them inline in the command, with the verbosity
 *   capped at 3 and pinned through `/usr/bin/env ANSIBLE_VERBOSITY=0`.
 * The stored command never contains an answer, and a job whose answers
 * cannot be decrypted fails before it runs.
 */
class JobClaimSurveySecretsTest extends DbTestCase
{
    private const CAPABLE = 'vault_password_source,secret_extra_vars';
    private const PREFIX = ['/usr/bin/env', 'ANSIBLE_VERBOSITY=0'];

    private JobClaimService $service;
    private User $user;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var JobClaimService $service */
        $service = \Yii::$app->get('jobClaimService');
        $this->service = $service;
        $this->user = $this->createUser('claim-secrets');
        $this->template = $this->createPasswordSurveyTemplate((int)$this->user->id);
    }

    // -- Runners with the capability ------------------------------------------------

    public function testACapableRunnerGetsTheAnswersOutsideTheCommand(): void
    {
        $job = $this->jobWithAnswers(4);

        $payload = $this->service->buildExecutionPayload($job, $this->runner(self::CAPABLE));

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], $payload['secret_extra_vars'] ?? null);
        $this->assertSame('{"app_env":"staging"}', $payload['extra_vars']);
        $this->assertSame(4, $payload['verbosity'], 'the requested verbosity is kept');
        $this->assertSame('ansible-playbook', $payload['command'][0], 'no prefix');
        $this->assertContains('-vvvv', $payload['command']);
        $this->assertContains('{"app_env":"staging"}', $payload['command']);
        $this->assertCommandHasNoAnswer($payload['command']);
        $this->assertStringNotContainsString(SurveySecrets::PLACEHOLDER, implode(' ', $payload['command']));

        $this->assertSame(
            str_replace('{"app_env":"staging"}', '{"app_env":"staging","db_password":"$encrypted$"}', implode(' ', $payload['command'])),
            $this->storedCommand($job),
            'the stored command shows every extra var, the answer as the placeholder'
        );
    }

    /**
     * Only password answers: the runner command carries no --extra-vars at
     * all, the stored one shows the placeholder.
     */
    public function testACapableRunnerWithOnlyPasswordAnswersGetsNoInlineExtraVars(): void
    {
        $job = $this->createJobWithSealedSecrets($this->template, (int)$this->user->id, [self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], []);

        $payload = $this->service->buildExecutionPayload($job, $this->runner(self::CAPABLE));

        $this->assertNull($payload['extra_vars']);
        $this->assertNotContains('--extra-vars', $payload['command']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], $payload['secret_extra_vars'] ?? null);
        $this->assertStringEndsWith('--extra-vars {"db_password":"$encrypted$"}', $this->storedCommand($job));
    }

    // -- Runners without the capability ---------------------------------------------

    /**
     * Runners up to 2.8.1, 2.8 runners reporting only the vault capability,
     * and callers that name no runner.
     *
     * @return array<string, array{0: string|null|false}>
     */
    public static function runnerWithoutCapabilityProvider(): array
    {
        return [
            'runner older than 2.8' => [null],
            'runner 2.8' => ['vault_password_source'],
            'no runner given' => [false],
        ];
    }

    /**
     * @dataProvider runnerWithoutCapabilityProvider
     * @param string|null|false $capabilities false: buildExecutionPayload() without a runner
     */
    public function testARunnerWithoutTheCapabilityGetsTheAnswersInlineWithTheVerbosityPinned(string|null|false $capabilities): void
    {
        $job = $this->jobWithAnswers(5);

        $payload = $capabilities === false
            ? $this->service->buildExecutionPayload($job)
            : $this->service->buildExecutionPayload($job, $this->runner($capabilities));

        $this->assertArrayNotHasKey('secret_extra_vars', $payload);
        $this->assertSame('{"app_env":"staging","db_password":"example-db-answer-4711"}', $payload['extra_vars']);
        $this->assertSame(3, $payload['verbosity'], 'payload verbosity is capped too, for runners that build the command themselves');
        $this->assertSame(self::PREFIX, array_slice($payload['command'], 0, 2));
        $this->assertSame('ansible-playbook', $payload['command'][2]);
        $this->assertContains('-vvv', $payload['command']);
        $this->assertNotContains('-vvvvv', $payload['command']);

        $stored = $this->storedCommand($job);
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, $stored);
        $this->assertStringStartsWith('/usr/bin/env ANSIBLE_VERBOSITY=0 ansible-playbook ', $stored);
    }

    /**
     * Regression for the design: what an older runner runs and what the job
     * page shows differ only in the answers.
     */
    public function testTheOldRunnerCommandAndTheStoredCommandDifferOnlyInTheAnswers(): void
    {
        $job = $this->jobWithAnswers(4);

        $sent = implode(' ', $this->service->buildExecutionPayload($job, $this->runner(null))['command']);

        $this->assertStringContainsString(self::SURVEY_PASSWORD_ANSWER, $sent);
        $this->assertSame(
            str_replace((string)json_encode(self::SURVEY_PASSWORD_ANSWER), (string)json_encode(SurveySecrets::PLACEHOLDER), $sent),
            $this->storedCommand($job)
        );
    }

    /**
     * Answers keep their JSON type; the extra vars argument is the only one
     * that differs, and only under the answers' keys.
     */
    public function testAnswersOfAnyTypeDifferOnlyUnderTheirKeys(): void
    {
        $secrets = [self::SURVEY_PASSWORD_FIELD => ['nested' => ['x', 42], 'flag' => true], 'pin' => 4711, 'ratio' => 0.5];
        $this->template->verbosity = 2;
        $this->template->save(false);
        $job = $this->createJobWithSealedSecrets($this->template, (int)$this->user->id, $secrets, ['app_env' => 'prod', 'release' => 12]);

        $sent = $this->service->buildExecutionPayload($job, $this->runner(null))['command'];
        $stored = explode(' ', $this->storedCommand($job));

        $index = array_search('--extra-vars', $sent, true);
        $this->assertIsInt($index);
        $this->assertCount(count($sent), $stored);
        $this->assertSame(array_diff_key($sent, [$index + 1 => true]), array_diff_key($stored, [$index + 1 => true]));
        $revealed = json_decode($sent[$index + 1], true);
        $this->assertSame(['app_env' => 'prod', 'release' => 12] + $secrets, $revealed);
        $this->assertSame(
            SurveySecrets::split($revealed, array_keys($secrets))['public'],
            json_decode($stored[$index + 1], true)
        );
        $this->assertContains('-vv', $sent, 'verbosity at or below 3 is kept');
    }

    // -- Jobs without answers -------------------------------------------------------

    /**
     * Behaviour unchanged: every runner gets the same payload as before, the
     * extra vars exactly as launched, no prefix, no cap, no extra field.
     */
    public function testAJobWithoutAnswersIsClaimedAsBefore(): void
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$this->user->id)->id,
            (int)$this->createInventory((int)$this->user->id)->id,
            (int)$this->createRunnerGroup((int)$this->user->id)->id,
            (int)$this->user->id
        );
        $template->extra_vars = '{"env":"prod","path":"/srv/app","name":"Jürgen"}';
        $template->verbosity = 5;
        $template->save(false);
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $job = $launcher->launch($template, (int)$this->user->id);
        $raw = $job->decodedRunnerPayload();

        $payloads = [
            $this->service->buildExecutionPayload($job),
            $this->service->buildExecutionPayload($job, $this->runner(null)),
            $this->service->buildExecutionPayload($job, $this->runner(self::CAPABLE)),
        ];

        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('secret_extra_vars', $payload);
            $this->assertSame($raw['extra_vars'], $payload['extra_vars'], 'byte for byte');
            $this->assertSame(5, $payload['verbosity']);
            $this->assertSame('ansible-playbook', $payload['command'][0]);
            $this->assertContains('-vvvvv', $payload['command']);
            $this->assertSame($payloads[0]['command'], $payload['command']);
        }
        $this->assertSame(implode(' ', $payloads[0]['command']), $this->storedCommand($job));
    }

    /**
     * Code that runs before migration m000080 (no secret_extra_vars column)
     * claims jobs as before.
     */
    public function testAJobReadWithoutTheNewColumnIsClaimedAsBefore(): void
    {
        $launched = $this->jobWithAnswers(5);
        $job = JobWithoutSecretColumn::findOne($launched->id);
        $this->assertInstanceOf(JobWithoutSecretColumn::class, $job);

        $payload = $this->service->buildExecutionPayload($job, $this->runner(null));

        $this->assertSame('ansible-playbook', $payload['command'][0]);
        $this->assertSame(5, $payload['verbosity']);
        $this->assertArrayNotHasKey('secret_extra_vars', $payload);
    }

    // -- Answers that cannot be decrypted -------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function brokenKeyProvider(): array
    {
        return [
            'APP_SECRET_KEY changed' => ['another-key-of-32-characters-xyz'],
            'APP_SECRET_KEY missing' => [''],
        ];
    }

    /**
     * @dataProvider brokenKeyProvider
     */
    public function testAnswersThatCannotBeDecryptedStopThePayloadAndStoreNothing(string $key): void
    {
        $job = $this->jobWithAnswers(4);
        $original = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = $key;
        try {
            $this->service->buildExecutionPayload($job, $this->runner(self::CAPABLE));
            $this->fail('expected a CredentialResolutionException');
        } catch (CredentialResolutionException $e) {
            $this->assertSame(CredentialResolutionException::surveyAnswersUndecryptable()->getMessage(), $e->getMessage());
            $this->assertSame([], $e->failures);
        } finally {
            if ($original === null) {
                unset($_ENV['APP_SECRET_KEY']);
            } else {
                $_ENV['APP_SECRET_KEY'] = $original;
            }
        }
        $job->refresh();
        $this->assertNull($job->execution_command, 'nothing is stored for a job that cannot run');
    }

    /**
     * Never a 500 and never a job stuck in running: the claim fails the job
     * with an operator message and hands out the next one.
     */
    public function testClaimNextPayloadFailsTheJobAndContinuesWithTheNext(): void
    {
        [$group, $runner] = $this->groupAndRunner(self::CAPABLE);
        $broken = $this->jobWithAnswers(0);
        $broken->setSealedSecretExtraVars('not-a-ciphertext');
        $broken->save(false);
        $healthy = $this->jobWithAnswers(0);

        $payload = $this->service->claimNextPayload($group, $runner);

        $this->assertIsArray($payload);
        $this->assertSame($healthy->id, $payload['job_id']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], $payload['secret_extra_vars'] ?? null);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
        $this->assertSame(-1, (int)$broken->exit_code);
        $this->assertNull($broken->execution_command);
        $log = JobLog::find()->where(['job_id' => $broken->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertSame(
            "The job's encrypted survey answers cannot be decrypted (was APP_SECRET_KEY changed?). "
                . 'Launch the job template again and enter the password answers.',
            $log->content
        );
        $this->assertSame('pre_execution_failure', $this->auditMeta(AuditLog::ACTION_JOB_FINISHED, (int)$broken->id)['reason']);
    }

    /**
     * Extra vars that cannot be encoded again (an out-of-range number written
     * into the payload by hand; a launch never stores one) fail the job the
     * same way instead of running it without them.
     */
    public function testExtraVarsThatCannotBeEncodedAgainFailTheJobBeforeItRuns(): void
    {
        [$group, $runner] = $this->groupAndRunner(null);
        $job = $this->jobWithAnswers(0);
        $raw = $job->decodedRunnerPayload();
        $raw['extra_vars'] = '{"huge":1e999,"db_password":"$encrypted$"}';
        $job->runner_payload = (string)json_encode($raw);
        $job->save(false);

        $this->assertNull($this->service->claimNextPayload($group, $runner));

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertNull($job->execution_command);
    }

    // -- Audit ------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: int, 2: bool, 3: bool, 4: int|null}>
     */
    public static function auditProvider(): array
    {
        return [
            'capable runner' => [self::CAPABLE, 5, true, true, null],
            'older runner, verbosity capped' => [null, 5, true, false, 5],
            'older runner, verbosity 4 capped' => ['vault_password_source', 4, true, false, 4],
            'older runner, verbosity 3' => [null, 3, true, false, null],
            'older runner, no answers' => [null, 5, false, false, null],
        ];
    }

    /**
     * @dataProvider auditProvider
     */
    public function testTheStartAuditRecordsHowTheAnswersTravelled(
        ?string $capabilities,
        int $verbosity,
        bool $withAnswers,
        bool $supported,
        ?int $cappedFrom
    ): void {
        [$group, $runner] = $this->groupAndRunner($capabilities);
        $job = $withAnswers ? $this->jobWithAnswers($verbosity) : $this->jobWithoutAnswers($verbosity);

        $this->assertIsArray($this->service->claimNextPayload($group, $runner));

        $meta = $this->auditMeta(AuditLog::ACTION_JOB_STARTED, (int)$job->id);
        $this->assertSame($supported, $meta['runner_supports_secret_extra_vars']);
        if ($cappedFrom === null) {
            $this->assertArrayNotHasKey('verbosity_capped_from', $meta);
        } else {
            $this->assertSame($cappedFrom, $meta['verbosity_capped_from']);
        }
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)json_encode($meta));
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * A queued job of the password survey template, verbosity $verbosity.
     */
    private function jobWithAnswers(int $verbosity): Job
    {
        $this->template->verbosity = $verbosity;
        $this->template->save(false);

        return $this->createJobWithSealedSecrets($this->template, (int)$this->user->id);
    }

    private function jobWithoutAnswers(int $verbosity): Job
    {
        $this->template->verbosity = $verbosity;
        $this->template->save(false);

        return $this->createJobWithSealedSecrets($this->template, (int)$this->user->id, []);
    }

    private function runner(?string $capabilities): Runner
    {
        $runner = $this->createRunner((int)$this->template->runner_group_id, (int)$this->user->id);
        $runner->capabilities = $capabilities;
        $runner->save(false);

        return $runner;
    }

    /**
     * @return array{0: RunnerGroup, 1: Runner}
     */
    private function groupAndRunner(?string $capabilities): array
    {
        $group = RunnerGroup::findOne($this->template->runner_group_id);
        $this->assertNotNull($group);

        return [$group, $this->runner($capabilities)];
    }

    private function storedCommand(Job $job): string
    {
        $job->refresh();
        $this->assertIsString($job->execution_command);

        return $job->execution_command;
    }

    /**
     * @param array<int, string> $command
     */
    private function assertCommandHasNoAnswer(array $command): void
    {
        foreach ($command as $argument) {
            $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, $argument);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMeta(string $action, int $jobId): array
    {
        $log = AuditLog::find()->where(['action' => $action, 'object_type' => 'job', 'object_id' => $jobId])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log, "no {$action} audit entry for job #{$jobId}");
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);

        return $meta;
    }
}

/**
 * A job read by code that runs before migration m000080.
 */
class JobWithoutSecretColumn extends Job
{
    public function attributes(): array
    {
        return array_values(array_diff(parent::attributes(), ['secret_extra_vars']));
    }
}
