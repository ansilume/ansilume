<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProfileController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

class ProfileControllerTest extends WebControllerTestCase
{
    private ProfileController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new ProfileController('api/v1/profile', \Yii::$app);
    }

    public function testChangePasswordRequiresAuth(): void
    {
        $this->expectException(\yii\web\UnauthorizedHttpException::class);
        $this->ctrl->beforeAction(new \yii\base\Action('change-password', $this->ctrl));
    }

    public function testChangePasswordSucceeds(): void
    {
        $user = $this->authenticateUser('oldpassword');

        \Yii::$app->request->setBodyParams([
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword123',
            'new_password_confirm' => 'newpassword123',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $this->ctrl->actionChangePassword();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Password changed.', $result['data']['message']);

        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('newpassword123'));
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $this->authenticateUser('correctpassword');

        \Yii::$app->request->setBodyParams([
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123',
            'new_password_confirm' => 'newpassword123',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $this->ctrl->actionChangePassword();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('fields', $result['error']);
        $this->assertArrayHasKey('current_password', $result['error']['fields']);
    }

    public function testChangePasswordRejectsMismatch(): void
    {
        $this->authenticateUser('oldpassword');

        \Yii::$app->request->setBodyParams([
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword123',
            'new_password_confirm' => 'different123',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $this->ctrl->actionChangePassword();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('new_password_confirm', $result['error']['fields']);
    }

    public function testChangePasswordRejectsShort(): void
    {
        $this->authenticateUser('oldpassword');

        \Yii::$app->request->setBodyParams([
            'current_password' => 'oldpassword',
            'new_password' => 'short',
            'new_password_confirm' => 'short',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $this->ctrl->actionChangePassword();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('new_password', $result['error']['fields']);
    }

    public function testChangePasswordCreatesAuditLog(): void
    {
        $user = $this->authenticateUser('oldpassword');

        \Yii::$app->request->setBodyParams([
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword123',
            'new_password_confirm' => 'newpassword123',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->ctrl->actionChangePassword();

        $log = AuditLog::find()
            ->where([
                'action' => AuditLog::ACTION_PASSWORD_CHANGED,
                'object_type' => 'user',
                'object_id' => $user->id,
            ])
            ->one();

        $this->assertNotNull($log);
    }

    public function testChangePasswordRejectsEmptyFields(): void
    {
        $this->authenticateUser('oldpassword');

        \Yii::$app->request->setBodyParams([]);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = $this->ctrl->actionChangePassword();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('fields', $result['error']);
    }

    private function authenticateUser(string $password): User
    {
        $user = $this->createUser();
        $user->setPassword($password);
        $user->save(false);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }
}
