<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\SurveySecrets;
use app\models\WorkflowJob;
use app\tests\integration\DbTestCase;

/**
 * What a workflow run keeps of its launch (m000084): the public extra vars
 * in workflow_job.extra_vars, the password answers sealed in
 * workflow_job.secret_extra_vars; and which runs are active.
 */
class WorkflowJobLaunchVarsTest extends DbTestCase
{
    private int $userId;
    private int $workflowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('wj_vars')->id;
        $this->workflowId = (int)$this->createWorkflowTemplate($this->userId)->id;
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function statusProvider(): array
    {
        return [
            'running' => [WorkflowJob::STATUS_RUNNING, true],
            'succeeded' => [WorkflowJob::STATUS_SUCCEEDED, false],
            'failed' => [WorkflowJob::STATUS_FAILED, false],
            'canceled' => [WorkflowJob::STATUS_CANCELED, false],
            'unknown' => ['paused', false],
        ];
    }

    /**
     * Only a running run is active: one that waits at a pause or approval
     * step, or for a child job, is running too.
     *
     * @dataProvider statusProvider
     */
    public function testOnlyARunningRunIsActive(string $status, bool $active): void
    {
        $run = new WorkflowJob();
        $run->status = $status;

        $this->assertSame($active, $run->isActive());
        $this->assertSame([WorkflowJob::STATUS_RUNNING], WorkflowJob::activeStatuses());
        $this->assertSame($active, in_array($status, WorkflowJob::activeStatuses(), true));
    }

    public function testEveryStatusIsActiveOrFinished(): void
    {
        foreach (WorkflowJob::statuses() as $status) {
            $run = new WorkflowJob();
            $run->status = $status;
            $this->assertNotSame($run->isActive(), $run->isFinished(), $status);
        }
    }

    public function testTheLaunchExtraVarsAreStoredAndReadBack(): void
    {
        $vars = [
            'env' => 'prod',
            'db_password' => SurveySecrets::PLACEHOLDER,
            'replicas' => 3,
            'nested' => ['hosts' => ['web1', 'web2'], 'tls' => true],
            'note' => 'Grüße',
        ];
        $run = $this->newRun();
        $this->assertSame([], $run->launchExtraVars(), 'none by default');

        $run->setLaunchExtraVars($vars);
        $this->assertTrue($run->save(), 'the launch saves with validation');

        $reloaded = $this->reload($run);
        $this->assertSame($vars, $reloaded->launchExtraVars());
        $this->assertSame(
            '{"env":"prod","db_password":"$encrypted$","replicas":3,"nested":{"hosts":["web1","web2"],"tls":true},"note":"Grüße"}',
            $this->storedExtraVars($run),
            'a JSON object, unicode unescaped as job.extra_vars'
        );
    }

    /**
     * Keys "0" to "n-1" stay an object: json_encode() would write a list.
     */
    public function testNumericKeysAreStoredAsAnObject(): void
    {
        $run = $this->newRun();

        $run->setLaunchExtraVars(['first', 'second']);
        $this->assertTrue($run->save(false));

        $this->assertSame('{"0":"first","1":"second"}', $this->storedExtraVars($run));
        $this->assertSame(['first', 'second'], $this->reload($run)->launchExtraVars());
    }

    public function testNoVarsStoreNone(): void
    {
        $run = $this->newRun();
        $run->setLaunchExtraVars(['a' => 1]);
        $this->assertTrue($run->save(false));

        $run->setLaunchExtraVars([]);
        $this->assertTrue($run->save(false));

        $this->assertNull($this->storedExtraVars($run));
        $this->assertSame([], $this->reload($run)->launchExtraVars());
    }

