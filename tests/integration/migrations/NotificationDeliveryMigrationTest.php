<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\components\DbErrorSummary;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;
use yii\db\ColumnSchema;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Query;

/**
 * m000091_000000_notification_delivery_and_options: the options of a
 * notification template (level, workflow_scope, channel_state, and
 * delivers_as with a foreign key to user, backfilled from created_by; events
 * widened to VARCHAR(1024)), its workflow filter
 * (notification_template_workflow_template), its delivery outbox
 * (notification_delivery) and the threads of its status posts
 * (notification_thread).
 *
 * DDL commits implicitly in MySQL/MariaDB. The schema the real migration
 * produced in the test database is checked as it is, with writes inside
 * the test's transaction. up() and down() run for real in the scratch
 * database ansilume_migrate_test (bin/tests-phpunit.sh grants it; without
 * the grant those tests are skipped), on tables under a prefix of their
 * own: other migration tests use the same database.
 */
class NotificationDeliveryMigrationTest extends DbTestCase
{
    private const SCRATCH_DB = 'ansilume_migrate_test';

    /** The scratch tables, children first. */
    private const SCRATCH_TABLES = [
        'notification_thread',
        'notification_delivery',
        'notification_template_workflow_template',
        'notification_template',
        'workflow_job',
        'workflow_template',
        'user',
    ];

    /**
     * The columns of the new tables in their order: the type ('int' for an
     * integer, else the database type), whether it is unsigned, whether it
     * allows NULL, and its default.
     *
     * @var array<string, array<string, array{0: string, 1: bool, 2: bool, 3: mixed}>>
     */
    private const NEW_TABLES = [
        'notification_template_workflow_template' => [
            'notification_template_id' => ['int', true, false, null],
            'workflow_template_id' => ['int', true, false, null],
        ],
        'notification_delivery' => [
            'id' => ['int', true, false, null],
            'notification_template_id' => ['int', true, false, null],
            'event' => ['varchar(64)', false, false, null],
            'kind' => ['varchar(16)', false, false, 'message'],
            'workflow_job_id' => ['int', true, true, null],
            'job_id' => ['int', true, true, null],
            'approval_request_id' => ['int', true, true, null],
            'variables' => ['text', false, false, null],
            'status' => ['varchar(16)', false, false, 'pending'],
            'attempts' => ['int', true, false, 0],
            'next_attempt_at' => ['int', false, false, null],
            'claimed_at' => ['int', false, true, null],
            'last_error' => ['varchar(500)', false, true, null],
            'remote_id' => ['varchar(64)', false, true, null],
            'created_at' => ['int', false, false, null],
            'updated_at' => ['int', false, false, null],
            'finished_at' => ['int', false, true, null],
        ],
        'notification_thread' => [
            'id' => ['int', true, false, null],
            'notification_template_id' => ['int', true, false, null],
            'workflow_job_id' => ['int', true, false, null],
            'root_post_id' => ['varchar(64)', false, false, null],
            'channel_id' => ['varchar(64)', false, false, null],
            'server' => ['varchar(255)', false, false, null],
            'created_at' => ['int', false, false, null],
            'updated_at' => ['int', false, false, null],
        ],
    ];

    /**
     * The indexes of the new tables and of notification_template.delivers_as:
     * table => index => [columns in their order, unique].
     *
     * @var array<string, array<string, array{0: list<string>, 1: bool}>>
     */
    private const INDEXES = [
        'notification_template' => [
            'idx-notification_template-delivers_as' => [['delivers_as'], false],
        ],
        'notification_template_workflow_template' => [
            'PRIMARY' => [['notification_template_id', 'workflow_template_id'], true],
            'idx-ntwt-workflow_template' => [['workflow_template_id'], false],
        ],
        'notification_delivery' => [
            'PRIMARY' => [['id'], true],
            'idx-notification_delivery-status-next_attempt' => [['status', 'next_attempt_at'], false],
            'idx-notification_delivery-template-created' => [['notification_template_id', 'created_at'], false],
            'idx-notification_delivery-workflow_job' => [['workflow_job_id', 'id'], false],
        ],
        'notification_thread' => [
            'PRIMARY' => [['id'], true],
            'idx-notification_thread-template-workflow_job' => [['notification_template_id', 'workflow_job_id'], true],
            'idx-notification_thread-workflow_job' => [['workflow_job_id'], false],
        ],
    ];

