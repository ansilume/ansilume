<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobClaimGate;
use app\services\JobClaimRefusedException;
use app\tests\integration\DbTestCase;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * The claim gate (JobClaimGate::check()) reads the job template, the
 * inventories and the approval request from one snapshot: one transaction at
 * REPEATABLE READ, without locks, whatever the server's default level. An
 * admin who moves a rule in two commits, each of which keeps the job gated,
 * cannot make the gate miss the rule, also when both commits land between
 * two of its reads.
 *
 * Other connections must see the fixtures, so this test commits them,
 * through a connection of its own outside the transaction of DbTestCase, and
 * deletes them in tearDown(). The gate runs with the application's db
 * component on a third connection, whose commands call back after each
 * statement (AfterStatementCommand): right after the gate's first read, the
 * template row, the admin's changes commit from the fixtures' connection,
 * each on its own.
 */
class JobClaimGateSnapshotTest extends DbTestCase
{
    /** The gate's first read, the template row, as sent. */
    private const TEMPLATE_READ = 'SELECT `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = %d';

    /** The gate's read of the inventory rows, as sent (JobTemplateGate::readInventoryRules()). */
    private const INVENTORY_READ = 'SELECT * FROM `inventory` WHERE `id` IN (%s) ORDER BY `id`';

    /** The gate's read of the approval request, as sent. */
    private const REQUEST_READ = 'SELECT `id`, `status` FROM `approval_request` WHERE `job_id` = %d';

    /** The gate's log entry for the admin's changes, which commit right after its first read. */
    private const ADMIN_COMMITTED = '(another connection committed %d change(s))';

    /**
     * The tables of the committed fixtures, in an order in which tearDown()
     * can delete their rows whatever the changes pointed where.
     */
    private const DELETE_ORDER = [
        '{{%approval_request}}',
        '{{%job}}',
        '{{%job_template}}',
        '{{%inventory}}',
        '{{%runner_group}}',
        '{{%project}}',
        '{{%approval_rule}}',
        '{{%user}}',
    ];

    /** @var array<string, list<int>> the IDs of the committed rows, by table */
    private array $rows = [];

    /** @var list<Connection> the connections of the test, closed in tearDown() */
    private array $connections = [];

    private ?Connection $committing = null;

