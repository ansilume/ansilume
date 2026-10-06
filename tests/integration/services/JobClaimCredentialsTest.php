<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\CredentialInjector;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\CredentialResolutionException;
use app\services\CredentialWriteService;
use app\services\JobClaimService;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;

/**
 * Credentials on the claim path: they resolve in precedence order, and a job
 * whose credential is gone or cannot be decrypted fails before it runs
 * instead of running without it.
 */
class JobClaimCredentialsTest extends DbTestCase
{
    private JobClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var JobClaimService $service */
        $service = \Yii::$app->get('jobClaimService');
        $this->service = $service;
    }

    /**
     * Regression: a credential deleted after the launch was skipped silently,
     * so the job ran without it.
     */
    public function testRegressionADeletedCredentialAbortsThePayload(): void
    {
        $user = $this->createUser();
        $template = $this->template($user);
        $primary = $this->storedCredential($user, Credential::TYPE_SSH_KEY, ['private_key' => 'key-primary'], 'deploy');
        $token = $this->storedCredential($user, Credential::TYPE_TOKEN, ['token' => 'tok-extra']);
        $this->attach($template, $primary, $token);
        $job = $this->launch($template, $user);
        $tokenName = $token->name;
        $token->delete();

        try {
            $this->service->buildExecutionPayload($job);
            $this->fail('the payload must not be built without the deleted credential');
        } catch (CredentialResolutionException $e) {
            $this->assertSame([[
                'id' => (int)$token->id,
                'name' => $tokenName,
                'role' => Credential::ROLE_ADDITIONAL,
                'reason' => CredentialResolutionException::REASON_MISSING,
            ]], $e->failures);
        }
        $job->refresh();
        $this->assertNull($job->execution_command, 'nothing is stored for a job that cannot run');
    }

    /**
     * Regression: credentials were ordered by id, so an additional SSH key
     * created earlier took --user and --private-key from the primary one.
     */
    public function testRegressionThePrimaryCredentialTakesTheSingleSlots(): void
    {
        $user = $this->createUser();
        $template = $this->template($user);
        $older = $this->storedCredential($user, Credential::TYPE_SSH_KEY, ['private_key' => 'key-older'], 'wrong');
        $primary = $this->storedCredential($user, Credential::TYPE_SSH_KEY, ['private_key' => 'key-primary'], 'right');
        $this->attach($template, $primary, $older);
        $job = $this->launch($template, $user);

        $payload = $this->service->buildExecutionPayload($job);

        $this->assertSame(['right', 'wrong'], array_column($payload['credentials'], 'username'));
        $this->assertSame('right', $payload['credential']['username'] ?? null);
        $result = (new CredentialInjector())->injectAll($payload['credentials']);
        try {
            $userFlag = array_search('--user', $result->args, true);
            $this->assertIsInt($userFlag);
            $this->assertSame('right', $result->args[$userFlag + 1]);
            $keyFlag = array_search('--private-key', $result->args, true);
            $this->assertIsInt($keyFlag);
            $this->assertSame('key-primary', file_get_contents($result->args[$keyFlag + 1]));
        } finally {
            CredentialInjector::cleanup($result->tempFiles);
        }
    }

    public function testClaimNextPayloadFailsTheBrokenJobAndReportsNothingToRun(): void
    {
        [$group, $runner, $template, $user] = $this->claimSetup();
        $broken = $this->queuedJob($template, $user, ['credential_id' => 999999]);

        $this->assertNull($this->service->claimNextPayload($group, $runner));

        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
        $this->assertSame(-1, (int)$broken->exit_code);
        $this->assertNotNull($broken->finished_at);
        $log = JobLog::find()->where(['job_id' => $broken->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Credential #999999 (primary) no longer exists.', (string)$log->content);
        $finished = $this->auditMeta(AuditLog::ACTION_JOB_FINISHED, (int)$broken->id);
        $this->assertSame('pre_execution_failure', $finished['reason']);
    }

    public function testClaimNextPayloadContinuesWithTheNextQueuedJob(): void
    {
        [$group, $runner, $template, $user] = $this->claimSetup();
        $broken = $this->queuedJob($template, $user, ['credential_ids' => [999999]]);
        $healthy = $this->queuedJob($template, $user, []);

        $payload = $this->service->claimNextPayload($group, $runner);

        $this->assertIsArray($payload);
        $this->assertSame($healthy->id, $payload['job_id']);
        $broken->refresh();
        $this->assertSame(Job::STATUS_FAILED, $broken->status);
    }

    public function testClaimNextPayloadGivesUpAfterTheMaximumAttempts(): void
    {
        [$group, $runner, $template, $user] = $this->claimSetup();
        $jobs = [];
        for ($i = 0; $i <= JobClaimService::MAX_CLAIM_ATTEMPTS; $i++) {
            $jobs[] = $this->queuedJob($template, $user, ['credential_id' => 999999]);
        }

        $this->assertNull($this->service->claimNextPayload($group, $runner));

        $statuses = array_map(static function (Job $job): string {
            $job->refresh();
            return $job->status;
        }, $jobs);
        $expected = array_fill(0, JobClaimService::MAX_CLAIM_ATTEMPTS, Job::STATUS_FAILED);
        $expected[] = Job::STATUS_QUEUED;
        $this->assertSame($expected, $statuses);
    }

    public function testAnUndecryptableScmCredentialFailsTheJob(): void
    {
        [$group, $runner, $template, $user] = $this->claimSetup();
        $scm = $this->createCredential((int)$user->id, Credential::TYPE_SSH_KEY);
        $scm->secret_data = 'not-a-ciphertext';
        $scm->save(false);
        $project = $template->project;
        $this->assertNotNull($project);
        $project->scm_credential_id = $scm->id;
        $project->save(false);
        $job = $this->queuedJob($template, $user, []);

        $this->assertNull($this->service->claimNextPayload($group, $runner));

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $log = JobLog::find()->where(['job_id' => $job->id, 'stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('(scm) cannot be decrypted.', (string)$log->content);
    }

    public function testTheStartAuditNamesRunnerAndCredentialsButNoSecret(): void
    {
        [$group, $runner, $template, $user] = $this->claimSetup();
        $token = $this->storedCredential($user, Credential::TYPE_TOKEN, ['token' => 'tok-audit-secret']);
        $template->credential_id = $token->id;
        $template->save(false);
        $job = $this->launch($template, $user);

        $payload = $this->service->claimNextPayload($group, $runner);

        $this->assertIsArray($payload);
        $meta = $this->auditMeta(AuditLog::ACTION_JOB_STARTED, (int)$job->id);
        $this->assertSame($runner->id, $meta['runner_id']);
        $this->assertSame($runner->name, $meta['runner_name']);
        $this->assertSame([[
            'id' => (int)$token->id,
            'name' => $token->name,
            'credential_type' => Credential::TYPE_TOKEN,
            'role' => Credential::ROLE_PRIMARY,
            'deleted' => false,
        ]], $meta['credentials']);
        $this->assertStringNotContainsString('tok-audit-secret', (string)json_encode($meta));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @return array{0: RunnerGroup, 1: Runner, 2: JobTemplate, 3: User}
     */
    private function claimSetup(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup((int)$user->id);
        $runner = $this->createRunner((int)$group->id, (int)$user->id);
        $project = $this->createProject((int)$user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$this->createInventory((int)$user->id)->id, (int)$group->id, (int)$user->id);

        return [$group, $runner, $template, $user];
    }

    private function template(User $user): JobTemplate
    {
        $userId = (int)$user->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(User $owner, string $type, array $secrets, ?string $username = null): Credential
    {
        $credential = new Credential();
        $credential->name = 'claim-cred-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->username = $username;
        $credential->created_by = (int)$owner->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    private function attach(JobTemplate $template, Credential $primary, Credential ...$additional): void
    {
        $template->credential_id = $primary->id;
        $template->save(false);
        foreach ($additional as $position => $credential) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credential->id,
                'sort_order' => $position,
            ])->execute();
        }
    }

    private function launch(JobTemplate $template, User $user): Job
    {
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $template->refresh();

        return $launcher->launch($template, (int)$user->id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function queuedJob(JobTemplate $template, User $user, array $payload): Job
    {
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_QUEUED);
        $job->runner_payload = (string)json_encode($payload + [
            'project_id' => $template->project_id,
            'inventory_id' => $template->inventory_id,
            'playbook' => 'site.yml',
        ]);
        $job->save(false);

        return $job;
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
