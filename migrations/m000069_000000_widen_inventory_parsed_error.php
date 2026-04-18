<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Widen `inventory.parsed_error` from VARCHAR(512) to TEXT.
 *
 * ansible-inventory error messages on a broken YAML / INI file can
 * include several lines of context plus the parser's internal stack
 * reference — easily over 512 characters. When that happens, saving
 * the error back to the cache blows up with MySQL error 1406
 * ("Data too long for column"), which bubbles up as a 500 on POST
 * /inventory/parse-hosts and looks like "the button doesn't work".
 *
 * TEXT caps at 64 KB, which is well above the largest realistic
 * ansible-inventory error output (a few KB at most).
 */
class m000069_000000_widen_inventory_parsed_error extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%inventory}}', 'parsed_error', $this->text()->null());
    }

    public function safeDown(): void
    {
        $this->alterColumn('{{%inventory}}', 'parsed_error', $this->string(512)->null());
    }
}