    protected function tearDown(): void
    {
        try {
            $committing = $this->committing;
            foreach (self::DELETE_ORDER as $table) {
                if (isset($this->rows[$table])) {
                    $committing?->createCommand()->delete($table, ['id' => $this->rows[$table]])->execute();
                }
            }
        } finally {
            $this->rows = [];
            foreach ($this->connections as $connection) {
                $connection->close();
            }
            $this->connections = [];
            $this->committing = null;
            parent::tearDown();
        }
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function sessionLevelProvider(): array
    {
        return [
            'the server\'s default level (REPEATABLE READ)' => [null],
            'a server set to READ COMMITTED' => ['READ COMMITTED'],
        ];
    }

    /**
     * Regression (2.12.0 review, LOCKS-3): the gate read the template row,
     * the inventory rows and the request in statements of their own, each
     * with a snapshot of its own. An admin gave the job template its
     * inventory's rule, then removed the rule from the inventory: both
     * commits kept the template gated, but when they landed between the
     * template read and the inventory read, the gate saw neither rule, and
     * a job queued before the rule, never approved, ran.
     *
     * @dataProvider sessionLevelProvider
     */
    public function testRegressionARuleMovedFromTheInventoryToTheTemplateBetweenTheReadsLetAnUnapprovedJobRun(?string $sessionLevel): void
    {
        $fixtures = $this->unapprovedJobOfAGatedInventory();
        $templateId = $fixtures['template'];
        $inventoryId = $fixtures['inventory'];
        $ruleId = $fixtures['rule'];

        [$refusal, $log] = $this->gateWithChangesAfterItsFirstRead($fixtures['job'], [
            ['{{%job_template}}', ['approval_rule_id' => $ruleId], $templateId],
            ['{{%inventory}}', ['approval_rule_id' => null], $inventoryId],
        ], $sessionLevel);

        $this->assertNotNull($refusal, 'the unapproved job does not run');
        $this->assertSame(
            ['claim_refusal' => JobClaimRefusedException::REASON_NOT_APPROVED, 'approval_rule_id' => $ruleId, 'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY, 'job_template_id' => $templateId, 'inventory_id' => $inventoryId],
            $refusal->auditContext(),
            'the rule as the snapshot of the first read has it: the inventory\'s'
        );
        $this->assertTheChangesCommittedBetweenTheReads($log, $templateId, [$inventoryId], 2);
        $this->assertSame($ruleId, $this->storedRule('{{%job_template}}', $templateId), 'the template has the rule now');
        $this->assertNull($this->storedRule('{{%inventory}}', $inventoryId), 'the inventory has none');
    }

    /**
     * Regression (2.12.0 review, LOCKS-3), the same class: an admin pointed
     * the job template at another inventory with the same rule, then removed
     * the rule from the inventory the job was launched with. Read in
     * statements of their own, the template row named the old inventory and
     * the inventory row had no rule any more, and the job ran.
     *
     * @dataProvider sessionLevelProvider
     */
    public function testRegressionARuleMovedToAnotherInventoryBetweenTheReadsLetAnUnapprovedJobRun(?string $sessionLevel): void
    {
        $fixtures = $this->unapprovedJobOfAGatedInventory();
        $templateId = $fixtures['template'];
        $launchedWith = $fixtures['inventory'];
        $ruleId = $fixtures['rule'];
        $other = $this->committed(fn (): int => $this->gatedInventory($fixtures['user'], $ruleId));

        [$refusal, $log] = $this->gateWithChangesAfterItsFirstRead($fixtures['job'], [
            ['{{%job_template}}', ['inventory_id' => $other], $templateId],
            ['{{%inventory}}', ['approval_rule_id' => null], $launchedWith],
        ], $sessionLevel);

        $this->assertNotNull($refusal, 'the unapproved job does not run');
        $this->assertSame(
            ['claim_refusal' => JobClaimRefusedException::REASON_NOT_APPROVED, 'approval_rule_id' => $ruleId, 'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY, 'job_template_id' => $templateId, 'inventory_id' => $launchedWith],
            $refusal->auditContext()
        );
        $this->assertTheChangesCommittedBetweenTheReads($log, $templateId, [$launchedWith], 2);
        $this->assertNull($this->storedRule('{{%inventory}}', $launchedWith), 'the launch inventory has no rule now');
    }

    /**
     * The gate's statements as the server gets them, outside any other
     * transaction: the level for this one transaction, then the three plain
     * reads (no FOR UPDATE, no LOCK IN SHARE MODE) between its BEGIN and
     * COMMIT. The transaction has ended before a refusal is thrown, and
     * after a job that may run.
     */
    public function testTheGateReadsInOneRepeatableReadTransactionWithoutLocks(): void
    {
        $fixtures = $this->unapprovedJobOfAGatedInventory();
        $expected = [
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'BEGIN',
            sprintf(self::TEMPLATE_READ, $fixtures['template']),
            sprintf(self::INVENTORY_READ, $fixtures['inventory']),
            sprintf(self::REQUEST_READ, $fixtures['job']->id),
            'COMMIT',
        ];

        [$refusal, $log, $openAfter] = $this->gateWithChangesAfterItsFirstRead($fixtures['job'], [], null);

        $this->assertNotNull($refusal, 'never approved');
        $this->assertSame($expected, $log);
        $this->assertFalse($openAfter, 'no transaction is left open after a refusal');

        $this->committed(fn (): int => $this->approvedRequest($fixtures['job'], $fixtures['rule']));

        [$refusal, $log, $openAfter] = $this->gateWithChangesAfterItsFirstRead($fixtures['job'], [], null);

        $this->assertNull($refusal, 'approved');
        $this->assertSame($expected, $log);
        $this->assertFalse($openAfter, 'no transaction is left open after a job that may run');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A queued job, never approved, of a job template without a rule of its
     * own on an inventory with a rule, all committed: the job was queued
     * before the inventory got its rule, and has no approval request.
     *
     * @return array{user: int, rule: int, inventory: int, template: int, job: Job}
     */
    private function unapprovedJobOfAGatedInventory(): array
    {
        return $this->committed(function (): array {
            $user = $this->kept($this->createUser('snapshot'));
            $rule = $this->kept($this->createApprovalRule($user));
            $project = $this->kept($this->createProject($user));
            $group = $this->kept($this->createRunnerGroup($user));
            $inventory = $this->gatedInventory($user, $rule);
            $template = $this->kept($this->createJobTemplate($project, $inventory, $group, $user));
            $job = $this->createJob($template, $user);
            $job->runner_payload = (string)json_encode([
                'template_id' => $template,
                'project_id' => $project,
                'inventory_id' => $inventory,
                Job::PAYLOAD_INVENTORY_NAME => (string)Inventory::findOne($inventory)?->name,
                'playbook' => 'site.yml',
            ]);
            $job->save(false);
            $this->kept($job);

            return ['user' => $user, 'rule' => $rule, 'inventory' => $inventory, 'template' => $template, 'job' => $job];
        });
    }

    /**
     * A static inventory with rule $ruleId, committed when called inside
     * committed(); its ID.
     */
    private function gatedInventory(int $userId, int $ruleId): int
    {
        $inventory = $this->createInventory($userId);
        Inventory::updateAll(['approval_rule_id' => $ruleId], ['id' => $inventory->id]);

        return $this->kept($inventory);
    }

    /**
     * The approved approval request of $job under rule $ruleId, written as an
     * approval leaves it; its ID.
     */
    private function approvedRequest(Job $job, int $ruleId): int
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $ruleId;
        $request->status = ApprovalRequest::STATUS_APPROVED;
        $request->requested_at = time() - 600;
        $request->resolved_at = time() - 60;
        $request->recordRequiredBy(ApprovalRequest::REQUIRED_BY_INVENTORY);
        $request->save(false);

        return $this->kept($request);
    }

    /**
     * The gate on $job, with the application's db component on a connection
     * of its own (whose session defaults to $sessionLevel when given): right
     * after the gate's first read, the template row, $changes ([table,
     * columns, ID]) commit from the fixtures' connection, each on its own.
     * What the gate refused (null: the job may run), the gate's statements
     * and transaction events in order, with ADMIN_COMMITTED where the
     * changes committed, and whether a transaction was left open.
     *
     * @param list<array{0: string, 1: array<string, int|null>, 2: int}> $changes
     * @return array{0: JobClaimRefusedException|null, 1: list<string>, 2: bool}
     */
    private function gateWithChangesAfterItsFirstRead(Job $job, array $changes, ?string $sessionLevel): array
    {
        $committing = $this->committingConnection();
        $firstRead = sprintf(self::TEMPLATE_READ, $job->job_template_id);
        $log = [];
        $recording = false;
        $hook = function (string $sql) use (&$log, &$recording, $firstRead, $changes, $committing): void {
            if (!$recording) {
                return;
            }
            $log[] = $sql;
            if ($sql === $firstRead && $changes !== []) {
                foreach ($changes as [$table, $columns, $id]) {
                    $committing->createCommand()->update($table, $columns, ['id' => $id])->execute();
                }
                $log[] = sprintf(self::ADMIN_COMMITTED, count($changes));
            }
        };
        $gateDb = $this->connection(['commandMap' => ['mysql' => ['class' => AfterStatementCommand::class, 'afterStatement' => $hook]]]);
        foreach (['BEGIN' => Connection::EVENT_BEGIN_TRANSACTION, 'COMMIT' => Connection::EVENT_COMMIT_TRANSACTION, 'ROLLBACK' => Connection::EVENT_ROLLBACK_TRANSACTION] as $entry => $event) {
            $gateDb->on($event, function () use (&$log, &$recording, $entry): void {
                if ($recording) {
                    $log[] = $entry;
                }
            });
        }
        if ($sessionLevel !== null) {
            $gateDb->createCommand('SET SESSION TRANSACTION ISOLATION LEVEL ' . $sessionLevel)->execute();
        }
        $raw = $job->decodedRunnerPayload();

        $original = \Yii::$app->db;
        \Yii::$app->set('db', $gateDb);
        $recording = true;
        try {
            (new JobClaimGate())->check($job, $raw);
            $refusal = null;
        } catch (JobClaimRefusedException $e) {
            $refusal = $e;
        } finally {
            $recording = false;
            \Yii::$app->set('db', $original);
        }

        return [$refusal, $log, $gateDb->getTransaction() !== null];
    }

    /**
     * The changes committed right after the gate's template read, and the
     * gate read $inventoryIds after them.
     *
     * @param list<string> $log
     * @param list<int> $inventoryIds
     */
    private function assertTheChangesCommittedBetweenTheReads(array $log, int $templateId, array $inventoryIds, int $changes): void
    {
        $templateRead = array_search(sprintf(self::TEMPLATE_READ, $templateId), $log, true);
        $committed = array_search(sprintf(self::ADMIN_COMMITTED, $changes), $log, true);
        $inventoryRead = array_search(sprintf(self::INVENTORY_READ, implode(', ', $inventoryIds)), $log, true);
        $this->assertIsInt($templateRead, "the gate read the template row:\n" . implode("\n", $log));
        $this->assertSame($templateRead + 1, $committed, "the changes committed right after it:\n" . implode("\n", $log));
        $this->assertGreaterThan($committed, $inventoryRead, "the gate read the inventory rows after them:\n" . implode("\n", $log));
    }

    /**
     * The approval rule of a row as committed, read on the fixtures' connection.
     */
    private function storedRule(string $table, int $id): ?int
    {
        $value = $this->committingConnection()->createCommand(
            'SELECT [[approval_rule_id]] FROM ' . $table . ' WHERE [[id]] = :id',
            [':id' => $id]
        )->queryScalar();
        $this->assertNotFalse($value, "row #{$id} of {$table}");

        return $value === null ? null : (int)$value;
    }

    /**
     * Runs $create with the application's db component on the fixtures'
     * connection, outside the transaction of DbTestCase, so that every row it
     * writes commits; kept() rows are deleted in tearDown().
     *
     * @template T
     * @param callable(): T $create
     * @return T
     */
    private function committed(callable $create): mixed
    {
        $original = \Yii::$app->db;
        \Yii::$app->set('db', $this->committingConnection());
        try {
            return $create();
        } finally {
            \Yii::$app->set('db', $original);
        }
    }

    /**
     * Notes $record for the deletes of tearDown(); its ID.
     */
    private function kept(ActiveRecord $record): int
    {
        $table = $record::tableName();
        $this->assertContains($table, self::DELETE_ORDER, 'tearDown() deletes the rows of this table');
        $id = (int)$record->getPrimaryKey();
        $this->rows[$table][] = $id;

        return $id;
    }

    private function committingConnection(): Connection
    {
        return $this->committing ??= $this->connection();
    }

    /**
     * A new connection to the test database, as the application's db
     * component connects, plus $config.
     *
     * @param array<string, mixed> $config
     */
    private function connection(array $config = []): Connection
    {
        $main = \Yii::$app->db;
        $connection = new Connection($config + [
            'dsn' => $main->dsn,
            'username' => $main->username,
            'password' => $main->password,
            'charset' => $main->charset,
            'tablePrefix' => $main->tablePrefix,
        ]);
        $this->connections[] = $connection;

        return $connection;
    }
}
