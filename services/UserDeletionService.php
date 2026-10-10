<?php

declare(strict_types=1);

namespace app\services;

use app\models\User;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Deletes a user together with their role assignments, all or nothing.
 *
 * Jobs they launched, rows they created and trigger tokens they generated
 * refer to the user through RESTRICT foreign keys, so the database refuses
 * to delete a user who is still referenced. The roles are revoked in the
 * same transaction (the RBAC manager writes through the same connection):
 * a refused delete keeps them, so the schedules and triggers that run as
 * the user keep working. auth_assignment has no foreign key to user, so a
 * deleted user's roles would otherwise stay behind.
 */
class UserDeletionService extends Component
{
    /**
     * Delete $user and revoke their roles. Returns false, with nothing
     * changed, when other records still refer to the user or the delete was
     * vetoed.
     */
    public function delete(User $user): bool
    {
        $transaction = User::getDb()->beginTransaction();
        try {
            $deleted = $user->delete() !== false;
            if ($deleted) {
                $this->authManager()->revokeAll((string)$user->id);
            }
            $transaction->commit();
        } catch (IntegrityException) {
            $transaction->rollBack();
            return false;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $deleted;
    }

    /**
     * Why delete() refused $user, and what to do instead.
     */
    public function refusalMessage(User $user): string
    {
        return sprintf(
            'User "%s" cannot be deleted: other records still refer to them, such as jobs they launched, '
                . 'resources they created or a trigger token they generated. Deactivate the account instead.',
            $user->username
        );
    }

    private function authManager(): \yii\rbac\ManagerInterface
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        return $auth;
    }
}