    /**
     * @return array<string, array{0: string|null, 1: array<string, mixed>}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'none' => [null, []],
            'empty' => ['', []],
            'an object after whitespace' => ["  \n{\"a\":1}", ['a' => 1]],
            'a list' => ['[1,2]', []],
            'a string' => ['"text"', []],
            'broken JSON' => ['{"a":', []],
            'not JSON' => ['a=1', []],
        ];
    }

    /**
     * Only a stored JSON object counts as vars.
     *
     * @dataProvider storedValueProvider
     * @param array<string, mixed> $expected
     */
    public function testOnlyAStoredJsonObjectCounts(?string $stored, array $expected): void
    {
        $run = $this->newRun();
        $run->setAttribute('extra_vars', $stored);

        $this->assertSame($expected, $run->launchExtraVars());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unencodableProvider(): array
    {
        return [
            'a number too large for JSON' => [INF, 'The extra vars cannot be stored: they contain a number too large for JSON.'],
            'text that is not UTF-8' => ["\xB1\x31", 'The extra vars cannot be stored: they contain characters that are not valid UTF-8.'],
        ];
    }

    /**
     * A launch refuses such vars first; if they get here anyway, nothing is
     * stored and the message names no value.
     *
     * @dataProvider unencodableProvider
     */
    public function testVarsJsonCannotHoldAreRefused(mixed $value, string $message): void
    {
        $run = $this->newRun();
        $run->setLaunchExtraVars(['kept' => true]);

        try {
            $run->setLaunchExtraVars(['bad' => $value]);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame(['kept' => true], $run->launchExtraVars(), 'the stored vars stay');
    }

    public function testThePasswordAnswersAreStoredSealed(): void
    {
        $answers = ['db_password' => 'example-run-answer-4711', 'pin' => ['n' => 1]];
        $run = $this->newRun();
        $this->assertNull($run->getSealedSecretExtraVars(), 'none by default');

        $run->setLaunchExtraVars(['db_password' => SurveySecrets::PLACEHOLDER, 'pin' => SurveySecrets::PLACEHOLDER]);
        $run->setSealedSecretExtraVars(SurveySecrets::seal($answers));
        $this->assertTrue($run->save());

        $reloaded = $this->reload($run);
        $sealed = $reloaded->getSealedSecretExtraVars();
        $this->assertNotNull($sealed);
        $this->assertStringNotContainsString('example-run-answer-4711', $sealed);
        $this->assertSame($answers, SurveySecrets::open($sealed));
        $this->assertStringNotContainsString('example-run-answer-4711', (string)$this->storedExtraVars($run));
    }

    /**
     * A form or API body can choose neither column, and toArray() leaves
     * the sealed answers out.
     */
    public function testLoadNeverAssignsTheVarsAndToArrayHidesTheAnswers(): void
    {
        $run = $this->newRun();
        $run->setSealedSecretExtraVars('c2VhbGVkLWZvci10ZXN0cw==');

        $run->load(['WorkflowJob' => ['extra_vars' => '{"forged":1}', 'secret_extra_vars' => 'forged', 'status' => WorkflowJob::STATUS_FAILED]]);

        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status, 'other fields still load');
        $this->assertSame([], $run->launchExtraVars());
        $this->assertSame('c2VhbGVkLWZvci10ZXN0cw==', $run->getSealedSecretExtraVars());
        $this->assertNotContains('extra_vars', $run->safeAttributes());
        $this->assertNotContains('secret_extra_vars', $run->safeAttributes());
        $run->setLaunchExtraVars(['env' => 'prod']);
        $data = $run->toArray();
        $this->assertArrayNotHasKey('secret_extra_vars', $data);
        $this->assertSame('{"env":"prod"}', $data['extra_vars'] ?? null);
    }

    /**
     * Regression guard for updates: code can run before m000084. Without the
     * columns a run has no launch vars and saves, and only storing vars
     * fails, with an actionable message.
     */
    public function testBeforeTheMigrationARunHasNoVarsAndOnlyStoringThemFails(): void
    {
        $run = new class () extends WorkflowJob {
            /**
             * @return list<string>
             */
            public function attributes(): array
            {
                return array_values(array_diff(array_map('strval', parent::attributes()), ['extra_vars', 'secret_extra_vars']));
            }
        };
        $run->workflow_template_id = $this->workflowId;
        $run->launched_by = $this->userId;
        $run->status = WorkflowJob::STATUS_RUNNING;

        $this->assertSame([], $run->launchExtraVars());
        $this->assertNull($run->getSealedSecretExtraVars());
        $run->setLaunchExtraVars([]);
        $run->setSealedSecretExtraVars(null);
        $this->assertTrue($run->save(), 'a run without launch vars saves');

        $stores = [
            static fn () => $run->setLaunchExtraVars(['env' => 'prod']),
            static fn () => $run->setSealedSecretExtraVars('c2VhbGVk'),
        ];
        foreach ($stores as $store) {
            try {
                $store();
                $this->fail('expected a RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertSame(WorkflowJob::MIGRATION_PENDING, $e->getMessage());
                $this->assertSame('The database update has not finished; run the migrations.', $e->getMessage());
            }
        }
    }

    private function newRun(): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = $this->workflowId;
        $run->launched_by = $this->userId;
        $run->status = WorkflowJob::STATUS_RUNNING;

        return $run;
    }

    private function reload(WorkflowJob $run): WorkflowJob
    {
        $reloaded = WorkflowJob::findOne($run->id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }

    private function storedExtraVars(WorkflowJob $run): ?string
    {
        $value = \Yii::$app->db->createCommand('SELECT extra_vars FROM {{%workflow_job}} WHERE id = :id', [':id' => $run->id])->queryScalar();

        return $value === null ? null : (string)$value;
    }
}
