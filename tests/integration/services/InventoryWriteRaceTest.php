<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\DbErrorSummary;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Project;
use app\services\InventoryRuleAssignment;
use app\services\InventoryWriteService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Exception as DbException;

/**
 * Regression (2.12.0 review, LOCKS-1), with two processes: an approval rule
 * or a project that another process deletes while an inventory save names
 * it.
 *
 * The other process deletes the row through the application's own delete
 * (ApprovalRuleWriteService::delete(), ProjectDeletionService::delete()) and
 * holds the row's lock for PAUSE_MS before it commits: the rule delete locks
 * the rule's row FOR UPDATE first, the project delete with its DELETE. The
 * save's foreign key check waits for that lock and, once the delete has
 * committed, finds the row gone: the database refuses the insert or update
 * with 23000/1452, no lock conflict, which ended the save in a server error.
 * The save now makes its attempt once more, and the validation of that
 * attempt names the row that is gone; nothing is written.
 *
 * The rows are committed, so that the other process sees them: these tests
 * run without DbTestCase's transaction, and tearDown() deletes what they
 * wrote. The other process works on the same test database
 * (tests/bootstrap.php, config/test.php).
 */
class InventoryWriteRaceTest extends DbTestCase
{
    use TeamScopeFixtures;

    /** How long the other process holds the row's lock before it commits, in milliseconds. */
    private const PAUSE_MS = 1000;

    /** How long the test waits for the other process to hold the lock, in seconds. */
    private const READY_TIMEOUT = 60;

    /**
     * The other process. Arguments: the repository root, what it deletes
     * (rule or project), the row's ID and the pause. It prints "locked" while
     * it holds the row's lock, then "deleted", or "kept" when the delete was
     * refused.
     */
    private const PEER = <<<'PHP'
        <?php

        declare(strict_types=1);

        [, $root, $kind, $id, $pauseMs] = $argv;
        require $root . '/vendor/autoload.php';
        require $root . '/tests/bootstrap.php';
        $pause = static function () use ($pauseMs): void {
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep((int)$pauseMs * 1000);
        };
        if ($kind === 'rule') {
            \yii\base\Event::on(\app\models\ApprovalRule::class, \yii\db\BaseActiveRecord::EVENT_BEFORE_DELETE, $pause);
            $rule = \app\models\ApprovalRule::findOne((int)$id);
            $deleted = \Yii::$app->get('approvalRuleWriteService')->delete($rule, null, 'api')['deleted'];
        } else {
            \yii\base\Event::on(\app\models\Project::class, \yii\db\BaseActiveRecord::EVENT_AFTER_DELETE, $pause);
            $project = \app\models\Project::findOne((int)$id);
            $deleted = \Yii::$app->get('projectDeletionService')->delete($project) === null;
        }
        fwrite(STDOUT, ($deleted ? 'deleted' : 'kept') . "\n");

        PHP;

    /** @var array{user: int|null, rule: int|null, project: int|null, inventory: int|null} the committed rows */
    private array $rows = ['user' => null, 'rule' => null, 'project' => null, 'inventory' => null];

    /** The name of the inventory a create makes, deleted in tearDown() when the test fails after it. */
    private string $inventoryName = '';

    private string $peerFile = '';