    /**
     * The foreign keys: table => key => [column, referenced table, ON DELETE];
     * every one updates with CASCADE and references id.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string}>>
     */
    private const FOREIGN_KEYS = [
        'notification_template' => [
            'fk-notification_template-delivers_as' => ['delivers_as', 'user', 'RESTRICT'],
        ],
        'notification_template_workflow_template' => [
            'fk-ntwt-notification_template' => ['notification_template_id', 'notification_template', 'CASCADE'],
            'fk-ntwt-workflow_template' => ['workflow_template_id', 'workflow_template', 'CASCADE'],
        ],
        'notification_delivery' => [
            'fk-notification_delivery-template' => ['notification_template_id', 'notification_template', 'CASCADE'],
            'fk-notification_delivery-workflow_job' => ['workflow_job_id', 'workflow_job', 'CASCADE'],
        ],
        'notification_thread' => [
            'fk-notification_thread-template' => ['notification_template_id', 'notification_template', 'CASCADE'],
            'fk-notification_thread-workflow_job' => ['workflow_job_id', 'workflow_job', 'CASCADE'],
        ],
    ];

    /**
     * The columns that later migrations add to the new tables, which the
     * test database has too: table => columns. m000093 adds the project and
     * the job template a delivery's event is about
     * (NotificationDeliveryRefsMigrationTest).
     *
     * @var array<string, list<string>>
     */
    private const LATER_COLUMNS = [
        'notification_delivery' => ['project_id', 'job_template_id'],
    ];

    private ?Connection $scratch = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000091_000000_notification_delivery_and_options.php';
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

    public function testTheTestDatabaseHasTheMigratedSchema(): void
    {
        $this->assertMigratedSchema(\Yii::$app->db, true);
    }

    public function testUpChangesNothingOnAMigratedSchema(): void
    {
        $migration = $this->recordingMigration(\Yii::$app->db);

        $output = $this->quietly(static fn () => $migration->up());

        $this->assertSame([], $migration->calls);
        $this->assertSame('', $output);
    }

    /**
     * Deleting a template deletes its workflow filter, its deliveries and
     * its threads with it.
     */
    public function testDeletingATemplateDeletesItsFilterDeliveriesAndThreads(): void
    {
        $user = $this->createUser('m91-template');
        $template = $this->createNotificationTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $run = $this->createRun((int)$workflow->id, (int)$user->id);
        $this->insertFilter((int)$template->id, (int)$workflow->id);
        $this->insertDelivery((int)$template->id, $run);
        $this->insertThread((int)$template->id, $run);

        $template->delete();

        foreach (['notification_template_workflow_template', 'notification_delivery', 'notification_thread'] as $table) {
            $this->assertSame(0, $this->rowsOf($table, (int)$template->id), $table);
        }
        $this->assertTrue(WorkflowTemplate::find()->where(['id' => $workflow->id])->exists(), 'the workflow template stays');
    }

    /**
     * A workflow template that is deleted from the database (the app only
     * soft-deletes) leaves the filters it was in.
     */
    public function testAWorkflowTemplateDeletedFromTheDatabaseLeavesTheFilters(): void
    {
        $user = $this->createUser('m91-workflow');
        $template = $this->createNotificationTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $kept = $this->createWorkflowTemplate((int)$user->id);
        $this->insertFilter((int)$template->id, (int)$workflow->id);
        $this->insertFilter((int)$template->id, (int)$kept->id);

        WorkflowTemplate::deleteAll(['id' => $workflow->id]);

        $this->assertSame([(int)$kept->id], $this->filterOf((int)$template->id));
    }

