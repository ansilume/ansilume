<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Approval rules are managed by admins: the operator role loses
 * approval-rule.create and approval-rule.update, which m000052 granted it.
 * Whoever may change a rule's approvers can neutralise every gate that uses
 * it, and assigning rules to job templates and workflow steps needs
 * approval-rule.update from now on as well. Operators keep viewing rules
 * (approval-rule.view) and deciding requests (approval.decide).
 *
 * The admin role inherits operator. Where an admin removed admin's own
 * direct grant through the role editor, admin held the permissions only
 * through operator: each one is granted to admin directly before operator
 * loses it, so admins never lose them. Custom roles keep what they hold; an
 * admin can grant the two permissions to a custom role to let other users
 * manage approval rules.
 *
 * Data only (RBAC rows). Running it again changes nothing. Down gives the
 * operator role both permissions back.
 */
class m000082_000000_restrict_approval_rule_management extends Migration
{
    /** The permissions operators lose. approval-rule.delete was admin-only already. */
    private const PERMISSIONS = ['approval-rule.create', 'approval-rule.update'];

    public function safeUp(): void
    {
        $auth = $this->auth();
        $admin = $auth->getRole('admin');
        $operator = $auth->getRole('operator');
        $removed = 0;
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->getPermission($name);
            if ($permission === null) {
                echo "    > Permission {$name} does not exist: nothing to change.\n";
                continue;
            }
            if ($admin !== null && !$auth->hasChild($admin, $permission)) {
                $auth->addChild($admin, $permission);
                echo "    > Granted {$name} to the admin role directly.\n";
            }
            if ($operator !== null && $auth->hasChild($operator, $permission)) {
                $auth->removeChild($operator, $permission);
                echo "    > Removed {$name} from the operator role.\n";
                $removed++;
            }
        }
        if ($removed > 0) {
            echo "    > Operators no longer create or change approval rules. To let other users manage them, "
                . "grant approval-rule.create and approval-rule.update to a custom role.\n";
        }
    }

    public function safeDown(): void
    {
        $auth = $this->auth();
        $operator = $auth->getRole('operator');
        if ($operator === null) {
            return;
        }
        foreach (self::PERMISSIONS as $name) {
            $permission = $auth->getPermission($name);
            if ($permission !== null && !$auth->hasChild($operator, $permission)) {
                $auth->addChild($operator, $permission);
            }
        }
    }

    private function auth(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        if ($auth === null) {
            throw new \RuntimeException('The authManager component is not configured.');
        }

        return $auth;
    }
}
