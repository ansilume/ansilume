<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use app\services\AuditService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Initial platform setup commands.
 */
class SetupController extends Controller
{
    /**
     * What setup/admin prints, with a newline, when it aborts: an active
     * superadmin exists (User::activeSuperadminExists()). bin/quickstart
     * reads "already exists" in it as an admin who is there already.
     */
    public const ACTIVE_SUPERADMIN_EXISTS = 'An active superadmin already exists. Aborting.';

    /**
     * Creates a superadmin with the admin role: the first user, or, while no
     * active superadmin exists, a new one, so that a superadmin can sign in
     * again when every superadmin is inactive or none is left. Aborts while
     * an active superadmin exists (ACTIVE_SUPERADMIN_EXISTS). Only the first
     * setup, run while no user exists, runs the deferred seed migrations: a
     * later run leaves the seeded projects, inventories and templates alone.
     *
     * Usage: php yii setup/admin [username] [email] [password]
     */
    public function actionAdmin(
        string $username = 'admin',
        string $email = 'admin@example.com',
        #[\SensitiveParameter] string $password = ''
    ): int {
        if (User::activeSuperadminExists()) {
            $this->stdout(self::ACTIVE_SUPERADMIN_EXISTS . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $firstSetup = !User::find()->exists();

        if ($password === '') {
            /** @var \yii\base\Security $security */
            $security = \Yii::$app->security;
            $password = $security->generateRandomString(16);
            $this->stdout("No password supplied — generated: {$password}\n");
        }

        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = true;
        $user->setPassword($password);
        $user->generateAuthKey();

        if (!$user->save()) {
            $this->stderr("Failed to create user: " . json_encode($user->errors) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Assign admin RBAC role
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole('admin');
        if ($role !== null) {
            $auth->assign($role, $user->id);
        } else {
            $this->stderr("WARNING: RBAC role 'admin' not found — user created without role assignment.\n");
            $this->stderr("Run migrations to seed RBAC roles: php yii migrate\n");
        }

        /** @var AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditService::ACTION_USER_CREATED,
            'user',
            $user->id,
            $user->id,
            ['username' => $username, 'email' => $email, 'source' => 'console:setup/admin']
        );

        $this->stdout("Admin user '{$username}' created with ID {$user->id}.\n");

        if ($firstSetup) {
            $this->applyDeferredSeeds();
        }

        return ExitCode::OK;
    }

    /**
     * Seed migrations that require a user to exist are skipped during the
     * initial container startup (no users yet). Re-mark them as pending and
     * re-run migrate so they execute now that the first admin user exists.
     * Only for the first setup: run again, they would create the Selftest
     * and Demo content an operator removed (and queue a clone of the demo
     * repository) and give the default runner group to seeded templates
     * whose runner group is gone.
     */
    private function applyDeferredSeeds(): void
    {
        $seedMigrations = [
            'm000020_000000_seed_selftest_template',
            'm000035_000000_seed_demo_project',
            'm000036_000000_assign_default_runner_group_to_seeded_templates',
        ];

        $db = \Yii::$app->db;

        // Only reset migrations that were previously skipped (i.e. exist in the
        // applied table but produced no data because no user existed at the time).
        foreach ($seedMigrations as $version) {
            $applied = (int)$db->createCommand(
                'SELECT COUNT(*) FROM {{%migration}} WHERE version = :v',
                [':v' => $version]
            )->queryScalar();

            if ($applied > 0) {
                $db->createCommand()->delete('{{%migration}}', ['version' => $version])->execute();
                $this->stdout("Deferred seed reset: {$version}\n");
            }
        }

        $this->stdout("Running deferred seed migrations...\n");
        $result = \Yii::$app->runAction('migrate/up', ['interactive' => 0]);
        if ($result !== ExitCode::OK && $result !== null) {
            $this->stderr("WARNING: Deferred seed migrations returned non-zero exit code.\n");
            $this->stderr("The admin user was created, but demo data may be incomplete.\n");
        }
    }
}
