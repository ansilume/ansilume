<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * m000083_000000_protect_approval_rule_references: job_template and
 * workflow_step references to approval rules RESTRICT their delete, and
 * approval_request.approval_rule_id is nullable with ON DELETE SET NULL.
 *
 * DDL commits implicitly in MySQL/MariaDB. The schema the real migration
 * produced in the test database is checked as it is, with deletes inside
 * the test's transaction. up() and down() run for real in the scratch
 * database ansilume_migrate_test (bin/tests-phpunit.sh grants it; without
 * the grant those tests are skipped), on tables under a prefix of their
 * own: other migration tests use the same database.
 */
class ProtectApprovalRuleReferencesMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    private ?Connection $scratch = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000083_000000_protect_approval_rule_references.php';
    }

    protected function tearDown(): void
    {
        $scratch = $this->scratch;
        if ($scratch !== null) {
            $this->dropScratchTables($scratch);
            $scratch->close();
            $this->scratch = null;
        }
        parent::tearDown();
    }

    // -- The test database, as the real migration left it -------------------------

    public function testTheForeignKeysProtectUsedRulesAndKeepRequestHistory(): void
    {
        $db = \Yii::$app->db;

        $this->assertSame('RESTRICT', $this->deleteRule($db, 'job_template', 'fk-job_template-approval_rule'));
        $this->assertSame('RESTRICT', $this->deleteRule($db, 'workflow_step', 'fk-workflow_step-approval_rule'));
        $this->assertSame('SET NULL', $this->deleteRule($db, 'approval_request', 'fk-approval_request-rule'));
        $this->assertTrue($this->column($db, '{{%approval_request}}', 'approval_rule_id')->allowNull);
        $this->assertContains('idx-workflow_step-approval_rule', $this->indexNames($db, '{{%workflow_step}}'));
    }

    /**
     * Regression: deleting a rule silently ungated its templates (ON DELETE
     * SET NULL).
     */
    public function testARuleThatGatesAJobTemplateCannotBeDeleted(): void
    {
        $user = $this->createUser('fk_rule_template');
        $rule = $this->createApprovalRule((int)$user->id);
        $template = $this->templateOf((int)$user->id);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        $this->assertDeleteRestricted($rule);

        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id, 'the template stays gated');
    }

    public function testARuleOfAWorkflowStepCannotBeDeleted(): void
    {
        $user = $this->createUser('fk_rule_step');
        $rule = $this->createApprovalRule((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $this->assertDeleteRestricted($rule);

        $step->refresh();
        $this->assertSame((int)$rule->id, (int)$step->approval_rule_id);
    }

    public function testAWorkflowStepCannotPointToARuleThatDoesNotExist(): void
    {
        $user = $this->createUser('fk_step_unknown');
        $workflow = $this->createWorkflowTemplate((int)$user->id);

        $this->expectException(IntegrityException::class);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, 987654321);
    }

    /**
     * Regression: deleting a rule deleted its requests and their decisions
     * (ON DELETE CASCADE), pending ones included. A deleted rule's resolved
     * requests now keep their history without the rule.
     */
    public function testDeletingARuleKeepsItsRequestsAndTheirDecisions(): void
    {
        $user = $this->createUser('fk_rule_history');
        $rule = $this->createApprovalRule((int)$user->id);
        $template = $this->templateOf((int)$user->id);
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_SUCCEEDED);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_APPROVED;
        $request->requested_at = time();
        $request->resolved_at = time();
        $request->save(false);
        $decision = new ApprovalDecision();
        $decision->approval_request_id = (int)$request->id;
        $decision->user_id = (int)$user->id;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->created_at = time();
        $decision->save(false);

        $this->assertSame(1, $rule->delete());

        $request->refresh();
        $this->assertNull($request->approval_rule_id);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $this->assertTrue(ApprovalDecision::find()->where(['id' => $decision->id])->exists(), 'the decision stays');
    }

    public function testUpChangesNothingOnAMigratedSchema(): void
    {
        $migration = $this->recordingMigration(\Yii::$app->db);

        $output = $this->quietly(static fn () => $migration->up());

        $this->assertSame([], $migration->calls);
        $this->assertSame('', $output);
    }

    // -- Up and down for real, in the scratch database -------------------------------

    public function testUpProtectsTheReferencesOfTheOldSchemaAndClearsUnknownRules(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $db->createCommand()->insert('{{%job_template}}', ['approval_rule_id' => $ruleId])->execute();
        $stepId = $this->insertRow($db, 'workflow_step', ['workflow_template_id' => 7, 'approval_rule_id' => 987654321]);
        $keptStepId = $this->insertRow($db, 'workflow_step', ['workflow_template_id' => 7, 'approval_rule_id' => $ruleId]);
        $this->insertRow($db, 'workflow_step', ['workflow_template_id' => 8, 'approval_rule_id' => null]);

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertMigratedSchema($db);
        $this->assertStringContainsString("    > Cleared approval rule #987654321, which does not exist, from workflow step #{$stepId}.\n", $output);
        $this->assertNull($this->stepRuleId($db, $stepId));
        $this->assertSame($ruleId, $this->stepRuleId($db, $keptStepId), 'existing rules stay');

        $this->assertSame('', $this->quietly(fn () => $this->migration($db)->up()), 'a second run has nothing to do');
        $this->assertMigratedSchema($db);
    }

    /**
     * Regression guard: DDL commits implicitly, so a run can fail between
     * dropping a foreign key and adding it again. The next container start
     * runs the migration again; it must finish the work, not fail.
     */
    public function testARetryFinishesWhatAFailedRunLeft(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        // As a run that failed after dropping the keys and altering the column.
        $db->createCommand()->dropForeignKey('fk-job_template-approval_rule', '{{%job_template}}')->execute();
        $db->createCommand()->dropForeignKey('fk-approval_request-rule', '{{%approval_request}}')->execute();
        $db->createCommand()->alterColumn('{{%approval_request}}', 'approval_rule_id', 'INT UNSIGNED NULL')->execute();
        $db->createCommand()->createIndex('idx-workflow_step-approval_rule', '{{%workflow_step}}', 'approval_rule_id')->execute();
        // Without its key, a template could keep a rule that was deleted.
        $templateId = $this->insertRow($db, 'job_template', ['approval_rule_id' => 555]);

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertMigratedSchema($db);
        $this->assertStringContainsString("    > Cleared approval rule #555, which does not exist, from job template #{$templateId}.\n", $output);
    }

    public function testDownRestoresTheOldSchemaAndUpWorksAgain(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $this->quietly(fn () => $this->migration($db)->up());
        $keptId = $this->insertRow($db, 'approval_request', ['approval_rule_id' => $ruleId]);
        $orphanId = $this->insertRow($db, 'approval_request', ['approval_rule_id' => null]);
        $this->insertRow($db, 'approval_decision', ['approval_request_id' => $orphanId]);

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame('SET NULL', $this->deleteRule($db, 'job_template', 'fk-job_template-approval_rule'));
        $this->assertNull($this->deleteRule($db, 'workflow_step', 'fk-workflow_step-approval_rule'));
        $this->assertNotContains('idx-workflow_step-approval_rule', $this->indexNames($db, '{{%workflow_step}}'));
        $this->assertSame('CASCADE', $this->deleteRule($db, 'approval_request', 'fk-approval_request-rule'));
        $this->assertFalse($this->column($db, '{{%approval_request}}', 'approval_rule_id')->allowNull);
        $this->assertSame([$keptId], $this->ids($db, 'approval_request'), 'a request without its rule cannot stay in the old schema');
        $this->assertSame([], $this->ids($db, 'approval_decision'), 'its decisions go with it');
        $this->assertStringContainsString("    > Deleted 1 approval request(s) of deleted approval rules, with their decisions.\n", $output);

        $this->assertSame('', $this->quietly(fn () => $this->migration($db)->down()), 'a second down has nothing to do');

        $this->quietly(fn () => $this->migration($db)->up());
        $this->assertMigratedSchema($db);
    }

    // -- Helpers ----------------------------------------------------------------

    private function assertDeleteRestricted(ApprovalRule $rule): void
    {
        try {
            $rule->delete();
            $this->fail('expected the foreign key to restrict the delete');
        } catch (IntegrityException $e) {
            $this->assertStringContainsString('1451', $e->getMessage());
        }
        $this->assertTrue(ApprovalRule::find()->where(['id' => $rule->id])->exists());
    }

    private function assertMigratedSchema(Connection $db): void
    {
        $this->assertSame('RESTRICT', $this->deleteRule($db, 'job_template', 'fk-job_template-approval_rule'));
        $this->assertSame('RESTRICT', $this->deleteRule($db, 'workflow_step', 'fk-workflow_step-approval_rule'));
        $this->assertContains('idx-workflow_step-approval_rule', $this->indexNames($db, '{{%workflow_step}}'));
        $this->assertSame('SET NULL', $this->deleteRule($db, 'approval_request', 'fk-approval_request-rule'));
        $this->assertTrue($this->column($db, '{{%approval_request}}', 'approval_rule_id')->allowNull);
    }

    private function templateOf(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function migration(Connection $db): \m000083_000000_protect_approval_rule_references
    {
        return new \m000083_000000_protect_approval_rule_references(['db' => $db, 'compact' => true]);
    }

    /**
     * The migration with every schema or data change recorded instead of
     * run.
     *
     * @return \m000083_000000_protect_approval_rule_references&object{calls: list<string>}
     */
    private function recordingMigration(Connection $db): \m000083_000000_protect_approval_rule_references
    {
        return new class (['db' => $db, 'compact' => true]) extends \m000083_000000_protect_approval_rule_references {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @param string $name
             * @param string $table
             * @param string|list<string> $columns
             * @param string $refTable
             * @param string|list<string> $refColumns
             * @param string|null $delete
             * @param string|null $update
             */
            public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null): void
            {
                $this->calls[] = "addForeignKey {$name}";
            }

            /**
             * @param string $name
             * @param string $table
             */
            public function dropForeignKey($name, $table): void
            {
                $this->calls[] = "dropForeignKey {$name}";
            }

            /**
             * @param string $table
             * @param string $column
             * @param mixed $type
             */
            public function alterColumn($table, $column, $type): void
            {
                $this->calls[] = "alterColumn {$table}.{$column}";
            }

            /**
             * @param string $name
             * @param string $table
             * @param string|list<string> $columns
             * @param bool $unique
             */
            public function createIndex($name, $table, $columns, $unique = false): void
            {
                $this->calls[] = "createIndex {$name}";
            }

            /**
             * @param string $name
             * @param string $table
             */
            public function dropIndex($name, $table): void
            {
                $this->calls[] = "dropIndex {$name}";
            }

            /**
             * @param string $table
             * @param array<string, mixed> $columns
             * @param string|array<int|string, mixed> $condition
             * @param array<string, mixed> $params
             */
            public function update($table, $columns, $condition = '', $params = []): void
            {
                $this->calls[] = "update {$table}";
            }
        };
    }

    /**
     * The tables as m000050, m000051 and m000053 left them, reduced to the
     * columns and keys the migration touches.
     */
    private function createOldSchema(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $command = $db->createCommand();
        $command->createTable('{{%approval_rule}}', ['id' => $pk, 'name' => 'VARCHAR(128) NOT NULL'], $options)->execute();
        $command->createTable('{{%job_template}}', ['id' => $pk, 'approval_rule_id' => 'INT UNSIGNED NULL'], $options)->execute();
        $command->addForeignKey('fk-job_template-approval_rule', '{{%job_template}}', 'approval_rule_id', '{{%approval_rule}}', 'id', 'SET NULL', 'CASCADE')->execute();
        $command->createTable('{{%workflow_step}}', ['id' => $pk, 'workflow_template_id' => 'INT UNSIGNED NOT NULL', 'approval_rule_id' => 'INT UNSIGNED NULL'], $options)->execute();
        $command->createTable('{{%approval_request}}', ['id' => $pk, 'approval_rule_id' => 'INT UNSIGNED NOT NULL'], $options)->execute();
        $command->addForeignKey('fk-approval_request-rule', '{{%approval_request}}', 'approval_rule_id', '{{%approval_rule}}', 'id', 'CASCADE', 'CASCADE')->execute();
        $command->createTable('{{%approval_decision}}', ['id' => $pk, 'approval_request_id' => 'INT UNSIGNED NOT NULL'], $options)->execute();
        $command->addForeignKey('fk-m83-approval_decision-request', '{{%approval_decision}}', 'approval_request_id', '{{%approval_request}}', 'id', 'CASCADE', 'CASCADE')->execute();
    }

    private function dropScratchTables(Connection $db): void
    {
        foreach (['approval_decision', 'approval_request', 'workflow_step', 'job_template', 'approval_rule'] as $table) {
            $db->createCommand('DROP TABLE IF EXISTS {{%' . $table . '}}')->execute();
        }
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insertRow(Connection $db, string $table, array $columns): int
    {
        $db->createCommand()->insert("{{%{$table}}}", $columns)->execute();

        return (int)$db->getLastInsertID();
    }

    private function stepRuleId(Connection $db, int $stepId): ?int
    {
        $value = (new Query())->select('approval_rule_id')->from('{{%workflow_step}}')->where(['id' => $stepId])->scalar($db);

        return $value === null ? null : (int)$value;
    }

    /**
     * @return list<int>
     */
    private function ids(Connection $db, string $table): array
    {
        return array_map('intval', (new Query())->select('id')->from("{{%{$table}}}")->orderBy(['id' => SORT_ASC])->column($db));
    }

    private function deleteRule(Connection $db, string $table, string $name): ?string
    {
        $rule = (new Query())
            ->select('DELETE_RULE')
            ->from('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name', [
                ':table' => $db->getSchema()->getRawTableName("{{%{$table}}}"),
                ':name' => $name,
            ])
            ->scalar($db);

        return is_string($rule) ? $rule : null;
    }

    /**
     * @return list<string>
     */
    private function indexNames(Connection $db, string $table): array
    {
        return array_map('strval', (new Query())
            ->select('INDEX_NAME')
            ->distinct()
            ->from('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [':table' => $db->getSchema()->getRawTableName($table)])
            ->column($db));
    }

    private function column(Connection $db, string $table, string $name): \yii\db\ColumnSchema
    {
        $column = $db->getTableSchema($table, true)?->getColumn($name);
        $this->assertNotNull($column, "{$table}.{$name} exists");

        return $column;
    }

    private function quietly(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
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
        } catch (\yii\db\Exception $e) {
            $this->markTestSkipped('The database user may not create ' . self::SCRATCH_DB . ' (bin/tests-phpunit.sh grants it): ' . $e->getMessage());
        } finally {
            $server->close();
        }
        $this->scratch = new Connection([
            'dsn' => $serverDsn . ';dbname=' . self::SCRATCH_DB,
            'username' => $main->username,
            'password' => $main->password,
            'charset' => 'utf8mb4',
            'tablePrefix' => 'm83_' . bin2hex(random_bytes(3)) . '_',
        ]);

        return $this->scratch;
    }
}
