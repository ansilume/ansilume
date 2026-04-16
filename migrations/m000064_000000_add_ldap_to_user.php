<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds LDAP/Active Directory authentication fields to the user table.
 *
 * - auth_source: 'local' (bcrypt password) or 'ldap' (external directory bind).
 *   Default 'local' for all existing rows so behavior is unchanged.
 * - ldap_dn: distinguished name returned by the directory at last bind. Used
 *   for re-bind and group lookups without redoing a full search.
 * - ldap_uid: stable immutable identifier (objectGUID for AD, entryUUID for
 *   OpenLDAP). Survives username renames in the directory. Unique when set;
 *   NULL allowed for local users.
 * - last_synced_at: unix timestamp of the last successful LDAP attribute sync.
 */
class m000064_000000_add_ldap_to_user extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%user}}',
            'auth_source',
            $this->string(16)->notNull()->defaultValue('local')->after('recovery_codes')
        );
        $this->addColumn(
            '{{%user}}',
            'ldap_dn',
            $this->string(512)->null()->after('auth_source')
        );
        $this->addColumn(
            '{{%user}}',
            'ldap_uid',
            $this->string(255)->null()->after('ldap_dn')
        );
        $this->addColumn(
            '{{%user}}',
            'last_synced_at',
            $this->integer()->unsigned()->null()->after('ldap_uid')
        );

        $this->createIndex('idx_user_auth_source', '{{%user}}', 'auth_source');
        $this->createIndex('uniq_user_ldap_uid', '{{%user}}', 'ldap_uid', true);
    }

    public function safeDown(): void
    {
        $this->dropIndex('uniq_user_ldap_uid', '{{%user}}');
        $this->dropIndex('idx_user_auth_source', '{{%user}}');
        $this->dropColumn('{{%user}}', 'last_synced_at');
        $this->dropColumn('{{%user}}', 'ldap_uid');
        $this->dropColumn('{{%user}}', 'ldap_dn');
        $this->dropColumn('{{%user}}', 'auth_source');
    }
}
