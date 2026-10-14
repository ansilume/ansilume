<?php

declare(strict_types=1);

namespace app\components;

use yii\rbac\DbManager;

/**
 * The RBAC manager of every application (config/web.php, config/console.php
 * and config/test.php): yii\rbac\DbManager, except that a user ID that names
 * no user holds nothing.
 *
 * The web and console applications give every user the default role viewer.
 * DbManager applies default roles to any user ID, also to the null that
 * yii\web\User::getId() answers for a visitor who has not signed in, so a
 * signed-out visitor passed every access rule that names a viewer permission
 * and read lists such as the credentials, the runner groups and the approval
 * rules.
 * Here the default roles apply to signed-in users only.
 *
 * A user is named by an int from 1 up or by its canonical decimal string
 * ("7", but not "07", "+7", " 7" or "7\n"). Anything else names no user:
 * null, '', 0, '0', negative numbers, other strings, floats, booleans and
 * every other type. Whether the account exists or is active is not decided
 * here: web sessions and API tokens resolve active accounts only
 * (app\models\User::findIdentity()), and services that act for a stored user
 * check the account first (WorkflowAccessChecker::isActiveWithPermission()).
 */
final class AuthManager extends DbManager
{
    /**
     * Whether $userId holds $permissionName: never when $userId names no
     * user, otherwise as DbManager decides, default roles included.
     *
     * @param mixed $userId
     * @param string $permissionName
     * @param array<mixed> $params
     */
    public function checkAccess($userId, $permissionName, $params = []): bool
    {
        if (!self::namesAUser($userId)) {
            return false;
        }

        return parent::checkAccess($userId, $permissionName, $params);
    }

    /**
     * Whether $userId is an int from 1 up or the canonical decimal string of
     * one: a string that reads back unchanged as an int.
     *
     * @phpstan-assert-if-true int|string $userId
     */
    private static function namesAUser(mixed $userId): bool
    {
        if (is_int($userId)) {
            return $userId >= 1;
        }

        return is_string($userId) && $userId === (string)(int)$userId && (int)$userId >= 1;
    }
}
