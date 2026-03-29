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
     * Creates the first admin user.
     *
     * Usage: php yii setup/admin [username] [email] [password]
     */
    public function actionAdmin(
        string $username = 'admin',
        string $email = 'admin@example.com',
        string $password = ''
    ): int {
        if (User::find()->where(['is_superadmin' => true])->exists()) {
            $this->stdout("A superadmin already exists. Aborting.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

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

        $this->applyDeferredSeeds();

        return ExitCode::OK;
    }

    /**
     * Seed migrations that require a user to exist are skipped during the
     * initial container startup (no users yet). Re-mark them as pending and
     * re-run migrate so they execute now that the first admin user exists.
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
        \Yii::$app->runAction('migrate/up', ['interactive' => 0]);
    }
}
