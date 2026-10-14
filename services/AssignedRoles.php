<?php

declare(strict_types=1);

namespace app\services;

use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Query;
use yii\rbac\DbManager;
use yii\rbac\Item;

/**
 * The roles assigned to users: the rows of the RBAC assignment table whose
 * item is a role. The manager's default roles count only where they are
 * assigned too.
 *
 * The web and console applications give every signed-in user the default
 * role viewer (config/web.php), and DbManager::getRolesByUser() lists the
 * default roles first, for every user. Code that took the first of those
 * roles as the user's role found viewer for everyone: up to 2.10.1 the Edit
 * User form preselected Viewer, so saving an operator's or an admin's
 * account unchanged made them a viewer; the users list and the user page
 * showed viewer beside every role, and the API's role said viewer for every
 * user. Whatever shows or edits the roles a user was given reads them here.
 * A user without an assigned role holds the default roles all the same; they
 * are not listed.
 */
final class AssignedRoles
{
    /** Why fromApp() has no assignment table to read; %s: the class of the authManager component. */
    public const NOT_A_DB_MANAGER = 'The assigned roles are read from the tables of yii\rbac\DbManager, but the authManager component is %s.';

    public function __construct(private readonly DbManager $auth)
    {
    }

    /**
     * The assigned roles of the application's RBAC manager, the authManager
     * component.
     *
     * @throws InvalidConfigException when that manager keeps no assignment table
     */
    public static function fromApp(): self
    {
        $auth = \Yii::$app->authManager;
        if (!$auth instanceof DbManager) {
            throw new InvalidConfigException(sprintf(self::NOT_A_DB_MANAGER, get_debug_type($auth)));
        }

        return new self($auth);
    }

    /**
     * @return list<string> the names of the roles assigned to $userId, sorted by name
     */
    public function of(int $userId): array
    {
        return $this->ofUsers([$userId])[$userId];
    }

    /**
     * The roles assigned to each of $userIds, read in one query.
     *
     * @param list<int> $userIds
     * @return array<int, list<string>> every ID of $userIds, in the order given, => the names of the roles
     *     assigned to that user, sorted by name; [] when none is
     */
    public function ofUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $stored = [];
        foreach ($this->assignments($userIds) as $row) {
            $stored[(string)$row['user_id']][] = (string)$row['item_name'];
        }
        $roles = [];
        foreach ($userIds as $userId) {
            $names = $stored[(string)$userId] ?? [];
            sort($names, SORT_STRING);
            $roles[$userId] = $names;
        }

        return $roles;
    }

    /**
     * The role assignments of $userIds: user IDs are stored as strings.
     *
     * @param non-empty-list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function assignments(array $userIds): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (new Query())
            ->select(['a.user_id', 'a.item_name'])
            ->from(['a' => $this->auth->assignmentTable])
            ->innerJoin(['i' => $this->auth->itemTable], 'i.name = a.item_name')
            ->where(['a.user_id' => array_map('strval', $userIds), 'i.type' => Item::TYPE_ROLE])
            ->all($this->db());

        return $rows;
    }

    private function db(): Connection
    {
        /** @var Connection $db DbManager::init() turns the configured connection into an object */
        $db = $this->auth->db;

        return $db;
    }
}
