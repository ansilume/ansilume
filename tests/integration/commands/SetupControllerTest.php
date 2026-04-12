<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\SetupController;
use app\models\AuditLog;
use app\models\User;
use app\services\AuditService;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * Integration tests for SetupController::actionAdmin().
 *
 * Verifies that:
 *   - the command creates an admin user and writes ACTION_USER_CREATED to the audit log
 *   - the audit metadata includes the console source so the entry is distinguishable
 *     from browser-initiated user creation
 *   - the command refuses to run when a superadmin already exists and produces no audit entry
 *
 * Regression guard: if someone removes the auditService->log() call from actionAdmin(),
 * testAdminCommandCreatesAuditLog() will fail immediately.
 */
class SetupControllerTest extends DbTestCase
{
    private SetupController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();

        // Suppress console output so test output stays clean.
        $this->ctrl = new class ('setup', \Yii::$app) extends SetupController {
            public function stdout($string): int
            {
                return 0;
            }
            public function stderr($string): int
            {
                return 0;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Audit log is written on success
    // -------------------------------------------------------------------------

    public function testAdminCommandCreatesAuditLog(): void
    {
        $username = 'setup_admin_' . uniqid('', true);
        $email    = $username . '@example.com';

        $result = $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result);

        $user = User::find()->where(['username' => $username])->one();
        $this->assertNotNull($user, 'Admin user should be persisted');

        $entry = AuditLog::find()
            ->where([
                'action'      => AuditService::ACTION_USER_CREATED,
                'object_type' => 'user',
                'object_id'   => $user->id,
            ])
            ->one();

        $this->assertNotNull($entry, 'AuditLog entry for ACTION_USER_CREATED must be written');
        $this->assertSame($user->id, $entry->user_id, 'Audit entry user_id should be the new admin user');

        $meta = json_decode((string)$entry->metadata, true);
        $this->assertSame($username, $meta['username']);
        $this->assertSame(
            'console:setup/admin',
            $meta['source'],
            'Audit metadata must identify this as a console-originated action'
        );
    }

    public function testAuditMetadataContainsEmail(): void
    {
        $username = 'setup_email_' . uniqid('', true);
        $email    = $username . '@example.com';

        $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $user  = User::find()->where(['username' => $username])->one();
        $entry = AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED, 'object_id' => $user->id])
            ->one();

        $meta = json_decode((string)$entry->metadata, true);
        $this->assertSame($email, $meta['email']);
    }

    // -------------------------------------------------------------------------
    // No audit entry is written when the command aborts
    // -------------------------------------------------------------------------

    public function testAdminUserGetsRbacRoleAssigned(): void
    {
        $username = 'setup_rbac_' . uniqid('', true);
        $email = $username . '@example.com';

        $result = $this->ctrl->actionAdmin($username, $email, 'S3cur3P@ss!');

        $this->assertSame(ExitCode::OK, $result);

        $user = User::find()->where(['username' => $username])->one();
        $this->assertNotNull($user);

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRolesByUser($user->id);
        $this->assertArrayHasKey('admin', $roles, 'Admin user must have the admin RBAC role');
    }

    public function testNoAuditLogWhenSuperadminAlreadyExists(): void
    {
        // Seed a superadmin so the command aborts.
        $existing               = new User();
        $existing->username     = 'existing_' . uniqid('', true);
        $existing->email        = $existing->username . '@example.com';
        $existing->password_hash = \Yii::$app->security->generatePasswordHash('test');
        $existing->auth_key     = \Yii::$app->security->generateRandomString();
        $existing->status       = User::STATUS_ACTIVE;
        $existing->is_superadmin = true;
        $existing->save(false);

        $countBefore = (int) AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED])
            ->count();

        $result = $this->ctrl->actionAdmin('second_admin', 'second@example.com', 'P@ssw0rd!');

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $result);

        $countAfter = (int) AuditLog::find()
            ->where(['action' => AuditService::ACTION_USER_CREATED])
            ->count();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'No audit entry should be written when the command aborts early'
        );
    }
}