    /**
     * Deleting a run deletes its deliveries and its threads; the template
     * stays.
     */
    public function testDeletingARunDeletesItsDeliveriesAndThreads(): void
    {
        $user = $this->createUser('m91-run');
        $template = $this->createNotificationTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $run = $this->createRun((int)$workflow->id, (int)$user->id);
        $other = $this->createRun((int)$workflow->id, (int)$user->id);
        $this->insertDelivery((int)$template->id, $run);
        $this->insertDelivery((int)$template->id, $other);
        $this->insertDelivery((int)$template->id, null);
        $this->insertThread((int)$template->id, $run);

        \Yii::$app->db->createCommand()->delete('{{%workflow_job}}', ['id' => $run])->execute();

        $this->assertSame(2, $this->rowsOf('notification_delivery', (int)$template->id), 'the other run and the message stay');
        $this->assertSame(0, $this->rowsOf('notification_thread', (int)$template->id));
        $this->assertTrue(NotificationTemplate::find()->where(['id' => $template->id])->exists());
    }

    /**
     * A user a template delivers as cannot be deleted, as a user who
     * created one cannot (UserDeletionService refuses with its message).
     */
    public function testAUserATemplateDeliversAsCannotBeDeleted(): void
    {
        $creator = $this->createUser('m91-creator');
        $identity = $this->createUser('m91-identity');
        $template = $this->createNotificationTemplate((int)$creator->id);
        NotificationTemplate::updateAll(['delivers_as' => $identity->id], ['id' => $template->id]);

        try {
            $identity->delete();
            $this->fail('expected the foreign key to restrict the delete');
        } catch (IntegrityException $e) {
            $this->assertSame('1451', DbErrorSummary::of($e)[2]);
        }

        $this->assertTrue(User::find()->where(['id' => $identity->id])->exists());
    }

    public function testATemplateCannotDeliverAsAUserWhoDoesNotExist(): void
    {
        $template = $this->createNotificationTemplate((int)$this->createUser('m91-unknown')->id);

        $this->expectException(IntegrityException::class);
        NotificationTemplate::updateAll(['delivers_as' => 987654321], ['id' => $template->id]);
    }

    public function testATemplateRunsAtMostOneThread(): void
    {
        $user = $this->createUser('m91-thread');
        $template = $this->createNotificationTemplate((int)$user->id);
        $run = $this->createRun((int)$this->createWorkflowTemplate((int)$user->id)->id, (int)$user->id);
        $this->insertThread((int)$template->id, $run);

        $this->expectException(IntegrityException::class);
        $this->insertThread((int)$template->id, $run);
    }

    // -- Up and down for real, in the scratch database -------------------------------

    /**
     * Every existing template delivers as its creator, at level all,
     * without a workflow filter; its events stay. The templates whose
     * creator is inactive are named: they deliver nothing until someone
     * saves them.
     */
    public function testUpMigratesTheSchemaOf2140(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $active = $this->insertRow($db, 'user', ['status' => 10]);
        $inactive = $this->insertRow($db, 'user', ['status' => 0]);
        $first = $this->insertTemplate($db, $active, 'job.failed,workflow.failed');
        $second = $this->insertTemplate($db, $inactive, 'job.failed');
        $third = $this->insertTemplate($db, $inactive, 'runner.offline');

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertSame(sprintf(\m000091_000000_notification_delivery_and_options::INACTIVE_IDENTITIES, "#{$second}, #{$third}"), $output);
        $this->assertSame(
            "    > Notification template(s) #{$second}, #{$third} deliver as their creator, whose account is inactive: "
            . "they deliver nothing until someone saves them.\n",
            $output
        );
        $this->assertMigratedSchema($db);
        foreach ([$first => $active, $second => $inactive, $third => $inactive] as $id => $creator) {
            $this->assertSame((string)$creator, (string)$this->value($db, 'notification_template', 'delivers_as', $id), "template #{$id}");
            $this->assertSame('all', $this->value($db, 'notification_template', 'level', $id));
            $this->assertSame('all', $this->value($db, 'notification_template', 'workflow_scope', $id));
            $this->assertNull($this->value($db, 'notification_template', 'channel_state', $id));
        }
        $this->assertSame('job.failed,workflow.failed', $this->value($db, 'notification_template', 'events', $first));

        $again = $this->recordingMigration($db);
        $this->assertSame('', $this->quietly(static fn () => $again->up()), 'a second run has nothing to do');
        $this->assertSame([], $again->calls, 'and sends no DDL');
    }

