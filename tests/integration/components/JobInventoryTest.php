<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\JobInventory;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * JobInventory against the database: the inventory a job was launched
 * with, named as its payload recorded it, else by its current row; whether
 * the row still exists; one query for the jobs of a page, whatever their
 * number.
 */
class JobInventoryTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('job_inventory')->id;
    }

    /**
     * Renamed after the launch: the job keeps the name it was launched with.
     */
    public function testTheNameAtLaunchOutlivesARename(): void
    {
        $inventory = $this->inventory('web-hosts');
        $job = $this->jobWithPayload([
            'inventory_id' => (int)$inventory->id,
            Job::PAYLOAD_INVENTORY_NAME => 'web-hosts',
        ]);
        $inventory->name = 'web-hosts-renamed';
        $inventory->save(false);

        $launched = JobInventory::ofJob($job);

        $this->assertSame((int)$inventory->id, $launched->id);
        $this->assertSame('web-hosts', $launched->name);
        $this->assertTrue($launched->exists);
        $this->assertSame('web-hosts', $launched->label());
        $this->assertSame(['inventory_id' => (int)$inventory->id, 'inventory_name' => 'web-hosts'], $launched->apiFields());
    }

    /**
     * A job launched before 2.12.0 recorded no name: the current row names it.
     */
    public function testAPayloadFromBefore212IsNamedByTheCurrentRow(): void
    {
        $inventory = $this->inventory('db-hosts');
        $job = $this->jobWithPayload(['inventory_id' => (int)$inventory->id, 'template_name' => 'Deploy']);

        $launched = JobInventory::ofJob($job);

        $this->assertSame('db-hosts', $launched->name);
        $this->assertTrue($launched->exists);
    }

    public function testADeletedInventoryKeepsItsNameAtLaunchOrIsNamedByItsId(): void
    {
        $named = $this->inventory('gone-hosts');
        $unnamed = $this->inventory('gone-too');
        $namedId = (int)$named->id;
        $unnamedId = (int)$unnamed->id;
        $named->delete();
        $unnamed->delete();

        $inventories = JobInventory::ofPayloads([
            1 => ['inventory_id' => $namedId, Job::PAYLOAD_INVENTORY_NAME => 'gone-hosts'],
            2 => ['inventory_id' => $unnamedId],
        ]);

        $this->assertSame('gone-hosts', $inventories[1]->label());
        $this->assertFalse($inventories[1]->exists);
        $this->assertNull($inventories[2]->name);
        $this->assertFalse($inventories[2]->exists);
        $this->assertSame("Inventory #{$unnamedId}", $inventories[2]->label());
        $this->assertSame(['inventory_id' => $unnamedId, 'inventory_name' => null], $inventories[2]->apiFields());
    }

    /**
     * The placeholder of a workflow approval step has no job template and
     * no runner payload: no inventory, and no query.
     */
    public function testAPlaceholderHasNoInventory(): void
    {
        $placeholder = new Job();
        $placeholder->job_template_id = null;
        $placeholder->launched_by = $this->userId;
        $placeholder->status = Job::STATUS_PENDING_APPROVAL;
        $placeholder->timeout_minutes = 0;
        $placeholder->has_changes = 0;
        $placeholder->created_at = time();
        $placeholder->updated_at = time();
        $placeholder->save(false);
        $loaded = Job::findOne($placeholder->id);
        $this->assertInstanceOf(Job::class, $loaded);

        $inventory = null;
        $statements = $this->statementsOf(static function () use ($loaded, &$inventory): void {
            $inventory = JobInventory::ofJob($loaded);
        });

        $this->assertSame([], $statements);
        $this->assertInstanceOf(JobInventory::class, $inventory);
        $this->assertNull($inventory->id);
        $this->assertSame(JobInventory::NONE, $inventory->label());
    }

    public function testOfJobReadsOneRow(): void
    {
        $inventory = $this->inventory('one-row');
        $job = $this->jobWithPayload(['inventory_id' => (string)$inventory->id]);

        $statements = $this->statementsOf(static function () use ($job): void {
            JobInventory::ofJob($job);
        });

        $this->assertSame(["SELECT `id`, `name` FROM `inventory` WHERE `id`={$inventory->id}"], $statements);
    }

    /**
     * One query for the jobs of a page, whatever their number, also when
     * several jobs ran on one inventory.
     */
    public function testTwoOrTwelveJobsCostOneQuery(): void
    {
        $inventories = [];
        for ($i = 0; $i < 6; $i++) {
            $inventories[] = $this->inventory('page-' . $i);
        }
        $two = [101 => $this->payloadOf($inventories[0]), 102 => $this->payloadOf($inventories[1])];
        $twelve = [];
        for ($i = 0; $i < 12; $i++) {
            $twelve[200 + $i] = $this->payloadOf($inventories[$i % 6]);
        }
        $twelve[300] = [];

        $results = [];
        $forTwo = $this->statementsOf(static function () use ($two, &$results): void {
            $results['two'] = JobInventory::ofPayloads($two);
        });
        $forTwelve = $this->statementsOf(static function () use ($twelve, &$results): void {
            $results['twelve'] = JobInventory::ofPayloads($twelve);
        });

        $this->assertCount(1, $forTwo, implode("\n", $forTwo));
        $this->assertCount(1, $forTwelve, implode("\n", $forTwelve));
        $this->assertSame([101, 102], array_keys($results['two']));
        $this->assertSame(array_keys($twelve), array_keys($results['twelve']));
        foreach (range(0, 11) as $i) {
            $this->assertSame((int)$inventories[$i % 6]->id, $results['twelve'][200 + $i]->id);
            $this->assertSame('page-' . ($i % 6), $results['twelve'][200 + $i]->name);
            $this->assertTrue($results['twelve'][200 + $i]->exists);
        }
        $this->assertEquals(JobInventory::none(), $results['twelve'][300]);
    }

    /**
     * The job's payload names the inventory it was launched with, not the
     * one its template uses now.
     */
    public function testTheInventoryAtLaunchIsNotTheTemplatesCurrentOne(): void
    {
        $atLaunch = $this->inventory('at-launch');
        $now = $this->inventory('now');
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$atLaunch->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $job = $this->createJob((int)$template->id, $this->userId);
        $job->runner_payload = (string)json_encode(['inventory_id' => (int)$atLaunch->id, Job::PAYLOAD_INVENTORY_NAME => 'at-launch']);
        $job->save(false);
        JobTemplate::updateAll(['inventory_id' => (int)$now->id], ['id' => $template->id]);

        $this->assertSame((int)$atLaunch->id, JobInventory::ofJob($job)->id);
    }

    // -- Helpers ----------------------------------------------------------------

    private function inventory(string $name): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->name = $name;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(Inventory $inventory): array
    {
        return ['inventory_id' => (int)$inventory->id];
    }

    /**
     * A queued job with $payload as its runner payload, on a template of
     * its own.
     *
     * @param array<string, mixed> $payload
     */
    private function jobWithPayload(array $payload): Job
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $job = $this->createJob((int)$template->id, $this->userId);
        $job->runner_payload = (string)json_encode($payload);
        $job->save(false);

        return $job;
    }

    /**
     * The SQL statements that $action sends, as logged (values filled in).
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }
}
