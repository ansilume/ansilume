<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Team;
use app\models\User;

/**
 * Seeds e2e-referenced-user, a user whom another record still refers to: the
 * team e2e-referenced-user-team names them as its creator. The database
 * refuses to delete such a user, so the web UI keeps the user and says why
 * (tests/e2e/tests/users/crud.spec.ts). The team has no members and no
 * projects, so it changes nobody's access.
 *
 * Restored on every seed. e2e/teardown removes the team, then the user.
 */
final class E2eReferencedUserSeeder
{
    public const USERNAME = 'e2e-referenced-user';
    public const TEAM = 'e2e-referenced-user-team';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(): void
    {
        $user = User::findOne(['username' => self::USERNAME]) ?? new User();
        if ($user->isNewRecord) {
            $user->username = self::USERNAME;
            // Nobody signs in as this user.
            $user->setPassword(\Yii::$app->security->generateRandomString(32));
            $user->generateAuthKey();
        }
        $user->email = self::USERNAME . '@example.com';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        $user->save(false);

        $team = Team::findOne(['name' => self::TEAM]) ?? new Team();
        $team->name = self::TEAM;
        $team->description = 'E2E fixture: its creator e2e-referenced-user cannot be deleted';
        $team->created_by = (int)$user->id;
        $team->save(false);

        ($this->logger)('  Restored user ' . self::USERNAME . " (ID {$user->id}), creator of team " . self::TEAM . ".\n");
    }
}