    /**
     * regression: the model accepted 1024 characters of events, but the
     * column held 512, so a template that listened to many events failed
     * with a server error (SQLSTATE 22001) instead of being saved.
     */
    public function testRegressionEventsTheModelAcceptsDidNotFitTheColumn(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $user = $this->insertRow($db, 'user', ['status' => 10]);
        $events = str_repeat('x', 1024);
        try {
            $this->insertTemplate($db, $user, $events);
            $this->fail('VARCHAR(512) holds 512 characters at most');
        } catch (\yii\db\Exception $e) {
            $this->assertSame('22001', DbErrorSummary::of($e)[1], 'refused as data too long');
        }

        $this->quietly(fn () => $this->migration($db)->up());

        $id = $this->insertTemplate($db, $user, $events, $user);
        $this->assertSame($events, $this->value($db, 'notification_template', 'events', $id));
    }

    /**
     * DDL commits implicitly, so a run can fail half way: here after
     * delivers_as was added, before its backfill finished, and after the
     * filter table was created, before its keys. The next container start
     * runs the migration again; it must finish the work, not fail, and keep
     * an identity that was set already.
     */
    public function testARetryFinishesWhatAFailedRunLeft(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $creator = $this->insertRow($db, 'user', ['status' => 10]);
        $other = $this->insertRow($db, 'user', ['status' => 10]);
        $backfilled = $this->insertTemplate($db, $creator, 'job.failed');
        $set = $this->insertTemplate($db, $creator, 'job.failed');
        $command = $db->createCommand();
        $command->addColumn('{{%notification_template}}', 'level', "VARCHAR(16) NOT NULL DEFAULT 'all' AFTER [[events]]")->execute();
        $command->addColumn('{{%notification_template}}', 'delivers_as', 'INT UNSIGNED NULL AFTER [[created_by]]')->execute();
        $command->update('{{%notification_template}}', ['delivers_as' => $other], ['id' => $set])->execute();
        $command->createTable('{{%notification_template_workflow_template}}', [
            'notification_template_id' => 'INT UNSIGNED NOT NULL',
            'workflow_template_id' => 'INT UNSIGNED NOT NULL',
            'PRIMARY KEY ([[notification_template_id]], [[workflow_template_id]])',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();

        $output = $this->quietly(fn () => $this->migration($db)->up());

        $this->assertSame('', $output, 'no inactive creator');
        $this->assertMigratedSchema($db);
        $this->assertSame((string)$creator, (string)$this->value($db, 'notification_template', 'delivers_as', $backfilled));
        $this->assertSame((string)$other, (string)$this->value($db, 'notification_template', 'delivers_as', $set), 'kept');
    }

    public function testAForeignKeyWithAnotherDeleteRuleIsReplaced(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $command = $db->createCommand();
        $command->dropForeignKey('fk-notification_template-delivers_as', '{{%notification_template}}')->execute();
        $command->addForeignKey('fk-notification_template-delivers_as', '{{%notification_template}}', 'delivers_as', '{{%user}}', 'id', 'CASCADE', 'CASCADE')->execute();
        $command->dropForeignKey('fk-notification_delivery-template', '{{%notification_delivery}}')->execute();
        $command->addForeignKey('fk-notification_delivery-template', '{{%notification_delivery}}', 'notification_template_id', '{{%notification_template}}', 'id', 'RESTRICT', 'CASCADE')->execute();

        $this->quietly(fn () => $this->migration($db)->up());

        $this->assertMigratedSchema($db);
    }

    public function testDownRestoresTheSchemaOf2140AndUpWorksAgain(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $user = $this->insertRow($db, 'user', ['status' => 10]);
        $id = $this->insertTemplate($db, $user, 'job.failed');
        $this->quietly(fn () => $this->migration($db)->up());

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame('', $output, 'nothing unsent, no option, no long events');
        $this->assertOldSchema($db);
        $this->assertSame('job.failed', $this->value($db, 'notification_template', 'events', $id));

        $again = $this->recordingMigration($db);
        $this->assertSame('', $this->quietly(static fn () => $again->down()), 'a second down has nothing to do');
        $this->assertSame([], $again->calls);

        $this->quietly(fn () => $this->migration($db)->up());
        $this->assertMigratedSchema($db);
        $this->assertSame((string)$user, (string)$this->value($db, 'notification_template', 'delivers_as', $id));
    }

    /**
     * Down says what an operator loses: the deliveries not sent yet, which
     * are never sent, and the workflow filters and levels, without which
     * the templates deliver every workflow event they listen to again; and
     * it keeps VARCHAR(1024) while some events need it. In this order.
     */
    public function testDownSaysWhatItDropsAndKeeps(): void
    {
        $db = $this->scratchDatabase();
        $this->createOldSchema($db);
        $this->quietly(fn () => $this->migration($db)->up());
        $user = $this->insertRow($db, 'user', ['status' => 10]);
        $plain = $this->insertTemplate($db, $user, 'job.failed', $user);
        $filtered = $this->insertTemplate($db, $user, 'workflow.failed', $user);
        $essential = $this->insertTemplate($db, $user, 'workflow.failed', $user);
        $long = $this->insertTemplate($db, $user, str_repeat('y', 513), $user);
        $db->createCommand()->update('{{%notification_template}}', ['workflow_scope' => 'selected'], ['id' => $filtered])->execute();
        $db->createCommand()->update('{{%notification_template}}', ['level' => 'essential'], ['id' => $essential])->execute();
        foreach (['pending', 'sending', 'delivered', 'failed', 'skipped', 'superseded', 'pending'] as $status) {
            $this->insertRow($db, 'notification_delivery', [
                'notification_template_id' => $plain,
                'event' => 'job.failed',
                'variables' => '{}',
                'status' => $status,
                'next_attempt_at' => 1700000000,
                'created_at' => 1700000000,
                'updated_at' => 1700000000,
            ]);
        }

        $output = $this->quietly(fn () => $this->migration($db)->down());

        $this->assertSame(
            sprintf(\m000091_000000_notification_delivery_and_options::UNSENT_DROPPED, 3)
            . sprintf(\m000091_000000_notification_delivery_and_options::OPTIONS_REMOVED, "#{$filtered}, #{$essential}")
            . sprintf(\m000091_000000_notification_delivery_and_options::EVENTS_KEPT, "#{$long}"),
            $output
        );
        $this->assertSame(
            "    > Dropped 3 notification delivery(ies) that were not delivered yet: they are never sent.\n"
            . "    > Removed the workflow filter or level of notification template(s) #{$filtered}, #{$essential}: "
            . "they deliver every workflow event they listen to again.\n"
            . "    > Kept notification_template.events as VARCHAR(1024): template(s) #{$long} list events longer than "
            . "512 characters, which VARCHAR(512) cannot store; older releases work with VARCHAR(1024).\n",
            $output
        );
        $this->assertSame('varchar(1024)', $this->column($db, '{{%notification_template}}', 'events')?->dbType, 'kept for the long events');
        $this->assertSame(513, strlen((string)$this->value($db, 'notification_template', 'events', $long)));
        $this->assertNull($this->column($db, '{{%notification_template}}', 'delivers_as'));
        $this->assertNull($db->getTableSchema('{{%notification_delivery}}', true));

        $again = $this->recordingMigration($db);
        $this->assertSame(
            sprintf(\m000091_000000_notification_delivery_and_options::EVENTS_KEPT, "#{$long}"),
            $this->quietly(static fn () => $again->down()),
            'a second down changes nothing and says again why VARCHAR(1024) stays'
        );
        $this->assertSame([], $again->calls);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The schema m000091 leaves; with $laterMigrations, the columns that
     * later migrations add (LATER_COLUMNS) are left out of the comparison.
     */
    private function assertMigratedSchema(Connection $db, bool $laterMigrations = false): void
    {
        $events = $this->column($db, '{{%notification_template}}', 'events');
        $this->assertNotNull($events, 'notification_template.events exists');
        $this->assertSame('varchar(1024)', $events->dbType);
        $this->assertFalse($events->allowNull);
        $this->assertSame('Comma-separated event names', $events->comment);

        $this->assertTemplateColumn($db, 'level', 'varchar(16)', false, 'all', 'events');
        $this->assertTemplateColumn($db, 'workflow_scope', 'varchar(16)', false, 'all', 'level');
        $this->assertTemplateColumn($db, 'channel_state', 'text', true, null, 'config');
        $deliversAs = $this->assertTemplateColumn($db, 'delivers_as', 'int', false, null, 'created_by');
        $this->assertTrue($deliversAs->unsigned);
        $this->assertSame('The user whose access decides what the template delivers: who last changed what or where it delivers', $deliversAs->comment);

        foreach (self::NEW_TABLES as $table => $columns) {
            $schema = $db->getTableSchema("{{%{$table}}}", true);
            $this->assertNotNull($schema, "{$table} exists");
            $later = $laterMigrations ? (self::LATER_COLUMNS[$table] ?? []) : [];
            $this->assertSame(array_keys($columns), array_values(array_diff($schema->getColumnNames(), $later)), "the columns of {$table}");
            foreach ($columns as $name => [$type, $unsigned, $allowNull, $default]) {
                $column = $schema->getColumn($name);
                $this->assertNotNull($column);
                $this->assertSame($type, $type === 'int' ? ($column->type === 'integer' ? 'int' : $column->type) : $column->dbType, "{$table}.{$name}");
                $this->assertSame($unsigned, $column->unsigned, "{$table}.{$name} unsigned");
                $this->assertSame($allowNull, $column->allowNull, "{$table}.{$name} null");
                $this->assertSame($default, $column->defaultValue, "{$table}.{$name} default");
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            $actual = array_intersect_key($this->indexesOf($db, $table), $indexes);
            ksort($actual);
            ksort($indexes);
            $this->assertSame($indexes, $actual, "the indexes of {$table}");
        }
        foreach (self::FOREIGN_KEYS as $table => $keys) {
            foreach ($keys as $name => [$column, $refTable, $onDelete]) {
                $this->assertSame(
                    ['column' => $column, 'table' => $db->getSchema()->getRawTableName("{{%{$refTable}}}"), 'refColumn' => 'id', 'delete' => $onDelete, 'update' => 'CASCADE'],
                    $this->foreignKey($db, $table, $name),
                    $name
                );
            }
        }
    }

    private function assertTemplateColumn(Connection $db, string $name, string $type, bool $allowNull, mixed $default, string $after): ColumnSchema
    {
        $column = $this->column($db, '{{%notification_template}}', $name);
        $this->assertNotNull($column, "notification_template.{$name} exists");
        $this->assertSame($type, $type === 'int' ? ($column->type === 'integer' ? 'int' : $column->type) : $column->dbType, $name);
        $this->assertSame($allowNull, $column->allowNull, "{$name} null");
        $this->assertSame($default, $column->defaultValue, "{$name} default");
        $this->assertSame($name, $this->columnAfter($db, '{{%notification_template}}', $after), "{$name} follows {$after}");

        return $column;
    }

    private function assertOldSchema(Connection $db): void
    {
        $events = $this->column($db, '{{%notification_template}}', 'events');
        $this->assertNotNull($events);
        $this->assertSame('varchar(512)', $events->dbType);
        $this->assertFalse($events->allowNull);
        $this->assertSame('Comma-separated event names', $events->comment);
        foreach (['level', 'workflow_scope', 'channel_state', 'delivers_as'] as $name) {
            $this->assertNull($this->column($db, '{{%notification_template}}', $name), "notification_template.{$name} is gone");
        }
        $this->assertSame([], array_intersect_key($this->indexesOf($db, 'notification_template'), self::INDEXES['notification_template']));
        $this->assertNull($this->foreignKey($db, 'notification_template', 'fk-notification_template-delivers_as'));
        foreach (array_keys(self::NEW_TABLES) as $table) {
            $this->assertNull($db->getTableSchema("{{%{$table}}}", true), "{$table} is gone");
        }
    }

    private function migration(Connection $db): \m000091_000000_notification_delivery_and_options
    {
        return new \m000091_000000_notification_delivery_and_options(['db' => $db, 'compact' => true]);
    }

    /**
     * The migration with every schema or data change recorded instead of
     * run.
     *
     * @return \m000091_000000_notification_delivery_and_options&object{calls: list<string>}
     */
    private function recordingMigration(Connection $db): \m000091_000000_notification_delivery_and_options
    {
        return new class (['db' => $db, 'compact' => true]) extends \m000091_000000_notification_delivery_and_options {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @param string $table
             * @param array<int|string, mixed> $columns
             * @param string|null $options
             */
            public function createTable($table, $columns, $options = null): void
            {
                $this->calls[] = "createTable {$table}";
            }

            /**
             * @param string $table
             */
            public function dropTable($table): void
            {
                $this->calls[] = "dropTable {$table}";
            }

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
     * The tables as 2.14.0 left them, reduced to the columns the migration
     * touches, places a column after or refers to.
     */
    private function createOldSchema(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $command = $db->createCommand();
        $command->createTable('{{%user}}', ['id' => $pk, 'status' => 'SMALLINT NOT NULL DEFAULT 10'], $options)->execute();
        $command->createTable('{{%workflow_template}}', ['id' => $pk, 'deleted_at' => 'INT NULL'], $options)->execute();
        $command->createTable('{{%workflow_job}}', ['id' => $pk, 'workflow_template_id' => 'INT UNSIGNED NOT NULL'], $options)->execute();
        $command->createTable('{{%notification_template}}', [
            'id' => $pk,
            'name' => 'VARCHAR(128) NOT NULL',
            'channel' => 'VARCHAR(32) NOT NULL',
            'config' => 'TEXT NULL',
            'subject_template' => 'VARCHAR(512) NULL',
            'body_template' => 'TEXT NULL',
            'events' => "VARCHAR(512) NOT NULL COMMENT 'Comma-separated event names'",
            'created_by' => 'INT UNSIGNED NOT NULL',
            'created_at' => 'INT NOT NULL',
            'updated_at' => 'INT NOT NULL',
        ], $options)->execute();
        // Named after the prefix: a foreign key's name is unique in its database.
        $command->addForeignKey($db->tablePrefix . 'created_by', '{{%notification_template}}', 'created_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE')->execute();
    }

    /**
     * A template of $createdBy listening to $events; delivering as
     * $deliversAs once the migration added the column.
     */
    private function insertTemplate(Connection $db, int $createdBy, string $events, ?int $deliversAs = null): int
    {
        $columns = [
            'name' => 'm91-' . uniqid('', true),
            'channel' => 'email',
            'config' => '{"emails":["ops@example.com"]}',
            'events' => $events,
            'created_by' => $createdBy,
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
        ];

        return $this->insertRow($db, 'notification_template', $deliversAs === null ? $columns : $columns + ['delivers_as' => $deliversAs]);
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

    private function createRun(int $workflowTemplateId, int $userId): int
    {
        \Yii::$app->db->createCommand()->insert('{{%workflow_job}}', [
            'workflow_template_id' => $workflowTemplateId,
            'launched_by' => $userId,
            'status' => 'running',
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }

    private function insertFilter(int $templateId, int $workflowTemplateId): void
    {
        \Yii::$app->db->createCommand()->insert('{{%notification_template_workflow_template}}', [
            'notification_template_id' => $templateId,
            'workflow_template_id' => $workflowTemplateId,
        ])->execute();
    }

    private function insertDelivery(int $templateId, ?int $runId): void
    {
        \Yii::$app->db->createCommand()->insert('{{%notification_delivery}}', [
            'notification_template_id' => $templateId,
            'event' => 'workflow.failed',
            'workflow_job_id' => $runId,
            'variables' => '{}',
            'next_attempt_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
    }

    private function insertThread(int $templateId, int $runId): void
    {
        \Yii::$app->db->createCommand()->insert('{{%notification_thread}}', [
            'notification_template_id' => $templateId,
            'workflow_job_id' => $runId,
            'root_post_id' => 'post' . $runId,
            'channel_id' => 'channel1',
            'server' => 'https://mattermost.example.com',
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();
    }

    private function rowsOf(string $table, int $templateId): int
    {
        return (int)(new Query())->from("{{%{$table}}}")->where(['notification_template_id' => $templateId])->count();
    }

    /**
     * @return list<int>
     */
    private function filterOf(int $templateId): array
    {
        $ids = (new Query())
            ->select('workflow_template_id')
            ->from('{{%notification_template_workflow_template}}')
            ->where(['notification_template_id' => $templateId])
            ->orderBy(['workflow_template_id' => SORT_ASC])
            ->column();

        return array_map('intval', array_values($ids));
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

    /**
     * The indexes of $table: name => [columns in their order, unique].
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    private function indexesOf(Connection $db, string $table): array
    {
        $rows = (new Query())
            ->select(['name' => 'INDEX_NAME', 'column' => 'COLUMN_NAME', 'nonUnique' => 'NON_UNIQUE'])
            ->from('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', [':table' => $db->getSchema()->getRawTableName("{{%{$table}}}")])
            ->orderBy(['INDEX_NAME' => SORT_ASC, 'SEQ_IN_INDEX' => SORT_ASC])
            ->all($db);
        $indexes = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $indexes[$name][0][] = (string)$row['column'];
            $indexes[$name][1] = (int)$row['nonUnique'] === 0;
        }

        return $indexes;
    }

    /**
     * @return array{column: string, table: string, refColumn: string, delete: string, update: string}|null
     */
    private function foreignKey(Connection $db, string $table, string $name): ?array
    {
        $row = (new Query())
            ->select([
                'column' => 'k.COLUMN_NAME',
                'table' => 'k.REFERENCED_TABLE_NAME',
                'refColumn' => 'k.REFERENCED_COLUMN_NAME',
                'delete' => 'r.DELETE_RULE',
                'update' => 'r.UPDATE_RULE',
            ])
            ->from(['k' => 'information_schema.KEY_COLUMN_USAGE'])
            ->innerJoin(
                ['r' => 'information_schema.REFERENTIAL_CONSTRAINTS'],
                'r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME'
            )
            ->where('k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = :table AND k.CONSTRAINT_NAME = :name', [
                ':table' => $db->getSchema()->getRawTableName("{{%{$table}}}"),
                ':name' => $name,
            ])
            ->one($db);
        if (!is_array($row)) {
            return null;
        }

        return [
            'column' => (string)$row['column'],
            'table' => (string)$row['table'],
            'refColumn' => (string)$row['refColumn'],
            'delete' => (string)$row['delete'],
            'update' => (string)$row['update'],
        ];
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
            'tablePrefix' => 'm91_' . bin2hex(random_bytes(3)) . '_',
        ]);

        return $this->scratch;
    }
}
