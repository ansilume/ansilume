<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use yii\rbac\Rule;

/**
 * An RBAC business rule for tests: it passes only for the users it lists.
 * DbManager stores rules serialized, so this is a named class with public
 * properties.
 */
final class ListedUsersRbacRule extends Rule
{
    /** @var list<int> */
    public array $userIds = [];

    /**
     * @param string|int $user
     * @param \yii\rbac\Item $item
     * @param array<string, mixed> $params
     */
    public function execute($user, $item, $params): bool
    {
        return in_array((int)$user, $this->userIds, true);
    }
}
