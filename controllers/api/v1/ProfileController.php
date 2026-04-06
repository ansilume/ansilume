<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\ChangePasswordForm;
use app\models\User;

/**
 * API v1: Profile
 *
 * POST /api/v1/profile/change-password
 */
class ProfileController extends BaseApiController
{
    /**
     * POST /api/v1/profile/change-password
     *
     * Body: { current_password, new_password, new_password_confirm }
     *
     * @return array<string, mixed>
     */
    public function actionChangePassword(): array
    {
        /** @var User|null $user */
        $user = \Yii::$app->user->identity;
        if ($user === null) {
            return $this->error('Unauthorized.', 401);
        }

        $model = new ChangePasswordForm($user);
        $input = (array)\Yii::$app->request->bodyParams;
        $model->current_password = (string)($input['current_password'] ?? '');
        $model->new_password = (string)($input['new_password'] ?? '');
        $model->new_password_confirm = (string)($input['new_password_confirm'] ?? '');

        if (!$model->changePassword()) {
            \Yii::$app->response->statusCode = 422;
            return ['error' => ['message' => 'Validation failed.', 'fields' => $model->getFirstErrors()]];
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PASSWORD_CHANGED,
            'user',
            $user->id
        );

        return $this->success(['message' => 'Password changed.']);
    }
}
