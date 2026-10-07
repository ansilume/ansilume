<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * How each runner reaches the server, recorded from its requests:
 *
 * - transport: https, http_internal (plain HTTP from a trusted network such
 *   as the bundled runners' Docker network) or http_external (plain HTTP
 *   from elsewhere; claim responses then carry decrypted credentials in
 *   clear). NULL until the first request after the upgrade.
 * - remote_addr: the client address the classification is based on.
 * - plaintext_seen_at: the last http_external request, kept after a fix so
 *   operators know which credentials to rotate.
 */
class m000075_000000_add_runner_transport extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%runner}}', 'transport', $this->string(16)->null()->defaultValue(null));
        $this->addColumn('{{%runner}}', 'remote_addr', $this->string(45)->null()->defaultValue(null));
        $this->addColumn('{{%runner}}', 'plaintext_seen_at', $this->integer()->null()->defaultValue(null));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%runner}}', 'plaintext_seen_at');
        $this->dropColumn('{{%runner}}', 'remote_addr');
        $this->dropColumn('{{%runner}}', 'transport');
    }
}
