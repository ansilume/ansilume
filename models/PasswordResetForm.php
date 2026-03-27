<?php

declare(strict_types=1);

namespace app\models;

use yii\base\InvalidArgumentException;
use yii\base\Model;

/**
 * Form model for setting a new password via a valid reset token.
 */
class PasswordResetForm extends Model
{
    public string $password = '';
    public string $password_confirm = '';

    private User $_user;

    public function __construct(string $token, array $config = [])
    {
        $user = User::findByPasswordResetToken($token);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid or expired password reset token.');
        }
        $this->_user = $user;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['password', 'password_confirm'], 'required'],
            [['password'], 'string', 'min' => 8, 'max' => 72],
            [['password_confirm'], 'compare', 'compareAttribute' => 'password', 'message' => 'Passwords do not match.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'password' => 'New Password',
            'password_confirm' => 'Confirm Password',
        ];
    }

    /**
     * Set the new password and clear the reset token.
     */
    public function resetPassword(): bool
    {
        $this->_user->setPassword($this->password);
        $this->_user->removePasswordResetToken();
        return $this->_user->save(false);
    }

    public function getUser(): User
    {
        return $this->_user;
    }
}
