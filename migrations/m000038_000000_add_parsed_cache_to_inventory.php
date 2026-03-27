<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Cache parsed inventory results so the view can display them
 * immediately without re-running ansible-inventory on every page load.
 */
class m000038_000000_add_parsed_cache_to_inventory extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%inventory}}', 'parsed_hosts', $this->text()->null()->after('project_id')
            ->comment('Cached JSON output of ansible-inventory parse'));
        $this->addColumn('{{%inventory}}', 'parsed_error', $this->string(512)->null()->after('parsed_hosts')
            ->comment('Error message from last parse attempt'));
        $this->addColumn('{{%inventory}}', 'parsed_at', $this->integer()->unsigned()->null()->after('parsed_error')
            ->comment('Unix timestamp of last parse'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%inventory}}', 'parsed_at');
        $this->dropColumn('{{%inventory}}', 'parsed_error');
        $this->dropColumn('{{%inventory}}', 'parsed_hosts');
    }
}
