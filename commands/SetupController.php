<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
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
        string $email    = 'admin@example.com',
        string $password = ''
    ): int {
        if (User::find()->where(['is_superadmin' => true])->exists()) {
            $this->stdout("A superadmin already exists. Aborting.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($password === '') {
            $password = \Yii::$app->security->generateRandomString(16);
            $this->stdout("No password supplied — generated: {$password}\n");
        }

        $user = new User();
        $user->username     = $username;
        $user->email        = $email;
        $user->status       = User::STATUS_ACTIVE;
        $user->is_superadmin = true;
        $user->setPassword($password);
        $user->generateAuthKey();

        if (!$user->save()) {
            $this->stderr("Failed to create user: " . json_encode($user->errors) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Assign admin RBAC role
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole('admin');
        if ($role !== null) {
            $auth->assign($role, $user->id);
        }

        $this->stdout("Admin user '{$username}' created with ID {$user->id}.\n");
        return ExitCode::OK;
    }
}
