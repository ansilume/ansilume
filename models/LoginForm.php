<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

class LoginForm extends Model
{
    public string $username = '';
    public string $password = '';
    public bool $rememberMe = true;

    private ?User $_user = null;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            [['rememberMe'], 'boolean'],
            [['password'], 'validatePassword'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'password' => 'Password',
            'rememberMe' => 'Remember me',
        ];
    }

    public function validatePassword(string $attribute): void
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();
            if ($user === null || !$user->validatePassword($this->password)) {
                $this->addError($attribute, 'Incorrect username or password.');
            }
        }
    }

    /**
     * Check if credentials are valid without actually logging in.
     */
    public function validateCredentials(): bool
    {
        return $this->validate();
    }

    /**
     * Whether the validated user has TOTP 2FA enabled.
     */
    public function requiresTotp(): bool
    {
        $user = $this->getUser();
        return $user !== null && $user->totp_enabled;
    }

    public function login(): bool
    {
        if ($this->validate()) {
            /** @var User $user Validated above — user exists */
            $user = $this->getUser();
            $duration = $this->rememberMe ? 3600 * 24 * 30 : 0;
            /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
            $userComponent = \Yii::$app->user;
            return $userComponent->login($user, $duration);
        }
        return false;
    }

    public function getUserModel(): ?User
    {
        return $this->getUser();
    }

    protected function getUser(): ?User
    {
        if ($this->_user === null) {
            $this->_user = User::findByUsername($this->username);
        }
        return $this->_user;
    }
}