    /**
     * No transaction: the other process must see the rows (DbTestCase begins
     * one, which keeps them from it).
     */
    protected function setUp(): void
    {
        $this->assertNull(\Yii::$app->db->getTransaction(), 'An earlier test left a transaction open: these rows would not be committed.');
        $this->inventoryName = 'inv-race-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $db = \Yii::$app->db;
        Inventory::deleteAll(['or', ['id' => $this->rows['inventory']], ['name' => $this->inventoryName]]);
        ApprovalRule::deleteAll(['id' => $this->rows['rule']]);
        Project::deleteAll(['id' => $this->rows['project']]);
        $db->createCommand()->delete('{{%audit_log}}', ['or',
            ['object_type' => 'approval_rule', 'object_id' => $this->rows['rule']],
            ['object_type' => 'project', 'object_id' => $this->rows['project']],
            ['object_type' => 'inventory', 'object_id' => $this->rows['inventory']],
            ['user_id' => $this->rows['user']],
        ])->execute();
        if ($this->rows['user'] !== null) {
            \Yii::$app->authManager?->revokeAll((string)$this->rows['user']);
            $db->createCommand()->delete('{{%user}}', ['id' => $this->rows['user']])->execute();
        }
        if ($this->peerFile !== '' && file_exists($this->peerFile)) {
            unlink($this->peerFile);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}> the write, and the row it names that the other process deletes
     */
    public static function raceProvider(): array
    {
        return [
            'an update gives the inventory a rule an admin deletes' => ['update', 'rule'],
            'a create with a rule an admin deletes' => ['create', 'rule'],
            'an update moves the inventory into a project being deleted' => ['update', 'project'],
            'a create in a project being deleted' => ['create', 'project'],
        ];
    }

    /**
     * @dataProvider raceProvider
     */
    public function testRegressionARowDeletedWhileTheSaveWaitedEndedInAForeignKeyError(string $write, string $reference): void
    {
        $userId = (int)$this->createUserWithRole('inv-race-admin', 'admin')->id;
        $this->rows['user'] = $userId;
        $this->rows['rule'] = (int)$this->createApprovalRule($userId)->id;
        $this->rows['project'] = (int)$this->createProject($userId)->id;
        $inventory = $this->inventory($write, $userId);
        $stored = $write === 'update' ? $this->storedRow((int)$inventory->id) : null;
        $submitted = [];
        if ($reference === 'rule') {
            $submitted[InventoryRuleAssignment::FIELD] = $this->rows['rule'];
        } else {
            $inventory->project_id = $this->rows['project'];
        }
        $writer = new InventoryWriteService();

        $peer = $this->startPeer($reference, (int)$this->rows[$reference]);
        $started = microtime(true);
        try {
            $saved = $write === 'create'
                ? $writer->create($inventory, $submitted, $userId, InventoryWriteService::SOURCE_API)
                : $writer->update($inventory, $submitted, $userId, InventoryWriteService::SOURCE_API);
        } catch (DbException $e) {
            $saved = DbErrorSummary::of($e);
        }
        $waited = microtime(true) - $started;
        $said = $this->finishPeer($peer);

        $this->assertSame(false, $saved, 'not saved, with the validation error, not the foreign key\'s refusal');
        $this->assertSame('deleted', $said, 'the other process deleted the row');
        $this->assertGreaterThan(0.3, $waited, 'the save waited for the lock of the delete');
        $this->assertSame(
            $reference === 'rule'
                ? [InventoryRuleAssignment::FIELD => [Inventory::APPROVAL_RULE_NOT_FOUND]]
                : ['project_id' => ['The selected project does not exist.']],
            $inventory->getErrors()
        );
        if ($stored === null) {
            $this->assertFalse(Inventory::find()->where(['name' => $this->inventoryName])->exists(), 'no inventory');
            return;
        }
        $this->assertSame($stored, $this->storedRow((int)$inventory->id), 'nothing written');
    }

    /**
     * The inventory of $write: a stored static inventory for an update, a
     * new one for a create; both without a rule or a project.
     */
    private function inventory(string $write, int $userId): Inventory
    {
        if ($write === 'create') {
            $inventory = new Inventory();
            $inventory->name = $this->inventoryName;
            $inventory->inventory_type = Inventory::TYPE_STATIC;
            $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";

            return $inventory;
        }
        $created = $this->createInventory($userId);
        $created->name = $this->inventoryName;
        $created->content = "all:\n  hosts:\n    web1.example.com:\n";
        $created->save(false);
        $this->rows['inventory'] = (int)$created->id;
        $inventory = Inventory::findOne($created->id);
        $this->assertInstanceOf(Inventory::class, $inventory);

        return $inventory;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        $row = (new \yii\db\Query())->from(Inventory::tableName())->where(['id' => $id])->one();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * Starts the other process, which deletes the $kind row #$id, and
     * returns once it holds the row's lock.
     *
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function startPeer(string $kind, int $id): array
    {
        $file = tempnam(sys_get_temp_dir(), 'inv-race-peer-');
        $this->assertIsString($file);
        $this->peerFile = $file;
        file_put_contents($file, self::PEER);
        $process = proc_open(
            [PHP_BINARY, $file, dirname(__DIR__, 3), $kind, (string)$id, (string)self::PAUSE_MS],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'the other process starts');
        fclose($pipes[0]);
        $peer = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        $read = [$peer['stdout']];
        $none = [];
        $ready = stream_select($read, $none, $none, self::READY_TIMEOUT);
        $line = $ready === 1 ? fgets($peer['stdout']) : false;
        if ($line !== "locked\n") {
            $this->fail('The other process did not lock the row: ' . (string)json_encode($line) . ' ' . $this->finishPeer($peer));
        }

        return $peer;
    }

    /**
     * Waits for the other process to end: what it printed last ("deleted" or
     * "kept"); with its errors when it failed.
     *
     * @param array{process: resource, stdout: resource, stderr: resource} $peer
     */
    private function finishPeer(array $peer): string
    {
        $out = (string)stream_get_contents($peer['stdout']);
        $errors = (string)stream_get_contents($peer['stderr']);
        fclose($peer['stdout']);
        fclose($peer['stderr']);
        $exit = proc_close($peer['process']);

        return $exit === 0 ? trim($out) : sprintf('exit %d: %s %s', $exit, trim($out), trim($errors));
    }
}
