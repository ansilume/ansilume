<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\DbErrorSummary;
use app\controllers\ApprovalController;
use app\controllers\InventoryController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryService;
use app\services\InventoryWriteService;
use app\services\JobTemplateGate;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\InventoryApprovalDocsContractTest;
use app\tests\unit\docs\ReadsOperatorDocs;
use Symfony\Component\Yaml\Yaml;
use yii\db\IntegrityException;

/**
 * What docs/updating.md's section for the update from 2.11.1 (approval
 * rules for inventories) says about the schema, the roles and the model
 * holds on the database and the RBAC of this release: the fields only
 * admins change on a gated inventory are its columns but the open ones; the
 * table of who may do what is what the built-in roles hold and what the
 * controllers require; what required a request takes the values of the
 * code, the column and the spec; the columns, the index and the foreign key
 * are what the migration made; and the hosts the docs give for the
 * localhost that the claim gave a job without hosts are a static inventory
 * that the form and the API take.
 *
 * Regression (2.12.0): no doc said which fields of an inventory with an
 * approval rule only admins change, who may do what, or what the section's
 * advice for a job that ran against the runner itself stores. Each test
 * fails on the docs from before, which had no such section.
 *
 * Regression (2.12.0 review): the audit entries of inventories (API-4), the
 * parse cache (OPS-1), the rollback with rules that inventories name
 * (OPS-3), a save that names a rule or project deleted meanwhile (LOCKS-1)
 * and inventories whose content is INI (API-5) are as the docs say; each
 * testRegression of them fails on the docs from before the review's fixes.
 */
class InventoryApprovalBehaviourDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /**
     * The columns of inventory that no user sets as a field of it:
     * bookkeeping, the cache of Parse Inventory, its creator, and the
     * approval rule (InventoryRuleAssignment).
     */
    private const NOT_FIELDS = ['id', 'approval_rule_id', 'parsed_hosts', 'parsed_error', 'parsed_at', 'created_by', 'created_at', 'updated_at'];

    /** The fields only admins change on a gated inventory, in the words of the docs. */
    private const FIELD_WORDS = [
        'inventory_type' => 'type',
        'content' => 'content',
        'source_path' => 'source path',
        'project_id' => 'project',
    ];

    /** The built-in roles, in the columns of the table. */
    private const ROLES = ['viewer', 'operator', 'admin'];

    /** The INI content of the seeded selftest and demo inventories (m000020, m000035). */
    private const SEEDED_INI = "[local]\nlocalhost ansible_connection=local\n";

    /**
     * The controller actions that need the permission of each row of the
     * table, by the permission; approval-rule.update is asked by the
     * services instead (ApprovalRuleAssignmentPolicy::PERMISSION).
     */
    private const ACTIONS = [
        'inventory.view' => [[InventoryController::class, 'view'], [InventoryController::class, 'index'], [InventoryController::class, 'parse-hosts']],
        'inventory.create' => [[InventoryController::class, 'create']],
        'inventory.update' => [[InventoryController::class, 'update']],
        'inventory.delete' => [[InventoryController::class, 'delete']],
        'approval.decide' => [[ApprovalController::class, 'approve'], [ApprovalController::class, 'reject']],
    ];

    /**
     * Every column of inventory but the name and the description is locked
     * on a gated inventory (GatedInventoryLock denies by default), so the
     * docs name every one of them; a new column fails here until they name
     * it.
     */
    public function testTheDocsNameEveryFieldOnlyAdminsChange(): void
    {
        $this->assertSame(['name', 'description'], GatedInventoryLock::OPEN_ATTRIBUTES, 'The docs say: but not its name and description.');
        $columns = Inventory::getTableSchema()->columnNames;
        $locked = array_values(array_diff($columns, GatedInventoryLock::OPEN_ATTRIBUTES, self::NOT_FIELDS));
        $named = array_keys(self::FIELD_WORDS);
        sort($locked);
        sort($named);
        $this->assertSame($named, $locked, 'The docs name every field of an inventory that only admins change.');
        $safe = (new Inventory())->safeAttributes();
        foreach (['approval_rule_id', 'created_by'] as $column) {
            $this->assertNotContains($column, $safe, "{$column} is no field a form or a body sets.");
        }

        $item = $this->item('Only admins change a gated inventory.');
        $lists = [
            'updating.md' => $this->between($item, 'change a gated inventory: its ', ', and any field'),
            'updating.md, on the form' => $this->between($item, 'the form disables its ', '. The save'),
            'troubleshooting.md' => $this->between(
                $this->flat('docs/troubleshooting.md', '## Saving an inventory is refused: it has an approval rule'),
                'Its type, content',
                ' count.'
            ),
        ];
        foreach ($lists as $where => $list) {
            foreach (self::FIELD_WORDS as $word) {
                $this->assertMentions($word, $list, $where);
            }
        }
        $this->assertMentions('but not its name and description, which stay open to everyone who may change the inventory', $item, 'updating.md');
        $this->assertSame(
            ['name', 'description', 'inventory_type', 'content', 'source_path', 'project_id'],
            InventoryWriteService::TRACKED_FIELDS,
            'The docs name the fields the audit entries track.'
        );
        $this->assertMentions(
            'as `changed_fields` (from `' . implode('`, `', array_slice(InventoryWriteService::TRACKED_FIELDS, 0, -1)) . '` and `project_id`, never their values)',
            $this->item('Approval requests say what required them.'),
            'updating.md'
        );
    }

    /**
     * regression (2.12.0 review, API-4): the docs said that
     * inventory.deleted records the fields that changed, as
     * inventory.created and inventory.updated do; it records none. Each
     * entry has the keys the docs name: inventory.created the name, type
     * and project and, as changed_fields, the fields that have a value;
     * inventory.updated the name and the fields that changed;
     * inventory.deleted the name and type, and no changed_fields; each the
     * rule as approval_rule where it changes (the rule a new inventory gets
     * and the rule a deleted one had included), and the source. Fails on
     * the docs from before.
     */
    public function testRegressionTheAuditEntriesOfAnInventoryHaveTheKeysTheDocsName(): void
    {
        $admin = $this->superadmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $writer = $this->writer();
        $inventory = $this->staticInventory('all: {hosts: {web1: }}');
        $inventory->name = 'docs-audit-' . uniqid('', true);
        $this->assertTrue($writer->create($inventory, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$admin->id, InventoryWriteService::SOURCE_API));
        $id = (int)$inventory->id;
        $stored = $this->storedInventory($id);
        $stored->description = 'docs';
        $this->assertTrue($writer->update($stored, [], (int)$admin->id, InventoryWriteService::SOURCE_WEB));
        $writer->delete($this->storedInventory($id), (int)$admin->id, InventoryWriteService::SOURCE_API);
        $this->assertNull(Inventory::findOne($id), 'The inventory is deleted.');

        $entries = $this->auditEntries($id);
        $this->assertSame([AuditLog::ACTION_INVENTORY_CREATED, AuditLog::ACTION_INVENTORY_UPDATED, AuditLog::ACTION_INVENTORY_DELETED], array_keys($entries));
        $ruleOf = ['id' => (int)$rule->id, 'name' => (string)$rule->name];
        $created = $entries[AuditLog::ACTION_INVENTORY_CREATED];
        $this->assertSame(['name', 'type', 'project_id', 'changed_fields', 'approval_rule', 'source'], array_keys($created));
        $this->assertSame(['name', 'inventory_type', 'content'], $created['changed_fields'], 'The fields that have a value.');
        $this->assertSame(['from' => null, 'to' => $ruleOf], $created['approval_rule'], 'The rule a new inventory gets.');
        $updated = $entries[AuditLog::ACTION_INVENTORY_UPDATED];
        $this->assertSame(['name', 'changed_fields', 'source'], array_keys($updated), 'No rule change, no approval_rule.');
        $this->assertSame(['description'], $updated['changed_fields'], 'The fields that changed.');
        $deleted = $entries[AuditLog::ACTION_INVENTORY_DELETED];
        $this->assertArrayNotHasKey('changed_fields', $deleted);
        $this->assertSame(['name', 'type', 'approval_rule', 'source'], array_keys($deleted));
        $this->assertSame(['from' => $ruleOf, 'to' => null], $deleted['approval_rule'], 'The rule a deleted inventory had.');
        $this->assertSame(['api', 'web', 'api'], array_column($entries, 'source'));

        $item = $this->item('Approval requests say what required them.');
        $expected = [
            '`inventory.created` records the `name`, `type` and `project_id` of the new inventory, and the fields that have a value; `inventory.updated` records the `name`, and the fields that changed.',
            '`inventory.deleted` records the `name` and `type`, and no `changed_fields`.',
            'All three record a change of the rule as `approval_rule` `{"from": ..., "to": ...}` with each rule\'s `id` and `name`, the rule a new inventory gets and the rule a deleted one had included, and where the change came from (`source`: `web` or `api`).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'updating.md');
        }
        $this->assertStringNotContainsString('and `inventory.deleted` record the fields that changed', $item, 'inventory.deleted records no changed_fields.');
    }

    /**
     * The table of who may do what holds the permissions the built-in roles
     * hold, as the migrations seeded them in the test database, and each
     * action needs the permission its row names.
     */
    public function testTheRoleTableIsWhatTheRolesHoldAndTheCodeAsks(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $rows = $this->tableRows();
        $this->assertSame(['inventory.view', 'inventory.create', 'inventory.update', 'inventory.delete', 'approval-rule.update', 'approval.decide'], array_column($rows, 'permission'));
        foreach ($rows as $row) {
            foreach (self::ROLES as $index => $role) {
                $holds = array_key_exists($row['permission'], $auth->getPermissionsByRole($role));
                $this->assertSame($row['roles'][$index], $holds ? 'yes' : 'no', "{$role}: {$row['action']} ({$row['permission']})");
            }
            foreach (self::ACTIONS[$row['permission']] ?? [] as [$class, $action]) {
                $this->assertContains($row['permission'], $this->rolesOf($class, $action), "{$class} {$action} needs {$row['permission']}.");
            }
        }
        $this->assertSame('approval-rule.update', ApprovalRuleAssignmentPolicy::PERMISSION, 'The rule and the lock ask approval-rule.update.');
        $this->assertNotContains('inventory.delete', array_keys($auth->getPermissionsByRole('operator')), 'Operators do not delete inventories, as the docs say.');
        $this->assertMentions(
            'The operator role does not hold `inventory.delete`, so the refusal to delete a gated inventory (see **Only admins change a gated inventory**) meets only custom roles that hold it without `approval-rule.update`.',
            $this->item('Who may do what.'),
            'updating.md'
        );
    }

    /**
     * What required a request takes the values of the code, the column and
     * the spec; a job template's gate names two of them.
     */
    public function testTheRequiredByValuesAreTheCodes(): void
    {
        $this->assertSame([ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, ApprovalRequest::REQUIRED_BY_INVENTORY, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP], ApprovalRequest::REQUIRED_BY_VALUES);
        $this->assertSame(['job_template', 'inventory', 'approval_step'], ApprovalRequest::REQUIRED_BY_VALUES);
        $this->assertMentions(
            'records what required it (`required_by`): `job_template` for the job template\'s own rule, also when its inventory has the same rule, `inventory` for the rule of its inventory, and `approval_step` for a workflow approval step',
            $this->item('Approval requests say what required them.'),
            'updating.md'
        );
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $enum = (array)($spec['components']['schemas']['ApprovalRequest']['properties']['required_by']['enum'] ?? []);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_VALUES, array_values(array_filter($enum, 'is_string')), 'The spec lists the values, and null before the migration.');
        $this->assertSame([JobTemplateGate::SOURCE_JOB_TEMPLATE, JobTemplateGate::SOURCE_INVENTORY], ['job_template', 'inventory']);
        $column = $this->column('approval_request', 'required_by');
        $this->assertSame(['varchar', 'job_template', 'NO'], [$column['DATA_TYPE'], trim((string)$column['COLUMN_DEFAULT'], "'"), $column['IS_NULLABLE']]);
        $this->assertMentions(
            '(`approval_request.required_by`, `job_template` for every request it finds)',
            $this->item('Database and deployment.'),
            'updating.md'
        );
    }

    /**
     * The migration made what the docs say: the content of static
     * inventories as MEDIUMTEXT, which holds the 262,144 characters the
     * model takes, and the rule of an inventory with an index and a foreign
     * key that keeps a rule in use from being deleted.
     */
    public function testTheSchemaIsWhatTheDocsSay(): void
    {
        $this->assertSame('mediumtext', $this->column('inventory', 'content')['DATA_TYPE']);
        $this->assertSame(262144, $this->contentMaximum(), 'The docs say: the 262,144 characters that the form and the API take.');
        $rule = $this->column('inventory', 'approval_rule_id');
        $this->assertSame(['int', 'YES'], [$rule['DATA_TYPE'], $rule['IS_NULLABLE']]);
        $key = \Yii::$app->db->createCommand(
            'SELECT REFERENCED_TABLE_NAME AS referenced, DELETE_RULE AS on_delete, UPDATE_RULE AS on_update FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :name',
            [':name' => 'fk-inventory-approval_rule']
        )->queryOne();
        $this->assertSame(
            ['referenced' => \Yii::$app->db->getSchema()->getRawTableName('{{%approval_rule}}'), 'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE'],
            $key,
            'A rule an inventory uses cannot be deleted.'
        );
        $index = \Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name',
            [':table' => \Yii::$app->db->getSchema()->getRawTableName('{{%inventory}}'), ':name' => 'idx-inventory-approval_rule']
        )->queryScalar();
        $this->assertGreaterThan(0, (int)$index);
        $deployment = $this->item('Database and deployment.');
        $this->assertMentions(
            'It stores the content of static inventories, and the parsed hosts of **Parse Inventory** (`inventory.parsed_hosts`), as MEDIUMTEXT, where they were TEXT',
            $deployment,
            'updating.md'
        );
        $this->assertMentions('(`inventory.approval_rule_id`, with an index and a foreign key, so that a rule an inventory uses cannot be deleted)', $deployment, 'updating.md');
        $this->assertMentions(
            'The content of a static inventory is stored as MEDIUMTEXT, so the 262,144 characters that the form and the API take fit. The column was TEXT, which holds 65,535 bytes',
            $this->item('Fixes that change behaviour:'),
            'updating.md'
        );
    }

    /**
     * regression (2.12.0 review, OPS-1): Parse Inventory kept its result in
     * a TEXT column, so a result longer than 65,535 bytes as JSON ended in a
     * server error, and no doc said so. The migration stores it as
     * MEDIUMTEXT, which holds 16,777,215 bytes (the most InventoryService
     * keeps), and a longer result is kept as PARSED_TOO_LARGE: the docs say
     * both, and quote the text. Fails on the docs from before.
     */
    public function testRegressionTheParsedHostsAreStoredAsTheDocsSay(): void
    {
        $column = $this->column('inventory', 'parsed_hosts');
        $this->assertSame(['mediumtext', 'YES', 16777215], [$column['DATA_TYPE'], $column['IS_NULLABLE'], (int)$column['CHARACTER_OCTET_LENGTH']]);
        $this->assertSame(16777215, (new \ReflectionClassConstant(InventoryService::class, 'CACHE_MAX_BYTES'))->getValue(), 'InventoryService keeps what the column holds.');

        $fix = $this->itemUnit('Fixes that change behaviour:', '- The parsed hosts that **Parse Inventory** keeps');
        $expected = [
            '- The parsed hosts that **Parse Inventory** keeps for the page of an inventory (`inventory.parsed_hosts`) are stored as MEDIUMTEXT too. The column was TEXT, which holds 65,535 bytes:',
            'ended in a server error on a database in strict mode, as the stock one is. A parse longer than MEDIUMTEXT holds, 16,777,215 bytes, is shown to whoever runs **Parse Inventory**, but not kept: until the next parse, the page of the inventory says `'
                . sprintf(InventoryService::PARSED_TOO_LARGE, 17000000) . '`',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $fix, 'updating.md');
        }
        $this->assertMentions(
            'and clears the parsed hosts of **Parse Inventory** where they are longer than TEXT holds:',
            $this->item('Database and deployment.'),
            'updating.md'
        );
    }

    /**
     * regression (2.12.0 review, OPS-3): the rollback did not say that
     * 2.11.1 cannot delete an approval rule that an inventory still names.
     * The database refuses that delete, whatever the code that sends it (the
     * foreign key of m000088, ON DELETE RESTRICT); once the inventory's rule
     * is removed, the rule is deleted. The rollback of the section and the
     * Rollback steps say so, and what to do before 2.11.1 runs. Fails on
     * the docs from before.
     */
    public function testRegressionTheRollbackSaysThatARuleAnInventoryNamesCannotBeDeleted(): void
    {
        $user = $this->createUser('docs-rollback');
        $rule = $this->createApprovalRule((int)$user->id);
        $inventory = $this->createInventory((int)$user->id);
        Inventory::updateAll([InventoryRuleAssignment::FIELD => (int)$rule->id], ['id' => (int)$inventory->id]);
        $refusal = null;
        try {
            $this->deleteRule((int)$rule->id);
        } catch (IntegrityException $e) {
            $refusal = DbErrorSummary::of($e);
        }
        $this->assertSame([IntegrityException::class, '23000', '1451'], $refusal, 'The database refuses to delete a rule that an inventory names.');
        $this->assertNotNull(ApprovalRule::findOne((int)$rule->id));
        Inventory::updateAll([InventoryRuleAssignment::FIELD => null], ['id' => (int)$inventory->id]);
        $this->assertSame(1, $this->deleteRule((int)$rule->id), 'Once no inventory names it, the rule is deleted.');

        $expected = [
            'But 2.11.1 cannot delete an approval rule that an inventory still names: the database refuses the delete, and 2.11.1, which does not count inventories, says that the rule is not in use. Nor can 2.11.1 take a rule off an inventory.',
            'So before you deploy 2.11.1, once such job templates have rules of their own, either remove the rules of the inventories in 2.12.0 (`— None —` under **Approval** on their edit forms, or `"approval_rule_id": null`), or revert the migration.',
            'It removes the approval rules of inventories and their foreign key, naming the inventories',
            'Once 2.11.1 runs, its `migrate/down` cannot revert this migration, which 2.11.1 does not have: deploy 2.12.0 again to revert it.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $this->item('Database and deployment.'), 'Database and deployment');
        }
        $this->assertMentions(
            'but ignores the approval rules of inventories, and cannot delete an approval rule that an inventory still names. Remove the rules of the inventories, or revert the migration, before you deploy 2.11.1: once it runs, its `migrate/down` cannot revert `m000088`.',
            $this->flat('docs/updating.md', '## Rollback'),
            'The Rollback steps of updating.md'
        );
    }

    /**
     * regression (2.12.0 review, LOCKS-1): the known limit said that an
     * inventory save naming a rule or a project deleted at that moment
     * meets a lock conflict and usually ends with the error that it does
     * not exist; the database refused it with its foreign key instead (a
     * server error), which the save now makes once more. The item says so,
     * and quotes the errors as the model words them for a rule and a
     * project that no longer exist. Fails on the docs from before.
     */
    public function testRegressionTheKnownLimitQuotesWhatASaveNamingADeletedRuleOrProjectEndsWith(): void
    {
        $user = $this->createUser('docs-gone');
        $rule = $this->createApprovalRule((int)$user->id);
        $project = $this->createProject((int)$user->id);
        $inventory = $this->staticInventory('all: {hosts: {web1: }}');
        $inventory->setAttribute(InventoryRuleAssignment::FIELD, (int)$rule->id);
        $inventory->project_id = (int)$project->id;
        $this->assertTrue($inventory->validate(), (string)json_encode($inventory->getErrors()));
        $this->deleteRule((int)$rule->id);
        \Yii::$app->db->createCommand()->delete('{{%project}}', ['id' => (int)$project->id])->execute();

        $this->assertFalse($inventory->validate());
        $this->assertCount(1, $inventory->getErrors(InventoryRuleAssignment::FIELD));
        $this->assertCount(1, $inventory->getErrors('project_id'));
        $ruleError = (string)$inventory->getFirstError(InventoryRuleAssignment::FIELD);
        $projectError = (string)$inventory->getFirstError('project_id');
        $this->assertSame(Inventory::APPROVAL_RULE_NOT_FOUND, $ruleError);
        $this->assertStringEndsWith('does not exist.', $projectError);

        $item = $this->itemUnit('Known limits.', '- An approval rule or a project deleted at the very moment an inventory save names it');
        $this->assertMentions(
            '- An approval rule or a project deleted at the very moment an inventory save names it: the database refuses the save, or the save meets a lock conflict, and it is made once more. It then ends with `'
                . $ruleError . '`, or `' . $projectError . '`, with nothing written. A second refusal or conflict in a row ends the save in a server error with nothing written, and saving again works.',
            $item,
            'updating.md'
        );
        $this->assertStringNotContainsString('usually ends', $item, 'The save made once more ends with the error.');
    }

    /**
     * Regression (2.12.0 review, LOCKS-4): the known limits named the
     * inventory save only. A job template save that names an approval rule
     * or a project deleted at that very moment ends in a server error (the
     * foreign key, or a deadlock with the cascade of the project delete),
     * as before 2.12.0; saving again shows the errors the docs quote, which
     * the model gives for a rule or a project that is gone. An inventory
     * deleted at that moment is reported at once (LOCKS-5).
     */
    public function testRegressionTheDocsSayWhatAJobTemplateSaveShowsWhenItsRuleOrProjectIsGone(): void
    {
        $user = $this->createUser('docs-template-gone');
        $rule = $this->createApprovalRule((int)$user->id);
        $project = $this->createProject((int)$user->id);
        $template = new JobTemplate();
        $template->project_id = (int)$project->id;
        $template->approval_rule_id = (int)$rule->id;
        $this->assertTrue($template->validate(['project_id', 'approval_rule_id']), (string)json_encode($template->getErrors()));
        $this->deleteRule((int)$rule->id);
        \Yii::$app->db->createCommand()->delete('{{%project}}', ['id' => (int)$project->id])->execute();

        $this->assertFalse($template->validate(['project_id', 'approval_rule_id']));
        $ruleError = (string)$template->getFirstError('approval_rule_id');
        $projectError = (string)$template->getFirstError('project_id');
        $this->assertSame('The selected approval rule does not exist.', $ruleError);
        $this->assertSame('The selected project does not exist.', $projectError);

        $item = $this->itemUnit('Known limits.', '- An approval rule or a project deleted at the very moment a job template save names it');
        $this->assertMentions(
            '- An approval rule or a project deleted at the very moment a job template save names it ends the save in a server error with nothing written, as before 2.12.0, and saving again shows `'
                . $ruleError . '` or `' . $projectError . '`. An inventory deleted at that moment is reported as `'
                . JobTemplateRuleAssignment::INVENTORY_NOT_FOUND . '` at once.',
            $item,
            'updating.md'
        );
    }

    /**
     * regression (2.12.0 review, API-5): a static inventory whose content is
     * INI, as the seeded selftest and demo inventories are, could not be
     * renamed or given a rule: every save checked its content as YAML.
     * Through the write path of the form and the API, an admin renames it
     * and gives it a rule, its content as it was; content that changes must
     * still be a YAML mapping, and new content is refused with the text the
     * docs quote. The docs say so where an inventory is given a rule.
     */
    public function testRegressionAStaticInventoryWithIniContentIsRenamedAndGivenARuleAsTheDocsSay(): void
    {
        $admin = $this->superadmin();
        $rule = $this->createApprovalRule((int)$admin->id);
        $inventory = $this->createInventory((int)$admin->id);
        Inventory::updateAll(['content' => self::SEEDED_INI], ['id' => (int)$inventory->id]);
        $stored = $this->storedInventory((int)$inventory->id);
        $renamed = 'docs-ini-' . uniqid('', true);
        $stored->name = $renamed;

        $saved = $this->writer()->update($stored, [InventoryRuleAssignment::FIELD => (int)$rule->id], (int)$admin->id, InventoryWriteService::SOURCE_API);

        $this->assertTrue($saved, (string)json_encode($stored->getErrors()));
        $after = $this->storedInventory((int)$inventory->id);
        $this->assertSame([$renamed, (int)$rule->id, self::SEEDED_INI], [$after->name, (int)$after->getAttribute(InventoryRuleAssignment::FIELD), $after->content]);
        $after->content = "[local]\nlocalhost\n";
        $this->assertFalse($this->writer()->update($after, [], (int)$admin->id, InventoryWriteService::SOURCE_API), 'Changed content must be a YAML mapping.');
        $this->assertStringStartsWith('Invalid YAML: ', (string)$after->getFirstError('content'));
        $new = $this->staticInventory(self::SEEDED_INI);
        $this->assertFalse($new->validate(['content']));
        $error = (string)$new->getFirstError('content');
        $this->assertSame('Invalid YAML: Unable to parse at line 2 (near "localhost ansible_connection=local").', $error);

        $item = $this->item('Giving an inventory a rule.');
        $expected = [
            'A static inventory whose content is INI, as the seeded selftest and demo inventories are, can be given a rule, and renamed, on its edit form and through the API like any other: its content is checked only when it is new or changes, or when its type changes.',
            'Content that you change must be a YAML mapping; INI is refused, as for a new inventory with the content of the seeded selftest inventory: `' . $error . '`',
            'Up to 2.11.1 every save of such an inventory was refused so, also one that changed only its name.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'updating.md');
        }
        $this->assertMentions(
            '- A static inventory whose content is INI can be saved with a new name, a new description or an approval rule, where every save of it was refused as invalid YAML; content that changes must still be a YAML mapping (see **Giving an inventory a rule**).',
            $this->item('Fixes that change behaviour:'),
            'updating.md'
        );
    }

    /**
     * The hosts the docs give for the localhost that the claim gave a job
     * without hosts are static inventories the model takes: the form and
     * the API take new or changed content only as a YAML mapping, so the
     * text the claim used, and the one the seeded selftest inventory holds,
     * would be refused.
     */
    public function testTheHostsTheDocsGiveForLocalhostAreAStaticInventoryTheModelTakes(): void
    {
        foreach (InventoryApprovalDocsContractTest::LOCALHOST_CONTENTS as $content) {
            $inventory = $this->staticInventory($content);
            $this->assertTrue($inventory->validate(['content']), $content . ': ' . (string)json_encode($inventory->getErrors()));
            $this->assertTrue($inventory->targetsLocalhost(), "{$content} names the runner itself.");
        }
        foreach (["localhost\n", 'localhost ansible_connection=local'] as $text) {
            $inventory = $this->staticInventory($text);
            $this->assertFalse($inventory->validate(['content']), "{$text} is no static inventory the form takes.");
            $this->assertSame(['Inventory must be a YAML mapping (not a scalar).'], $inventory->getErrors('content'));
        }
        $this->assertFalse($this->staticInventory(self::SEEDED_INI)->validate(['content']), 'Nor is the text of the seeded selftest inventory.');
    }

    /**
     * The rows of the table under **Who may do what.**: action, permission
     * and yes or no for viewer, operator and admin.
     *
     * @return list<array{action: string, permission: string, roles: list<string>}>
     */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->units('docs/updating.md', InventoryApprovalDocsContractTest::UPDATE_SECTION) as $unit) {
            if (preg_match('/^\| (.+?) \| `([a-z.-]+)` \| (yes|no) \| (yes|no) \| (yes|no) \|$/', $unit, $match) === 1) {
                $rows[] = ['action' => $match[1], 'permission' => $match[2], 'roles' => [$match[3], $match[4], $match[5]]];
            }
        }
        $this->assertNotEmpty($rows, 'The section has the table.');

        return $rows;
    }

    /**
     * The roles of the access rules of $class that allow the action $id.
     *
     * @param class-string $class
     * @return list<string>
     */
    private function rolesOf(string $class, string $id): array
    {
        $controller = new \ReflectionClass($class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $roles = [];
        foreach ($rules as $rule) {
            if (in_array($id, (array)($rule['actions'] ?? []), true) && ($rule['allow'] ?? false) === true) {
                $roles = array_merge($roles, array_map('strval', (array)($rule['roles'] ?? [])));
            }
        }

        return $roles;
    }

    /**
     * The item of the section under the bold title $title: its paragraph
     * and everything after it up to the next bold title, on one line.
     */
    private function item(string $title): string
    {
        $item = [];
        foreach ($this->units('docs/updating.md', InventoryApprovalDocsContractTest::UPDATE_SECTION) as $unit) {
            if ($item === [] && !str_starts_with($unit, '**' . $title . '**')) {
                continue;
            }
            if ($item !== [] && preg_match('/^\*\*[^*]+[.:]\*\*/', $unit) === 1) {
                break;
            }
            $item[] = $unit;
        }
        $this->assertNotEmpty($item, "The section has no item {$title}");

        return implode(' ', $item);
    }

    /**
     * The unit of the item $title that starts with $start, such as one of
     * its list items.
     */
    private function itemUnit(string $title, string $start): string
    {
        $found = false;
        foreach ($this->units('docs/updating.md', InventoryApprovalDocsContractTest::UPDATE_SECTION) as $unit) {
            $found = $found || str_starts_with($unit, '**' . $title . '**');
            if ($found && str_starts_with($unit, $start)) {
                return $unit;
            }
        }
        $this->fail("The item {$title} has no unit {$start}");
    }

    /**
     * An active superadmin, who may assign rules and change gated
     * inventories.
     */
    private function superadmin(): User
    {
        $user = $this->createUser('docs-inv-admin');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    private function writer(): InventoryWriteService
    {
        $writer = \Yii::$app->get('inventoryWriteService');
        $this->assertInstanceOf(InventoryWriteService::class, $writer);

        return $writer;
    }

    /**
     * The inventory $id as stored, as the form and the API load it.
     */
    private function storedInventory(int $id): Inventory
    {
        $inventory = Inventory::findOne($id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    /**
     * The audit entries of inventory #$id: their metadata by action.
     *
     * @return array<string, array<string, mixed>>
     */
    private function auditEntries(int $id): array
    {
        $entries = [];
        foreach (AuditLog::find()->where(['object_type' => 'inventory', 'object_id' => $id])->orderBy(['id' => SORT_ASC])->all() as $row) {
            /** @var AuditLog $row */
            $metadata = json_decode((string)$row->metadata, true);
            $this->assertIsArray($metadata);
            $entries[(string)$row->action] = $metadata;
        }

        return $entries;
    }

    /**
     * Deletes approval rule #$id with a plain DELETE, as any version of the
     * code sends it; the number of rows deleted.
     */
    private function deleteRule(int $id): int
    {
        return \Yii::$app->db->createCommand()->delete('{{%approval_rule}}', ['id' => $id])->execute();
    }

    /**
     * The column $column of $table in information_schema.
     *
     * @return array<string, mixed>
     */
    private function column(string $table, string $column): array
    {
        $row = \Yii::$app->db->createCommand(
            'SELECT DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            [':table' => \Yii::$app->db->getSchema()->getRawTableName('{{%' . $table . '}}'), ':column' => $column]
        )->queryOne();
        $this->assertIsArray($row, "{$table}.{$column} exists.");

        return $row;
    }

    /**
     * The most characters the model takes as the content of an inventory.
     */
    private function contentMaximum(): int
    {
        foreach ((new Inventory())->rules() as $rule) {
            if (in_array('content', (array)$rule[0], true) && ($rule[1] ?? null) === 'string') {
                return (int)($rule['max'] ?? 0);
            }
        }
        $this->fail('Inventory has no string rule for its content.');
    }

    private function staticInventory(string $content): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = 'docs-localhost';
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = $content;

        return $inventory;
    }
}
