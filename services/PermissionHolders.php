<?php

declare(strict_types=1);

namespace app\services;

use yii\db\Connection;
use yii\db\Query;
use yii\rbac\DbManager;
use yii\rbac\ManagerInterface;

/**
 * Which of a set of users hold an RBAC item (a permission or a role): the
 * answer of ManagerInterface::checkAccess() for each of them, but in a
 * constant number of queries however many users are asked about, where
 * DbManager::checkAccess() costs several queries per user.
 *
 * A user holds an item when they are assigned the item or an item above it
 * (a role that has it as a child, directly or through other roles), or when
 * such an item is one of the manager's default roles: then every user holds
 * it. An item that carries a business rule, and a manager other than
 * DbManager, are left to checkAccess() user by user, so the answer never
 * differs from it.
 *
 * A user ID below 1 names no user and holds nothing, default roles or not:
 * app\components\AuthManager::checkAccess() answers false for it, so it is
 * dropped before anything is read.
 *
 * One instance reads the item hierarchy once: create one per decision, not
 * one per process, or role changes would go unnoticed.
 */
final class PermissionHolders
{
    /** @var array<string, list<string>>|null child item name => names of its parent items */
    private ?array $parents = null;

    public function __construct(private readonly ManagerInterface $auth)
    {
    }

    /**
     * @param list<int> $userIds
     * @return list<int> the users of $userIds who hold $itemName, in the order given; never an ID below 1
     */
    public function among(string $itemName, array $userIds): array
    {
        $userIds = array_values(array_filter($userIds, static fn (int $userId): bool => $userId >= 1));
        if ($userIds === []) {
            return [];
        }
        $auth = $this->auth;
        if (!$auth instanceof DbManager) {
            return $this->checkedOneByOne($itemName, $userIds);
        }
        $granting = $this->grantingItems($auth, $itemName);
        if ($granting === null) {
            return $this->checkedOneByOne($itemName, $userIds);
        }
        if ($granting === []) {
            return [];
        }
        if (array_intersect(array_map('strval', $auth->getDefaultRoles()), $granting) !== []) {
            return $userIds;
        }
        $assigned = array_flip(array_map('strval', (new Query())
            ->select('user_id')
            ->distinct()
            ->from($auth->assignmentTable)
            ->where(['item_name' => $granting, 'user_id' => array_map('strval', $userIds)])
            ->column($this->db($auth))));

        return array_values(array_filter(
            $userIds,
            static fn (int $userId): bool => isset($assigned[(string)$userId])
        ));
    }

    /**
     * The items whose holders hold $itemName: the item itself and every item
     * above it. Empty when the item does not exist, so nobody holds it; null
     * when one of them carries a business rule, which only checkAccess() can
     * evaluate.
     *
     * @return list<string>|null
     */
    private function grantingItems(DbManager $auth, string $itemName): ?array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (new Query())
            ->select(['name', 'rule_name'])
            ->from($auth->itemTable)
            ->where(['name' => $this->itemAndAncestors($auth, $itemName)])
            ->all($this->db($auth));
        $names = [];
        foreach ($rows as $row) {
            if ($row['rule_name'] !== null) {
                return null;
            }
            $names[] = (string)$row['name'];
        }

        return in_array($itemName, $names, true) ? $names : [];
    }

    /**
     * @return list<string> $itemName and the names of all items above it
     */
    private function itemAndAncestors(DbManager $auth, string $itemName): array
    {
        $parents = $this->parents($auth);
        $found = [$itemName => true];
        $queue = [$itemName];
        while ($queue !== []) {
            $name = array_pop($queue);
            foreach ($parents[$name] ?? [] as $parent) {
                if (!isset($found[$parent])) {
                    $found[$parent] = true;
                    $queue[] = $parent;
                }
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * The item hierarchy, read in one query.
     *
     * @return array<string, list<string>>
     */
    private function parents(DbManager $auth): array
    {
        if ($this->parents === null) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (new Query())
                ->select(['parent', 'child'])
                ->from($auth->itemChildTable)
                ->all($this->db($auth));
            $parents = [];
            foreach ($rows as $row) {
                $parents[(string)$row['child']][] = (string)$row['parent'];
            }
            $this->parents = $parents;
        }

        return $this->parents;
    }

    /**
     * @param list<int> $userIds
     * @return list<int>
     */
    private function checkedOneByOne(string $itemName, array $userIds): array
    {
        return array_values(array_filter(
            $userIds,
            fn (int $userId): bool => $this->auth->checkAccess($userId, $itemName)
        ));
    }

    private function db(DbManager $auth): Connection
    {
        /** @var Connection $db DbManager::init() turns the configured connection into an object */
        $db = $auth->db;

        return $db;
    }
}
