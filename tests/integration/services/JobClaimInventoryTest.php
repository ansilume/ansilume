<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobClaimRefusedException;
use app\tests\integration\DbTestCase;

/**
 * The claim fails closed on the inventory a job was launched with
 * (JobClaimService::resolveInventory()): a job whose launch record names no
 * inventory, whose inventory is gone, whose static inventory has no content
 * or whose file inventory names no source path fails before execution,
 * instead of running against the runner itself; and the inventory is
 * checked before any credential is decrypted. A dynamic inventory without a
 * source path still runs: its repository names its inventory.
 */
class JobClaimInventoryTest extends DbTestCase
{
    use JobClaimFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaimFixtures();
    }

    protected function tearDown(): void
    {
        $this->restoreClaimServices();
        parent::tearDown();
    }

    /**
     * Regression: a queued job whose inventory had been deleted (after its
     * template was pointed at another one) ran against localhost on the
     * runner.
     */
    public function testRegressionAJobWhoseInventoryNoLongerExistsRanAgainstLocalhost(): void
    {
        $job = $this->launchedJob($this->template);
        $launchInventoryId = (int)$this->inventory->id;
        $this->repointAndDeleteTheLaunchInventory();

        $payload = $this->claimNext();

        $this->assertNull($payload, 'the runner gets no job');
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame([sprintf(JobClaimRefusedException::INVENTORY_MISSING, $launchInventoryId)], $this->logLinesOf($job));
        $this->assertSame(
            ['exit_code' => -1, 'status' => Job::STATUS_FAILED, 'reason' => 'pre_execution_failure', 'claim_refusal' => 'inventory_missing', 'inventory_id' => $launchInventoryId],
            $this->auditContextOf($job)
        );
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noContentProvider(): array
    {
        return [
            'NULL' => [null],
            'empty' => [''],
            'whitespace' => [" \n\t"],
        ];
    }

    /**
     * Regression: a static inventory without content ran against localhost
     * (NULL), or against no host at all.
     *
     * @dataProvider noContentProvider
     */
    public function testRegressionAStaticInventoryWithoutContentRanAgainstLocalhost(?string $content): void
    {
        Inventory::updateAll(['content' => $content, 'name' => 'web "eu"'], ['id' => $this->inventory->id]);
        $job = $this->launchedJob($this->template);

        $payload = $this->claimNext();

        $this->assertNull($payload, 'the runner gets no job');
        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            [sprintf(JobClaimRefusedException::INVENTORY_EMPTY, $this->inventory->id, SurveyDefinition::quote('web "eu"'))],
            $this->logLinesOf($job)
        );
        $this->assertStringContainsString('static inventory #' . $this->inventory->id . ' "web \"eu\"" has no content', $this->logLinesOf($job)[0]);
        $this->assertSame(
            ['claim_refusal' => 'inventory_empty', 'inventory_id' => (int)$this->inventory->id],
            array_slice($this->auditContextOf($job), 3)
        );
    }

    /**
     * Regression: the claim decrypted the job's credentials before it knew
     * the inventory: a job whose inventory was gone got its token decrypted
     * and sent to the runner, with localhost as its hosts.
     */
    public function testRegressionTheClaimDecryptedCredentialsBeforeItKnewTheInventory(): void
    {
        $credential = $this->storedCredential(Credential::TYPE_TOKEN, ['token' => 'claim-inventory-token-4711']);
        $resolver = $this->spyCredentialResolver();
        $job = $this->launchedJob($this->template, Job::STATUS_QUEUED, null, ['credential_id' => (int)$credential->id]);
        $launchInventoryId = (int)$this->inventory->id;
        $this->repointAndDeleteTheLaunchInventory();

        $payload = $this->claimNext();

        $this->assertSame([], $resolver->resolved, 'no credential was resolved, so none was decrypted');
        $this->assertNull($payload, 'the runner gets no job');
        $job = $this->reloadJob($job);
        $this->assertNull($job->execution_command);
        $this->assertSame([sprintf(JobClaimRefusedException::INVENTORY_MISSING, $launchInventoryId)], $this->logLinesOf($job));
    }

    /**
     * An approved job is checked for its inventory too: the approval comes
     * first, then the inventory.
     */
    public function testAnApprovedJobWhoseInventoryIsGoneIsRefused(): void
    {
        $rule = $this->approvalRule();
        $this->giveTemplateRule($this->template, $rule);
        $job = $this->launchedJob($this->template);
        $this->approvalRequest($job, $rule, ApprovalRequest::STATUS_APPROVED);
        $launchInventoryId = (int)$this->inventory->id;
        $this->repointAndDeleteTheLaunchInventory();

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame([sprintf(JobClaimRefusedException::INVENTORY_MISSING, $launchInventoryId)], $this->logLinesOf($job));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noSourcePathProvider(): array
    {
        return [
            'NULL' => [null],
            'empty' => [''],
            'whitespace' => [" \t "],
        ];
    }

    /**
     * A file inventory without a source path would leave the runner without
     * hosts (no -i): the same fail-open class as a static one without content.
     *
     * @dataProvider noSourcePathProvider
     */
    public function testAFileInventoryWithoutASourcePathIsRefused(?string $sourcePath): void
    {
        Inventory::updateAll(
            ['inventory_type' => Inventory::TYPE_FILE, 'content' => null, 'source_path' => $sourcePath, 'name' => 'from-repo'],
            ['id' => $this->inventory->id]
        );
        $job = $this->launchedJob($this->template);

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame(
            [sprintf(JobClaimRefusedException::INVENTORY_NO_SOURCE, $this->inventory->id, '"from-repo"')],
            $this->logLinesOf($job)
        );
        $this->assertSame(
            ['claim_refusal' => 'inventory_no_source', 'inventory_id' => (int)$this->inventory->id],
            array_slice($this->auditContextOf($job), 3)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noInventoryRecordedProvider(): array
    {
        return [
            'no inventory_id' => [[]],
            'null' => [['inventory_id' => null]],
            'zero' => [['inventory_id' => 0]],
            'a negative number' => [['inventory_id' => -3]],
            'a string that is no number' => [['inventory_id' => 'abc']],
            'a float' => [['inventory_id' => 5.5]],
            'a bool' => [['inventory_id' => true]],
            'a list' => [['inventory_id' => [5]]],
        ];
    }

    /**
     * A job whose launch record names no inventory (DbTestCase jobs, rows
     * written by hand) has unknown hosts.
     *
     * @dataProvider noInventoryRecordedProvider
     * @param array<string, mixed> $recorded
     */
    public function testAJobWhoseLaunchRecordNamesNoInventoryIsRefused(array $recorded): void
    {
        $job = $this->jobWithPayload($this->template, Job::STATUS_QUEUED, $recorded + ['project_id' => (int)$this->template->project_id]);

        $this->assertNull($this->claimNext());

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame([JobClaimRefusedException::INVENTORY_NOT_RECORDED], $this->logLinesOf($job));
        $this->assertSame(
            ['exit_code' => -1, 'status' => Job::STATUS_FAILED, 'reason' => 'pre_execution_failure', 'claim_refusal' => 'inventory_not_recorded'],
            $this->auditContextOf($job)
        );
    }

    public function testAJobWithoutAnyLaunchRecordIsRefused(): void
    {
        $job = $this->createJob((int)$this->template->id, (int)$this->launcher->id);

        $this->assertNull($this->claimNext());

        $this->assertSame([JobClaimRefusedException::INVENTORY_NOT_RECORDED], $this->logLinesOf($this->reloadJob($job)));
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: array{type: string, content: string|null, path: string|null}, 4: list<string>|null}>
     */
    public static function namedHostsProvider(): array
    {
        return [
            'a static inventory with hosts' => [
                Inventory::TYPE_STATIC, "web1\nweb2\n", null,
                ['type' => 'static', 'content' => "web1\nweb2\n", 'path' => null], ['-i', '__INVENTORY_TMP__'],
            ],
            'a static inventory whose hosts are framed by whitespace, as stored' => [
                Inventory::TYPE_STATIC, " \nweb1\n\t", null,
                ['type' => 'static', 'content' => " \nweb1\n\t", 'path' => null], ['-i', '__INVENTORY_TMP__'],
            ],
            'a file inventory with a source path' => [
                Inventory::TYPE_FILE, null, 'inventories/prod.ini',
                ['type' => 'file', 'content' => null, 'path' => 'inventories/prod.ini'], ['-i', 'inventories/prod.ini'],
            ],
            'a dynamic inventory with a source path' => [
                Inventory::TYPE_DYNAMIC, null, 'inventories/aws_ec2.yml',
                ['type' => 'file', 'content' => null, 'path' => 'inventories/aws_ec2.yml'], ['-i', 'inventories/aws_ec2.yml'],
            ],
            'a dynamic inventory without a source path, which its repository names' => [
                Inventory::TYPE_DYNAMIC, null, null,
                ['type' => 'file', 'content' => null, 'path' => null], null,
            ],
        ];
    }

    /**
     * An inventory that names its hosts runs as before.
     *
     * @dataProvider namedHostsProvider
     * @param array{type: string, content: string|null, path: string|null} $expected
     * @param list<string>|null $inventoryArgs the -i arguments of the command; null for none
     */
    public function testAnInventoryThatNamesItsHostsRuns(string $type, ?string $content, ?string $sourcePath, array $expected, ?array $inventoryArgs): void
    {
        Inventory::updateAll(['inventory_type' => $type, 'content' => $content, 'source_path' => $sourcePath], ['id' => $this->inventory->id]);
        $job = $this->launchedJob($this->template);

        $payload = $this->claimNext();

        $this->assertNotNull($payload);
        $this->assertSame((int)$job->id, $payload['job_id']);
        $this->assertSame(
            $expected,
            ['type' => $payload['inventory_type'], 'content' => $payload['inventory_content'], 'path' => $payload['inventory_path']]
        );
        $command = $payload['command'];
        $this->assertIsArray($command);
        $flag = array_search('-i', $command, true);
        $this->assertSame($inventoryArgs, $flag === false ? null : [$command[$flag], $command[$flag + 1]]);
    }

    /**
     * The launch inventory is gone; the template uses another one now, so
     * the deleted inventory is no longer in use.
     */
    private function repointAndDeleteTheLaunchInventory(): void
    {
        $other = $this->createInventory((int)$this->launcher->id);
        JobTemplate::updateAll(['inventory_id' => $other->id], ['id' => $this->template->id]);
        $this->assertSame(1, $this->inventory->delete());
    }
}
