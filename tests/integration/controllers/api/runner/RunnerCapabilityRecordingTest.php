<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\components\RunnerHttpClient;
use app\controllers\api\runner\JobsController;
use app\models\AuditLog;
use app\models\Job;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Every authenticated runner request records the capabilities the runner
 * reports in its JSON body (`capabilities`, a list of names). Only names the
 * server knows (Runner::CAPABILITIES) are stored. The server uses them to
 * tell whether a runner honours a project's vault password source.
 */
class RunnerCapabilityRecordingTest extends WebControllerTestCase
{
    private RunnerGroup $group;
    private Runner $runner;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('runner-capabilities')->id;
        $this->group = $this->createRunnerGroup($this->userId);
        $this->runner = $this->createRunner((int)$this->group->id, $this->userId);
        $token = Runner::generateToken();
        $this->runner->token_hash = $token['hash'];
        $this->runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    public function testWhatTheRunnerClientSendsIsRecorded(): void
    {
        $this->heartbeat(['software_version' => '2.8.0', 'capabilities' => RunnerHttpClient::CAPABILITIES]);

        $runner = $this->reloaded();
        $this->assertSame('vault_password_source', $runner->capabilities);
        $this->assertSame([Runner::CAPABILITY_VAULT_PASSWORD_SOURCE], $runner->capabilityList());
        $this->assertTrue($runner->supports(Runner::CAPABILITY_VAULT_PASSWORD_SOURCE));
        $this->assertSame('2.8.0', $runner->software_version);
    }

    public function testUnknownNamesAndNonStringEntriesAreIgnored(): void
    {
        $this->heartbeat(['capabilities' => ['teleport', 42, ['vault_password_source'], null, 'vault_password_source', 'vault_password_source']]);

        $this->assertSame('vault_password_source', $this->reloaded()->capabilities);
    }

    public function testAListWithoutKnownNamesRecordsNone(): void
    {
        $this->storeCapabilities('vault_password_source');

        $this->heartbeat(['capabilities' => ['teleport', 'VAULT_PASSWORD_SOURCE', ' vault_password_source']]);

        $runner = $this->reloaded();
        $this->assertSame('', $runner->capabilities);
        $this->assertSame([], $runner->capabilityList());
        $this->assertFalse($runner->supports(Runner::CAPABILITY_VAULT_PASSWORD_SOURCE));
    }

    public function testAnEmptyListRecordsNone(): void
    {
        $this->storeCapabilities('vault_password_source');

        $this->heartbeat(['capabilities' => []]);

        $this->assertSame('', $this->reloaded()->capabilities);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedProvider(): array
    {
        return [
            'a string' => ['vault_password_source'],
            'a map' => [['first' => 'teleport']],
            'null' => [null],
            'a number' => [1],
            'a boolean' => [true],
        ];
    }

    /**
     * A field that is not a list is ignored: the recorded value stays.
     *
     * @dataProvider malformedProvider
     */
    public function testAFieldThatIsNotAListIsIgnored(mixed $capabilities): void
    {
        $this->storeCapabilities('vault_password_source');

        $this->heartbeat(['capabilities' => $capabilities]);

        $this->assertSame('vault_password_source', $this->reloaded()->capabilities);
    }

    public function testABodyThatIsNotAJsonObjectIsIgnored(): void
    {
        $this->storeCapabilities('vault_password_source');
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams('vault_password_source');

        $result = (new JobsController('runner-api', \Yii::$app))->runAction('heartbeat');

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame('vault_password_source', $this->reloaded()->capabilities);
    }

    /**
     * Regression guard for downgrades: every request of a runner that knows
     * about capabilities carries the field, so a request without it comes
     * from an older runner build that honours none of them. Keeping the old
     * value would claim the vault isolation for a runner that no longer
     * applies it.
     */
    public function testARequestWithoutTheFieldClearsTheRecordedCapabilities(): void
    {
        $this->storeCapabilities('vault_password_source');

        $this->heartbeat(['software_version' => '2.7.0']);

        $runner = $this->reloaded();
        $this->assertNull($runner->capabilities);
        $this->assertFalse($runner->supports(Runner::CAPABILITY_VAULT_PASSWORD_SOURCE));
        $this->assertSame('2.7.0', $runner->software_version, 'the rest of the request is recorded as usual');
    }

    public function testAnOlderRunnerThatNeverReportedStaysUnknown(): void
    {
        $this->heartbeat([]);

        $runner = $this->reloaded();
        $this->assertNull($runner->capabilities);
        $this->assertNotNull($runner->last_seen_at);
    }

    /**
     * Bounded: a runner cannot make the server walk an arbitrarily long list.
     */
    public function testOnlyTheFirstReportedEntriesAreConsidered(): void
    {
        $padding = array_map(static fn (int $i): string => 'unknown-' . $i, range(1, 32));

        $this->heartbeat(['capabilities' => [...$padding, 'vault_password_source']]);
        $this->assertSame('', $this->reloaded()->capabilities);

        $this->heartbeat(['capabilities' => [...array_slice($padding, 1), 'vault_password_source']]);
        $this->assertSame('vault_password_source', $this->reloaded()->capabilities);
    }

    public function testAnUnchangedValueIsNotWrittenAgain(): void
    {
        $this->storeCapabilities('vault_password_source');
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['capabilities' => ['vault_password_source']]);
        $runner = $this->reloaded();

        $this->assertSame([], $this->capabilityChanges($runner));
        $this->assertSame('vault_password_source', $runner->capabilities);

        $request->setBodyParams([]);
        $this->assertSame(['capabilities' => null], $this->capabilityChanges($runner));
        $this->assertNull($runner->capabilities, 'the model passed on to the action is updated too');
    }

    /**
     * The claim in the same request already sees what the runner reported:
     * the job.started audit entry says whether it honours the vault mode.
     */
    public function testTheClaimSeesTheCapabilitiesOfTheSameRequest(): void
    {
        $project = $this->createProject($this->userId);
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->group->id,
            $this->userId
        );
        $job = $this->createJob((int)$template->id, $this->userId, Job::STATUS_QUEUED);
        $job->runner_payload = (string)json_encode(['project_id' => $project->id, 'inventory_id' => $template->inventory_id]);
        $job->save(false);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['capabilities' => ['vault_password_source']]);

        $result = (new JobsController('runner-api', \Yii::$app))->runAction('claim');

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame($job->id, $result['data']['job_id']);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $result['data']['vault_password_source']);
        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_STARTED, 'object_type' => 'job', 'object_id' => $job->id])
            ->one();
        $this->assertNotNull($log);
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $meta['vault_password_source']);
        $this->assertTrue($meta['runner_supports_vault_password_source']);
        $this->assertSame('vault_password_source', $this->reloaded()->capabilities);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function heartbeat(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        $result = (new JobsController('runner-api', \Yii::$app))->runAction('heartbeat');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);

        return $result;
    }

    /**
     * @return array<string, string|null>
     */
    private function capabilityChanges(Runner $runner): array
    {
        $method = new \ReflectionMethod(JobsController::class, 'capabilityChanges');
        $method->setAccessible(true);
        /** @var array<string, string|null> $changes */
        $changes = $method->invoke(new JobsController('runner-api', \Yii::$app), $runner);

        return $changes;
    }

    private function storeCapabilities(?string $capabilities): void
    {
        Runner::updateAll(['capabilities' => $capabilities], ['id' => $this->runner->id]);
    }

    private function reloaded(): Runner
    {
        $runner = Runner::findOne($this->runner->id);
        $this->assertNotNull($runner);

        return $runner;
    }
}
