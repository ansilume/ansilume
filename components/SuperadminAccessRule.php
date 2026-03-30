<?php

declare(strict_types=1);

namespace app\components;

use app\models\User;
use yii\filters\AccessRule;

/**
 * AccessRule that grants access to superadmins unconditionally.
 * Add this as the first rule in every controller's AccessControl filter,
 * or use it via BaseController::behaviors().
 */
class SuperadminAccessRule extends AccessRule
{
    public $allow = true;
    /** @var string[] */
    public $roles = ['@'];

    public function allows($action, $user, $request): ?bool
    {
        if ($user === false) {
            return null;
        }

        if ($user->isGuest) {
            return null;
        }

        /** @var User $identity */
        $identity = $user->identity;
        if ($identity !== null && $identity->is_superadmin) {
            return true;
        }

        return null; // defer to next rule
    }
}
