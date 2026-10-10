<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * Removes the role assignments that belong to no user.
 *
 * Older versions deleted users in the web UI without revoking their roles,
 * and auth_assignment has no foreign key to user, so the rows stayed. They
 * count as users of the role, put deleted users on the approver list of
 * role-based approval rules, and would hand their roles to any new user who
 * is given a deleted user's ID.
 *
 * A row belongs to a user when its user_id equals the user's ID as a string
 * under the column's own collation, the comparison the RBAC manager makes
 * when it looks up a user's roles. So "42 " stays with user 42 (the
 * collation ignores trailing spaces), while "042" or " 42", which no lookup
 * ever finds, are removed. CONCAT() turns the ID into a string that, like
 * the bound value of that lookup, takes the column's collation. CAST(id AS
 * CHAR) would carry the connection's collation, which MariaDB refuses to
 * compare with the column's ("Illegal mix of collations"), and comparing
 * with the integer converts both sides to numbers, which keeps "042" for
 * user 42.
 *
 * Data only. Running it again changes nothing.
 */
class m000079_000000_remove_orphaned_role_assignments extends Migration
{
    public function safeUp(): void
    {
        // The tables the RBAC and user migrations created, not the RBAC
        // manager's configuration: a migration must keep working when the
        // application changes.
        $owner = (new Query())
            ->from('{{%user}}')
            ->where('{{%auth_assignment}}.[[user_id]] = CONCAT({{%user}}.[[id]])');
        $removed = $this->db->createCommand()
            ->delete('{{%auth_assignment}}', ['not exists', $owner])
            ->execute();

        echo "    > Removed {$removed} role assignment(s) that belong to no user.\n";
    }

    public function safeDown(): bool
    {
        // Nothing to restore: the removed rows gave roles to users that do
        // not exist. Putting them back would only bring the orphans back, and
        // the schema is unchanged.
        return true;
    }
}
