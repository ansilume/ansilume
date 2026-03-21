<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the teams table.
 *
 * Teams group users together and can be granted access to specific
 * projects with an assigned role (viewer or operator).
 */
class m000016_000000_create_teams_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%team}}', [
            'id'          => $this->primaryKey()->unsigned(),
            'name'        => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'created_by'  => $this->integer()->unsigned()->notNull(),
            'created_at'  => $this->integer()->unsigned()->notNull(),
            'updated_at'  => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_team_name', '{{%team}}', 'name', true);

        $this->addForeignKey(
            'fk_team_created_by',
            '{{%team}}', 'created_by',
            '{{%user}}', 'id',
            'RESTRICT', 'CASCADE'
        );

        // team_member: which users belong to which team
        $this->createTable('{{%team_member}}', [
            'id'         => $this->primaryKey()->unsigned(),
            'team_id'    => $this->integer()->unsigned()->notNull(),
            'user_id'    => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_team_member_unique', '{{%team_member}}', ['team_id', 'user_id'], true);
        $this->createIndex('idx_team_member_user',   '{{%team_member}}', 'user_id');

        $this->addForeignKey(
            'fk_team_member_team',
            '{{%team_member}}', 'team_id',
            '{{%team}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_team_member_user',
            '{{%team_member}}', 'user_id',
            '{{%user}}', 'id',
            'CASCADE', 'CASCADE'
        );

        // team_project: which teams can access which projects, and with what role
        $this->createTable('{{%team_project}}', [
            'id'         => $this->primaryKey()->unsigned(),
            'team_id'    => $this->integer()->unsigned()->notNull(),
            'project_id' => $this->integer()->unsigned()->notNull(),
            'role'       => $this->string(32)->notNull()->defaultValue('viewer')
                ->comment('viewer or operator — determines what the team can do in the project'),
            'created_at' => $this->integer()->unsigned()->notNull(),
        ], $tableOptions);

        $this->createIndex('idx_team_project_unique',  '{{%team_project}}', ['team_id', 'project_id'], true);
        $this->createIndex('idx_team_project_project', '{{%team_project}}', 'project_id');

        $this->addForeignKey(
            'fk_team_project_team',
            '{{%team_project}}', 'team_id',
            '{{%team}}', 'id',
            'CASCADE', 'CASCADE'
        );
        $this->addForeignKey(
            'fk_team_project_project',
            '{{%team_project}}', 'project_id',
            '{{%project}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%team_project}}');
        $this->dropTable('{{%team_member}}');
        $this->dropTable('{{%team}}');
    }
}
