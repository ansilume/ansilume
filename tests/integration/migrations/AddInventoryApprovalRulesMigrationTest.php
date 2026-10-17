<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\components\DbErrorSummary;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\tests\integration\DbTestCase;
use yii\db\ColumnSchema;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * m000088_000000_add_inventory_approval_rules: inventory.content and the
 * parse cache inventory.parsed_hosts become MEDIUMTEXT, inventories get an
 * approval rule (approval_rule_id, with an index and a foreign key that
 * RESTRICTs the delete of a used rule), and approval requests say what
 * required them (approval_request.required_by, backfilled for workflow
 * approval steps).
 *
 * DDL commits implicitly in MySQL/MariaDB. The schema the real migration
 * produced in the test database is checked as it is, with writes inside
 * the test's transaction. up() and down() run for real in the scratch
 * database ansilume_migrate_test (bin/tests-phpunit.sh grants it; without
 * the grant those tests are skipped), on tables under a prefix of their
 * own: other migration tests use the same database.
 */
class AddInventoryApprovalRulesMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';
    private const CONTENT_COMMENT = 'Inline INI/YAML content for static inventories';
    private const PARSED_COMMENT = 'Cached JSON output of ansible-inventory parse';
    private const RULE_COMMENT = 'The approval rule every launch with this inventory waits for; null: none';

    /** inventory.approval_rule_id as up() adds it. */
    private const RULE_COLUMN = "INT UNSIGNED NULL COMMENT '" . self::RULE_COMMENT . "' AFTER [[project_id]]";

    /** The scratch tables, children first. */
    private const SCRATCH_TABLES = ['workflow_job_step', 'workflow_step', 'approval_request', 'inventory', 'approval_rule'];

    private ?Connection $scratch = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // A test may preload another version of the migration (the regression check of 3007a3b).
        if (!class_exists(\m000088_000000_add_inventory_approval_rules::class, false)) {
            require_once dirname(__DIR__, 3) . '/migrations/m000088_000000_add_inventory_approval_rules.php';
        }
    }

    protected function tearDown(): void
    {
        $scratch = $this->scratch;
        if ($scratch !== null) {
            foreach (self::SCRATCH_TABLES as $table) {
                $scratch->createCommand('DROP TABLE IF EXISTS {{%' . $table . '}}')->execute();
            }
            $scratch->close();
            $this->scratch = null;
        }
        parent::tearDown();
    }

    // -- The test database, as the real migration left it -------------------------

    public function testTheTestDatabaseHasTheNewColumnsAndKeys(): void
    {
        $this->assertMigratedSchema(\Yii::$app->db);
    }

    /**
     * A rule that gates an inventory cannot be deleted: deleting it would
     * silently ungate every job template on the inventory.
     */
    public function testARuleThatGatesAnInventoryCannotBeDeleted(): void
    {
        $user = $this->createUser('m88_rule');
        $rule = $this->createApprovalRule((int)$user->id);
        $inventory = $this->createInventory((int)$user->id);
        $inventory->setAttribute('approval_rule_id', (int)$rule->id);
        $inventory->save(false);

        try {
            $rule->delete();
            $this->fail('expected the foreign key to restrict the delete');
        } catch (IntegrityException $e) {
            $this->assertSame('1451', DbErrorSummary::of($e)[2]);
        }

        $this->assertTrue(ApprovalRule::find()->where(['id' => $rule->id])->exists());
        $inventory->refresh();
        $this->assertSame((int)$rule->id, (int)$inventory->getAttribute('approval_rule_id'), 'the inventory stays gated');
    }

    public function testAnInventoryCannotPointToARuleThatDoesNotExist(): void
    {
        $inventory = $this->createInventory((int)$this->createUser('m88_unknown')->id);

        $this->expectException(IntegrityException::class);
        Inventory::updateAll(['approval_rule_id' => 987654321], ['id' => $inventory->id]);
    }

    public function testANewRuleIdOfTheRuleCarriesOverToItsInventories(): void
    {
        $user = $this->createUser('m88_cascade');
        $rule = $this->createApprovalRule((int)$user->id);
        $inventory = $this->createInventory((int)$user->id);
        $inventory->setAttribute('approval_rule_id', (int)$rule->id);
        $inventory->save(false);
        $newId = (int)(new Query())->from(ApprovalRule::tableName())->max('id') + 1000;

        ApprovalRule::updateAll(['id' => $newId], ['id' => $rule->id]);

        $inventory->refresh();
        $this->assertSame($newId, (int)$inventory->getAttribute('approval_rule_id'));
    }

    public function testUpChangesNothingOnAMigratedSchema(): void
    {
        $migration = $this->recordingMigration(\Yii::$app->db);

        $output = $this->quietly(static fn () => $migration->up());

        $this->assertSame([], $migration->calls);
        $this->assertSame('', $output);
    }

    // -- Up and down for real, in the scratch database -------------------------------

    public function testUpMigratesTheSchemaOfVersion2111(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $inventoryId = $this->insertRow($db, 'inventory', ['name' => 'web', 'content' => "all: {}\n"] + self::parseCache('{"groups":{},"hosts":{},"notices":[]}'));
        $requestId = $this->insertRow($db, 'approval_request', ['job_id' => 1, 'approval_rule_id' => $ruleId]);

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertSame('', $output, 'nothing to clear or mark');
        $this->assertMigratedSchema($db);
        $this->assertNull($this->value($db, 'inventory', 'approval_rule_id', $inventoryId), 'existing inventories have no rule');
        $this->assertSame("all: {}\n", $this->value($db, 'inventory', 'content', $inventoryId));
        $this->assertSame('{"groups":{},"hosts":{},"notices":[]}', $this->value($db, 'inventory', 'parsed_hosts', $inventoryId), 'the parse cache stays');
        $this->assertSame('job_template', $this->value($db, 'approval_request', 'required_by', $requestId));

        $again = $this->recordingMigration($db);
        $this->assertSame('', $this->quietly(static fn () => $again->up()), 'a second run has nothing to do');
        $this->assertSame([], $again->calls, 'and sends no DDL');
        $this->assertMigratedSchema($db);
    }

    /**
     * regression: the inventory form accepted 262,144 characters, but
     * inventory.content was TEXT (65,535 bytes), so a longer static
     * inventory failed with a server error (SQLSTATE 22001) instead of
     * being saved. MEDIUMTEXT holds it.
     */
    public function testRegressionContentTheFormAcceptsDidNotFitTheColumn(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $content = str_repeat('x', 70000);
        try {
            $db->createCommand()->insert('{{%inventory}}', ['name' => 'before', 'content' => $content])->execute();
            $this->fail('TEXT stores 65,535 bytes at most');
        } catch (\yii\db\Exception $e) {
            $this->assertSame('22001', DbErrorSummary::of($e)[1], 'refused as data too long');
        }

        $this->quietly(fn () => $this->migration($db)->up());

        try {
            $id = $this->insertRow($db, 'inventory', ['name' => 'after', 'content' => $content]);
        } catch (\yii\db\Exception $e) {
            $this->fail(sprintf('The content was not stored: %s (SQLSTATE %s, driver error %s).', ...DbErrorSummary::of($e)));
        }
        $this->assertSame(70000, strlen((string)$this->value($db, 'inventory', 'content', $id)));
    }

    /**
     * regression: Parse Inventory caches its JSON in inventory.parsed_hosts,
     * which stayed TEXT (65,535 bytes). That JSON is longer than the
     * content (for 1,200 hosts, or for 150 hosts with group variables), so
     * caching the parse of such an inventory failed with a server error
     * (SQLSTATE 22001, driver error 1406), and so did every inventory that
     * only MEDIUMTEXT content holds. MEDIUMTEXT holds it.
     */
    public function testRegressionTheParseOfALargeInventoryDidNotFitTheCache(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $json = self::parsedHostsOf(70000);
        try {
            $db->createCommand()->insert('{{%inventory}}', ['name' => 'before'] + self::parseCache($json))->execute();
            $this->fail('TEXT stores 65,535 bytes at most');
        } catch (\yii\db\Exception $e) {
            $this->assertSame(['22001', '1406'], array_slice(DbErrorSummary::of($e), 1), 'refused as data too long');
        }

        $this->quietly(fn () => $this->migration($db)->up());

        try {
            $id = $this->insertRow($db, 'inventory', ['name' => 'after'] + self::parseCache($json));
        } catch (\yii\db\Exception $e) {
            $this->fail(sprintf('The parse was not cached: %s (SQLSTATE %s, driver error %s).', ...DbErrorSummary::of($e)));
        }
        $this->assertSame($json, $this->value($db, 'inventory', 'parsed_hosts', $id));
    }

    /**
     * The requests of workflow approval steps are found through their
     * step execution: the step type it recorded, else its step's, compared
     * as binary. Everything else keeps the default.
     */
    public function testUpMarksTheRequestsOfApprovalStepsOnly(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $approvalStep = $this->insertRow($db, 'workflow_step', ['step_type' => 'approval']);
        $upperCaseStep = $this->insertRow($db, 'workflow_step', ['step_type' => 'APPROVAL']);
        $jobStep = $this->insertRow($db, 'workflow_step', ['step_type' => 'job']);
        $cases = [
            'a recorded approval step' => ['approval', null, true],
            'nothing recorded, an approval step' => [null, $approvalStep, true],
            'an empty recorded type, an approval step' => ['', $approvalStep, true],
            'an empty recorded type, the step gone' => ['', null, false],
            'nothing recorded, the step gone' => [null, null, false],
            'a recorded APPROVAL' => ['APPROVAL', null, false],
            'nothing recorded, an APPROVAL step' => [null, $upperCaseStep, false],
            'a recorded job step' => ['job', $jobStep, false],
            'a recorded job step, its step since changed to approval' => ['job', $approvalStep, false],
        ];
        $requests = [];
        foreach ($cases as $label => [$recorded, $stepId, $marked]) {
            $jobId = count($requests) + 100;
            $this->insertRow($db, 'workflow_job_step', ['job_id' => $jobId, 'workflow_step_id' => $stepId, 'step_type' => $recorded]);
            $requests[$label] = [$this->insertRow($db, 'approval_request', ['job_id' => $jobId, 'approval_rule_id' => $ruleId]), $marked];
        }
        $requests['a job without a step execution'] = [$this->insertRow($db, 'approval_request', ['job_id' => 999, 'approval_rule_id' => $ruleId]), false];

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertSame(sprintf(\m000088_000000_add_inventory_approval_rules::MARKED, 3), $output);
        $this->assertSame(
            "    > Marked 3 approval request(s) of workflow approval steps as required by an approval step.\n",
            $output
        );
        foreach ($requests as $label => [$requestId, $marked]) {
            $this->assertSame($marked ? 'approval_step' : 'job_template', $this->value($db, 'approval_request', 'required_by', $requestId), $label);
        }
        $this->assertSame('', $this->quietly(fn () => $this->migration($db)->up()), 'marked once');
    }

    /**
     * DDL commits implicitly, so a run can fail half way: here after the
     * column and its index, before the foreign key. Without the key a
     * deleted rule's ID can stay behind. The next container start runs the
     * migration again; it must finish the work, not fail.
     */
    public function testARetryFinishesWhatAFailedRunLeft(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $command = $db->createCommand();
        $command->alterColumn('{{%inventory}}', 'content', "MEDIUMTEXT NULL COMMENT '" . self::CONTENT_COMMENT . "'")->execute();
        $command->addColumn('{{%inventory}}', 'approval_rule_id', self::RULE_COLUMN)->execute();
        $command->createIndex('idx-inventory-approval_rule', '{{%inventory}}', 'approval_rule_id')->execute();
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $keptId = $this->insertRow($db, 'inventory', ['name' => 'kept', 'approval_rule_id' => $ruleId]);
        $clearedId = $this->insertRow($db, 'inventory', ['name' => 'cleared', 'approval_rule_id' => 555]);

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertSame(sprintf(\m000088_000000_add_inventory_approval_rules::CLEARED, 555, $clearedId), $output);
        $this->assertSame("    > Cleared approval rule #555, which does not exist, from inventory #{$clearedId}.\n", $output);
        $this->assertNull($this->value($db, 'inventory', 'approval_rule_id', $clearedId));
        $this->assertSame((string)$ruleId, (string)$this->value($db, 'inventory', 'approval_rule_id', $keptId), 'existing rules stay');
        $this->assertMigratedSchema($db);
    }

    public function testAForeignKeyThatDidNotRestrictTheDeleteIsReplaced(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $command = $db->createCommand();
        $command->addColumn('{{%inventory}}', 'approval_rule_id', self::RULE_COLUMN)->execute();
        $command->createIndex('idx-inventory-approval_rule', '{{%inventory}}', 'approval_rule_id')->execute();
        $command->addForeignKey('fk-inventory-approval_rule', '{{%inventory}}', 'approval_rule_id', '{{%approval_rule}}', 'id', 'SET NULL', 'CASCADE')->execute();

        $this->quietly(fn () => $this->migration($db)->up());

        $this->assertMigratedSchema($db);
    }

    /**
     * Down names the inventories whose rule it removes, and keeps
     * MEDIUMTEXT while some content needs it.
     */
    public function testDownRemovesTheRulesItNamesAndKeepsLongContent(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $otherRuleId = $this->insertRow($db, 'approval_rule', ['name' => 'other']);
        $gatedId = $this->insertRow($db, 'inventory', ['name' => 'gated', 'approval_rule_id' => $ruleId]);
        $otherId = $this->insertRow($db, 'inventory', ['name' => 'other', 'approval_rule_id' => $otherRuleId]);
        $this->insertRow($db, 'inventory', ['name' => 'plain']);
        $longId = $this->insertRow($db, 'inventory', ['name' => 'long', 'content' => str_repeat('x', 65536)]);
        $this->insertRow($db, 'inventory', ['name' => 'at the limit', 'content' => str_repeat('x', 65535)]);

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame(
            sprintf(\m000088_000000_add_inventory_approval_rules::RULES_REMOVED, 2, "#{$gatedId} (rule #{$ruleId}), #{$otherId} (rule #{$otherRuleId})")
            . sprintf(\m000088_000000_add_inventory_approval_rules::CONTENT_KEPT, "#{$longId}"),
            $output
        );
        $this->assertStringContainsString(
            "    > Removed the approval rule of 2 inventory(ies): #{$gatedId} (rule #{$ruleId}), #{$otherId} (rule #{$otherRuleId}). "
            . "Their job templates launch without approval unless they have a rule of their own.\n",
            $output
        );
        $this->assertStringEndsWith(
            "    > Kept inventory.content as MEDIUMTEXT: inventory(ies) #{$longId} hold more than 65535 bytes, which TEXT cannot store; "
            . "2.11.1 works with MEDIUMTEXT.\n",
            $output
        );
        $this->assertNull($this->column($db, '{{%inventory}}', 'approval_rule_id'));
        $this->assertNull($this->deleteRule($db, 'inventory', 'fk-inventory-approval_rule'));
        $this->assertNotContains('idx-inventory-approval_rule', $this->indexNames($db, '{{%inventory}}'));
        $this->assertNull($this->column($db, '{{%approval_request}}', 'required_by'));
        $this->assertSame('mediumtext', $this->column($db, '{{%inventory}}', 'content')?->dbType, 'kept for the long content');
        $this->assertSame(65536, strlen((string)$this->value($db, 'inventory', 'content', $longId)));

        $again = $this->recordingMigration($db);
        $this->assertSame(
            sprintf(\m000088_000000_add_inventory_approval_rules::CONTENT_KEPT, "#{$longId}"),
            $this->quietly(static fn () => $again->down()),
            'a second down changes nothing and says again why MEDIUMTEXT stays'
        );
        $this->assertSame([], $again->calls);
    }

    /**
     * Down narrows parsed_hosts to TEXT again. A parse cache longer than
     * TEXT can store is cleared first, the hosts, the error and the time of
     * the parse, and its inventories are named: it is only a cache, which
     * the next Parse Inventory writes again. Every other cache stays.
     */
    public function testDownClearsTheParseCachesThatTextCannotHold(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $longId = $this->insertRow($db, 'inventory', ['name' => 'long'] + self::parseCache(self::parsedHostsOf(65536)));
        $longerId = $this->insertRow($db, 'inventory', ['name' => 'longer'] + self::parseCache(self::parsedHostsOf(200000)));
        $kept = [
            'at the limit' => self::parseCache(self::parsedHostsOf(65535)),
            'small' => self::parseCache('{"groups":{},"hosts":{},"notices":[]}'),
            'a parse error' => ['parsed_hosts' => null, 'parsed_error' => 'ansible-inventory failed (exit 1): no inventory', 'parsed_at' => 1700000000],
            'never parsed' => ['parsed_hosts' => null, 'parsed_error' => null, 'parsed_at' => null],
        ];
        $keptIds = [];
        foreach ($kept as $name => $cache) {
            $keptIds[$name] = $this->insertRow($db, 'inventory', ['name' => $name] + $cache);
        }

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame(sprintf(\m000088_000000_add_inventory_approval_rules::PARSED_CLEARED, "#{$longId}, #{$longerId}"), $output);
        $this->assertSame(
            "    > Cleared the parsed hosts of inventory(ies) #{$longId}, #{$longerId}: they hold more than 65535 bytes, which TEXT cannot store.\n",
            $output
        );
        foreach ([$longId, $longerId] as $id) {
            $this->assertSame(['parsed_hosts' => null, 'parsed_error' => null, 'parsed_at' => null], $this->parseCacheOf($db, $id), "inventory #{$id}");
        }
        foreach ($keptIds as $name => $id) {
            $this->assertSame($kept[$name], $this->parseCacheOf($db, $id), $name);
        }
        $this->assertOldSchema($db);

        $again = $this->recordingMigration($db);
        $this->assertSame('', $this->quietly(static fn () => $again->down()), 'a second down has nothing to do');
        $this->assertSame([], $again->calls);

        $this->quietly(fn () => $this->migration($db)->up());
        $this->assertMigratedSchema($db);
        foreach ($keptIds as $name => $id) {
            $this->assertSame($kept[$name], $this->parseCacheOf($db, $id), "{$name}, after up again");
        }
    }

    /**
     * With all three to say, down says them in this order: the rules it
     * removes, the content it keeps as MEDIUMTEXT, the parse caches it
     * clears.
     */
    public function testDownSaysWhatItRemovesKeepsAndClearsInThisOrder(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $ruleId = $this->insertRow($db, 'approval_rule', ['name' => 'r']);
        $gatedId = $this->insertRow($db, 'inventory', ['name' => 'gated', 'approval_rule_id' => $ruleId]);
        $longId = $this->insertRow($db, 'inventory', ['name' => 'long', 'content' => str_repeat('x', 65536)] + self::parseCache(self::parsedHostsOf(70000)));

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame(
            sprintf(\m000088_000000_add_inventory_approval_rules::RULES_REMOVED, 1, "#{$gatedId} (rule #{$ruleId})")
            . sprintf(\m000088_000000_add_inventory_approval_rules::CONTENT_KEPT, "#{$longId}")
            . sprintf(\m000088_000000_add_inventory_approval_rules::PARSED_CLEARED, "#{$longId}"),
            $output
        );
        $this->assertSame('mediumtext', $this->column($db, '{{%inventory}}', 'content')?->dbType, 'kept for the long content');
        $this->assertSame('text', $this->column($db, '{{%inventory}}', 'parsed_hosts')?->dbType, 'the cache narrowed');
        $this->assertSame(['parsed_hosts' => null, 'parsed_error' => null, 'parsed_at' => null], $this->parseCacheOf($db, $longId));
        $this->assertSame(65536, strlen((string)$this->value($db, 'inventory', 'content', $longId)), 'the content stays');
    }

    public function testDownRestoresTheSchemaOfVersion2111AndUpWorksAgain(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $inventoryId = $this->insertRow($db, 'inventory', ['name' => 'short', 'content' => "all: {}\n"]);

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame('', $output, 'no rules to remove, no long content');
        $this->assertOldSchema($db);
        $this->assertSame("all: {}\n", $this->value($db, 'inventory', 'content', $inventoryId));

        $again = $this->recordingMigration($db);
        $this->assertSame('', $this->quietly(static fn () => $again->down()), 'a second down has nothing to do');
        $this->assertSame([], $again->calls);

        $this->quietly(fn () => $this->migration($db)->up());
        $this->assertMigratedSchema($db);
    }

    // -- Helpers ----------------------------------------------------------------

    private function assertMigratedSchema(Connection $db): void
    {
        $content = $this->column($db, '{{%inventory}}', 'content');
        $this->assertNotNull($content, 'inventory.content exists');
        $this->assertSame('mediumtext', $content->dbType);
        $this->assertTrue($content->allowNull);
        $this->assertSame(self::CONTENT_COMMENT, $content->comment);

        $parsed = $this->column($db, '{{%inventory}}', 'parsed_hosts');
        $this->assertNotNull($parsed, 'inventory.parsed_hosts exists');
        $this->assertSame('mediumtext', $parsed->dbType);
        $this->assertTrue($parsed->allowNull);
        $this->assertSame(self::PARSED_COMMENT, $parsed->comment);

        $rule = $this->column($db, '{{%inventory}}', 'approval_rule_id');
        $this->assertNotNull($rule, 'inventory.approval_rule_id exists');
        $this->assertSame('integer', $rule->type);
        $this->assertTrue($rule->unsigned);
        $this->assertTrue($rule->allowNull);
        $this->assertNull($rule->defaultValue);
        $this->assertSame(self::RULE_COMMENT, $rule->comment);
        $this->assertSame('approval_rule_id', $this->columnAfter($db, '{{%inventory}}', 'project_id'));
        $this->assertContains('idx-inventory-approval_rule', $this->indexNames($db, '{{%inventory}}'));
        $this->assertSame('RESTRICT', $this->deleteRule($db, 'inventory', 'fk-inventory-approval_rule'));
        $this->assertSame('CASCADE', $this->updateRule($db, 'inventory', 'fk-inventory-approval_rule'));
        $this->assertSame(
            ['column' => 'approval_rule_id', 'table' => $db->getSchema()->getRawTableName('{{%approval_rule}}'), 'refColumn' => 'id'],
            $this->foreignKeyColumns($db, 'inventory', 'fk-inventory-approval_rule')
        );

        $requiredBy = $this->column($db, '{{%approval_request}}', 'required_by');
        $this->assertNotNull($requiredBy, 'approval_request.required_by exists');
        $this->assertSame('varchar(16)', $requiredBy->dbType);
        $this->assertFalse($requiredBy->allowNull);
        $this->assertSame('job_template', $requiredBy->defaultValue);
        $this->assertSame('What required the request: job_template, inventory or approval_step', $requiredBy->comment);
        $this->assertSame('required_by', $this->columnAfter($db, '{{%approval_request}}', 'approval_rule_id'));
    }

    private function assertOldSchema(Connection $db): void
    {
        $content = $this->column($db, '{{%inventory}}', 'content');
        $this->assertNotNull($content);
        $this->assertSame('text', $content->dbType);
        $this->assertTrue($content->allowNull);
        $this->assertSame(self::CONTENT_COMMENT, $content->comment);
        $parsed = $this->column($db, '{{%inventory}}', 'parsed_hosts');
        $this->assertNotNull($parsed);
        $this->assertSame('text', $parsed->dbType);
        $this->assertTrue($parsed->allowNull);
        $this->assertSame(self::PARSED_COMMENT, $parsed->comment);
        $this->assertNull($this->column($db, '{{%inventory}}', 'approval_rule_id'));
        $this->assertNull($this->deleteRule($db, 'inventory', 'fk-inventory-approval_rule'));
        $this->assertNotContains('idx-inventory-approval_rule', $this->indexNames($db, '{{%inventory}}'));
        $this->assertNull($this->column($db, '{{%approval_request}}', 'required_by'));
    }

    private function migration(Connection $db): \m000088_000000_add_inventory_approval_rules
    {
        return new \m000088_000000_add_inventory_approval_rules(['db' => $db, 'compact' => true]);
    }

    /**
     * The migration with every schema or data change recorded instead of
     * run.
     *
     * @return \m000088_000000_add_inventory_approval_rules&object{calls: list<string>}
     */
    private function recordingMigration(Connection $db): \m000088_000000_add_inventory_approval_rules
    {
        return new class (['db' => $db, 'compact' => true]) extends \m000088_000000_add_inventory_approval_rules {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @param string $table
             * @param string $column
             * @param mixed $type
             */
            public function addColumn($table, $column, $type): void
            {
                $this->calls[] = "addColumn {$table}.{$column}";
            }

            /**
             * @param string $table
             * @param string $column
             */
            public function dropColumn($table, $column): void
            {
                $this->calls[] = "dropColumn {$table}.{$column}";
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
     * The tables as 2.11.1 (m000087) left them, reduced to the columns the
     * migration touches or places a column after, in the collation of the
     * real tables (in which 'APPROVAL' equals 'approval').
     */
    private function createOldSchema(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $command = $db->createCommand();
        $command->createTable('{{%approval_rule}}', ['id' => $pk, 'name' => 'VARCHAR(128) NOT NULL'], $options)->execute();
        $command->createTable('{{%inventory}}', [
            'id' => $pk,
            'name' => 'VARCHAR(128) NOT NULL',
            'content' => "TEXT NULL COMMENT '" . self::CONTENT_COMMENT . "'",
            'source_path' => 'VARCHAR(512) NULL',
            'project_id' => 'INT UNSIGNED NULL',
            'parsed_hosts' => "TEXT NULL COMMENT '" . self::PARSED_COMMENT . "'",
            'parsed_error' => 'TEXT NULL',
            'parsed_at' => 'INT UNSIGNED NULL',
        ], $options)->execute();
        $command->createTable('{{%approval_request}}', [
            'id' => $pk,
            'job_id' => 'INT UNSIGNED NOT NULL',
            'approval_rule_id' => 'INT UNSIGNED NULL',
            'status' => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
        ], $options)->execute();
        $command->createTable('{{%workflow_step}}', ['id' => $pk, 'step_type' => 'VARCHAR(16) NOT NULL'], $options)->execute();
        $command->createTable('{{%workflow_job_step}}', [
            'id' => $pk,
            'workflow_step_id' => 'INT UNSIGNED NULL',
            'job_id' => 'INT UNSIGNED NULL',
            'step_type' => 'VARCHAR(16) NULL',
        ], $options)->execute();
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insertRow(Connection $db, string $table, array $columns): int
    {
        $db->createCommand()->insert("{{%{$table}}}", $columns)->execute();

        return (int)$db->getLastInsertID();
    }

    private function value(Connection $db, string $table, string $column, int $id): mixed
    {
        return (new Query())->select($column)->from("{{%{$table}}}")->where(['id' => $id])->scalar($db);
    }

    /**
     * The parse cache of inventory $id.
     *
     * @return array{parsed_hosts: string|null, parsed_error: string|null, parsed_at: int|null}
     */
    private function parseCacheOf(Connection $db, int $id): array
    {
        $row = (new Query())->select(['parsed_hosts', 'parsed_error', 'parsed_at'])->from('{{%inventory}}')->where(['id' => $id])->one($db);
        $this->assertIsArray($row, "inventory #{$id} exists");

        return [
            'parsed_hosts' => $row['parsed_hosts'] === null ? null : (string)$row['parsed_hosts'],
            'parsed_error' => $row['parsed_error'] === null ? null : (string)$row['parsed_error'],
            'parsed_at' => $row['parsed_at'] === null ? null : (int)$row['parsed_at'],
        ];
    }

    /**
     * The columns of a parse that Parse Inventory cached.
     *
     * @return array{parsed_hosts: string, parsed_error: null, parsed_at: int}
     */
    private static function parseCache(string $json): array
    {
        return ['parsed_hosts' => $json, 'parsed_error' => null, 'parsed_at' => 1700000000];
    }

    /**
     * The JSON of a parse that is $bytes bytes long: one host, whose
     * variable fills it.
     */
    private static function parsedHostsOf(int $bytes): string
    {
        $prefix = '{"groups":{},"hosts":{"web1":{"notes":"';
        $suffix = '"}},"notices":[]}';

        return $prefix . str_repeat('x', $bytes - strlen($prefix) - strlen($suffix)) . $suffix;
    }

    private function column(Connection $db, string $table, string $name): ?ColumnSchema
    {
        return $db->getTableSchema($table, true)?->getColumn($name);
    }

    /**
     * The name of the column that follows $column in $table.
     */
    private function columnAfter(Connection $db, string $table, string $column): ?string
    {
        $names = $db->getTableSchema($table, true)?->getColumnNames() ?? [];
        $position = array_search($column, $names, true);

        return $position === false ? null : ($names[$position + 1] ?? null);
    }

    private function deleteRule(Connection $db, string $table, string $name): ?string
    {
        return $this->referentialRule($db, $table, $name, 'DELETE_RULE');
    }

    private function updateRule(Connection $db, string $table, string $name): ?string
    {
        return $this->referentialRule($db, $table, $name, 'UPDATE_RULE');
    }

    private function referentialRule(Connection $db, string $table, string $name, string $rule): ?string
    {
        $value = (new Query())
            ->select($rule)
            ->from('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name', [
                ':table' => $db->getSchema()->getRawTableName("{{%{$table}}}"),
                ':name' => $name,
            ])
            ->scalar($db);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{column: string, table: string, refColumn: string}|null
     */
    private function foreignKeyColumns(Connection $db, string $table, string $name): ?array
    {
        $row = (new Query())
            ->select(['column' => 'COLUMN_NAME', 'table' => 'REFERENCED_TABLE_NAME', 'refColumn' => 'REFERENCED_COLUMN_NAME'])
            ->from('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name', [
                ':table' => $db->getSchema()->getRawTableName("{{%{$table}}}"),
                ':name' => $name,
            ])
            ->one($db);
        if (!is_array($row)) {
            return null;
        }

        return ['column' => (string)$row['column'], 'table' => (string)$row['table'], 'refColumn' => (string)$row['refColumn']];
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
            'tablePrefix' => 'm88_' . bin2hex(random_bytes(3)) . '_',
        ]);

        return $this->scratch;
    }
}
