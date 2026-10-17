<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\services\JobTemplateGate;
use app\tests\integration\DbTestCase;
use yii\db\Connection;
use yii\db\Query;
use yii\log\Logger;

/**
 * JobTemplateGate against the database: the SQL condition (joinInventory(),
 * gatedCondition()) calls exactly the templates gated that of() calls gated,
 * and readInventoryRules(), the one raw reader of inventory rows, reads and
 * share-locks them in one statement in ascending ID order, also on a schema
 * from before m000088.
 *
 * The schema before m000088 lives in the scratch database
 * ansilume_migrate_test, under a table prefix of this test (other migration
 * tests use the same database); without the grant of bin/tests-phpunit.sh
 * that test is skipped.
 */
class JobTemplateGateSqlTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    private int $userId;
    private int $projectId;
    private int $runnerGroupId;
    private ?Connection $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('gate_sql')->id;
        $this->projectId = (int)$this->createProject($this->userId)->id;
        $this->runnerGroupId = (int)$this->createRunnerGroup($this->userId)->id;
    }

    protected function tearDown(): void
    {
        $scratch = $this->scratch;
        if ($scratch !== null) {
            $scratch->createCommand('DROP TABLE IF EXISTS {{%inventory}}')->execute();
            $scratch->close();
            $this->scratch = null;
        }
        parent::tearDown();
    }

    /**
     * Every combination: no rule, the template's own, the inventory's, the
     * same on both, different rules, and a template whose inventory row is
     * gone, with and without a rule of its own.
     */
    public function testTheSqlConditionCallsGatedWhatTheGateCallsGated(): void
    {
        $first = $this->createApprovalRule($this->userId);
        $second = $this->createApprovalRule($this->userId);
        $plain = $this->inventory(null);
        $gatedByFirst = $this->inventory((int)$first->id);
        $templates = [
            'none' => $this->template($plain, null),
            'own rule' => $this->template($plain, (int)$first->id),
            'inventory rule' => $this->template($gatedByFirst, null),
            'same rule' => $this->template($gatedByFirst, (int)$first->id),
            'different rules' => $this->template($gatedByFirst, (int)$second->id),
        ];
        $gone = $this->inventory(null);
        $templates['inventory gone'] = $this->template($gone, null);
        $templates['inventory gone, own rule'] = $this->template($gone, (int)$second->id);
        $this->deleteIgnoringForeignKeys($gone);
        $ids = array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates);

        $byGate = [];
        foreach (JobTemplate::find()->with(JobTemplateGate::RELATIONS)->where(['id' => $ids])->all() as $template) {
            /** @var JobTemplate $template */
            if (JobTemplateGate::of($template)->isGated()) {
                $byGate[] = (int)$template->id;
            }
        }
        sort($byGate);

        $this->assertSame($byGate, $this->gatedBySql($ids, 't'));
        $this->assertSame($byGate, $this->gatedBySql($ids, 'job_template', 'inv'));
        $this->assertSame(
            [$ids['own rule'], $ids['inventory rule'], $ids['same rule'], $ids['different rules'], $ids['inventory gone, own rule']],
            $byGate
        );
    }

    /**
     * Eager-loaded with RELATIONS, of() reads nothing more.
     */
    public function testOfReadsEagerLoadedRelationsWithoutAQuery(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $id = (int)$this->template($this->inventory((int)$rule->id), (int)$rule->id)->id;
        $template = JobTemplate::find()->with(JobTemplateGate::RELATIONS)->where(['id' => $id])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        $gate = null;
        $statements = $this->statementsOf(function () use ($template, &$gate): void {
            $gate = JobTemplateGate::of($template);
        });

        $this->assertSame([], $statements);
        $this->assertInstanceOf(JobTemplateGate::class, $gate);
        $this->assertSame((int)$rule->id, $gate->rule?->id);
        $this->assertSame((int)$rule->id, $gate->inventoryRule?->id);
    }

    /**
     * Lazily, of() reads a rule only where there is a rule ID.
     */
    public function testOfLoadsARuleOnlyWhereThereIsARuleId(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $ungated = JobTemplate::findOne((int)$this->template($this->inventory(null), null)->id);
        $gated = JobTemplate::findOne((int)$this->template($this->inventory((int)$rule->id), null)->id);
        $this->assertInstanceOf(JobTemplate::class, $ungated);
        $this->assertInstanceOf(JobTemplate::class, $gated);

        $statements = $this->statementsOf(static function () use ($ungated): void {
            JobTemplateGate::of($ungated);
        });
        $this->assertCount(1, $statements, 'the inventory only: ' . implode("\n", $statements));
        $this->assertMatchesRegularExpression('~^SELECT \* FROM `inventory` WHERE `id`=\d+$~', $statements[0]);

        $statements = $this->statementsOf(static function () use ($gated): void {
            JobTemplateGate::of($gated);
        });
        $this->assertCount(2, $statements, 'the inventory and its rule: ' . implode("\n", $statements));
        $this->assertSame((int)$rule->id, JobTemplateGate::of($gated)->rule?->id);
    }

    public function testReadInventoryRulesReadsTheRulesOfTheRowsFound(): void
    {
        $rule = $this->createApprovalRule($this->userId);
        $gated = (int)$this->inventory((int)$rule->id)->id;
        $plain = (int)$this->inventory(null)->id;
        $missing = $this->idThatDoesNotExist();

        $rules = JobTemplateGate::readInventoryRules([$gated, $plain, $missing], false);

        $this->assertSame([min($gated, $plain), max($gated, $plain)], array_keys($rules), 'found rows, in ascending ID order');
        $this->assertSame((int)$rule->id, $rules[$gated]);
        $this->assertTrue(array_key_exists($plain, $rules), 'found');
        $this->assertNull($rules[$plain]);
        $this->assertFalse(array_key_exists($missing, $rules), 'a row that does not exist has no key');
    }

    /**
     * One statement reads and share-locks every row the caller names, in
     * ascending ID order, whatever order it names them in (C16: two
     * transactions that lock the same rows lock them in the same order).
     */
    public function testReadInventoryRulesReadsAndLocksTheRowsInOneStatementInAscendingIdOrder(): void
    {
        $lower = (int)$this->inventory(null)->id;
        $higher = (int)$this->inventory(null)->id;
        $this->assertLessThan($higher, $lower);

        $locked = $this->statementsOf(static function () use ($lower, $higher): void {
            JobTemplateGate::readInventoryRules([$higher, $lower], true);
        });
        $read = $this->statementsOf(static function () use ($lower, $higher): void {
            JobTemplateGate::readInventoryRules([$higher, $lower], false);
        });

        $this->assertSame(
            ["SELECT * FROM `inventory` WHERE `id` IN ({$lower}, {$higher}) ORDER BY `id` LOCK IN SHARE MODE"],
            $locked
        );
        $this->assertSame(["SELECT * FROM `inventory` WHERE `id` IN ({$lower}, {$higher}) ORDER BY `id`"], $read);
    }

    public function testReadInventoryRulesDropsDuplicatesAndIdsThatAreNotPositive(): void
    {
        $id = (int)$this->inventory(null)->id;
        $rules = [];

        $statements = $this->statementsOf(static function () use ($id, &$rules): void {
            $rules = JobTemplateGate::readInventoryRules([$id, 0, $id, -4], true);
        });

        $this->assertSame(["SELECT * FROM `inventory` WHERE `id` IN ({$id}) ORDER BY `id` LOCK IN SHARE MODE"], $statements);
        $this->assertSame([$id => null], $rules);
    }

    public function testReadInventoryRulesWithoutIdsReadsNothing(): void
    {
        $results = [];

        $statements = $this->statementsOf(static function () use (&$results): void {
            $results[] = JobTemplateGate::readInventoryRules([], true);
            $results[] = JobTemplateGate::readInventoryRules([0, -1], false);
        });

        $this->assertSame([[], []], $results);
        $this->assertSame([], $statements);
    }

    /**
     * Code running before m000088: the inventory table has no
     * approval_rule_id. The raw reader finds the row, with no rule, and
     * nothing throws.
     */
    public function testReadInventoryRulesReadsATableWithoutTheColumn(): void
    {
        $scratch = $this->scratchDatabase();
        $scratch->createCommand()->createTable('{{%inventory}}', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'name' => 'VARCHAR(128) NOT NULL',
            'content' => "TEXT NULL COMMENT 'Inline INI/YAML content for static inventories'",
            'project_id' => 'INT UNSIGNED NULL',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
        $scratch->createCommand()->insert('{{%inventory}}', ['name' => 'before m000088', 'content' => "all: {}\n"])->execute();
        $id = (int)$scratch->getLastInsertID();

        $original = \Yii::$app->db;
        \Yii::$app->set('db', $scratch);
        try {
            $read = JobTemplateGate::readInventoryRules([$id, $id + 1], false);
            $locked = JobTemplateGate::readInventoryRules([$id], true);
        } finally {
            \Yii::$app->set('db', $original);
        }

        $this->assertSame([$id => null], $read);
        $this->assertSame([$id => null], $locked);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The IDs of $ids that the SQL condition calls gated, ascending.
     *
     * @param array<string, int> $ids
     * @return list<int>
     */
    private function gatedBySql(array $ids, string $templateAlias, ?string $inventoryAlias = null): array
    {
        $query = (new Query())->select("{$templateAlias}.id")->from([$templateAlias => JobTemplate::tableName()]);
        if ($inventoryAlias === null) {
            JobTemplateGate::joinInventory($query, $templateAlias);
            $query->where(JobTemplateGate::gatedCondition($templateAlias));
        } else {
            JobTemplateGate::joinInventory($query, $templateAlias, $inventoryAlias);
            $query->where(JobTemplateGate::gatedCondition($templateAlias, $inventoryAlias));
        }
        $query->andWhere([$templateAlias . '.id' => array_values($ids)])->orderBy([$templateAlias . '.id' => SORT_ASC]);

        return array_map('intval', $query->column());
    }

    private function inventory(?int $ruleId): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->setAttribute('approval_rule_id', $ruleId);
        $inventory->save(false);

        return $inventory;
    }

    private function template(Inventory $inventory, ?int $ruleId): JobTemplate
    {
        $template = $this->createJobTemplate($this->projectId, (int)$inventory->id, $this->runnerGroupId, $this->userId);
        $template->approval_rule_id = $ruleId;
        $template->save(false);

        return $template;
    }

    /**
     * Deletes $inventory although job templates use it, as only a database
     * without the foreign key could: the templates keep its ID.
     */
    private function deleteIgnoringForeignKeys(Inventory $inventory): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $db->createCommand()->delete(Inventory::tableName(), ['id' => $inventory->id])->execute();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }

    private function idThatDoesNotExist(): int
    {
        return (int)(new Query())->from(Inventory::tableName())->max('id') + 1000;
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

    /**
     * A connection to the scratch database, created when missing, whose
     * tables carry a prefix of this test; skips the test when the database
     * user may not create it.
     */
    private function scratchDatabase(): Connection
    {
        $main = \Yii::$app->db;
        $serverDsn = (string)preg_replace('/;?dbname=[^;]*/', '', $main->dsn);
        $server = new Connection(['dsn' => $serverDsn, 'username' => $main->username, 'password' => $main->password, 'charset' => 'utf8mb4']);
        try {
            $server->createCommand('CREATE DATABASE IF NOT EXISTS ' . self::SCRATCH_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
        } catch (\yii\db\Exception) {
            $this->markTestSkipped('The database user may not create ' . self::SCRATCH_DB . ' (bin/tests-phpunit.sh grants it).');
        } finally {
            $server->close();
        }
        $this->scratch = new Connection([
            'dsn' => $serverDsn . ';dbname=' . self::SCRATCH_DB,
            'username' => $main->username,
            'password' => $main->password,
            'charset' => 'utf8mb4',
            'tablePrefix' => 'gate_' . bin2hex(random_bytes(3)) . '_',
        ]);

        return $this->scratch;
    }
}
