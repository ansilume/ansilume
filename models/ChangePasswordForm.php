<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

/**
 * Form model for changing the current user's password.
 *
 * Requires the current password for verification before accepting a new one.
 */
class ChangePasswordForm extends Model
{
    public string $current_password = '';
    public string $new_password = '';
    public string $new_password_confirm = '';

    private User $_user;

    public function __construct(User $user, array $config = [])
    {
        $this->_user = $user;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['current_password', 'new_password', 'new_password_confirm'], 'required'],
            [['current_password'], 'validateNotLdap'],
            [['current_password'], 'validateCurrentPassword'],
            [['new_password'], 'string', 'min' => 8, 'max' => 72],
            [['new_password_confirm'], 'compare', 'compareAttribute' => 'new_password', 'message' => 'Passwords do not match.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'current_password' => 'Current Password',
            'new_password' => 'New Password',
            'new_password_confirm' => 'Confirm New Password',
        ];
    }

    public function validateCurrentPassword(string $attribute): void
    {
        if (!$this->hasErrors() && !$this->_user->validatePassword($this->current_password)) {
            $this->addError($attribute, 'Current password is incorrect.');
        }
    }

    /**
     * Block password change for LDAP-managed accounts. The directory owns
     * the credential — letting users edit it locally would create the
     * misleading impression that it sticks.
     */
    public function validateNotLdap(string $attribute): void
    {
        if ($this->_user->isLdap()) {
            $this->addError(
                $attribute,
                'This account is managed by an external directory. Change your password there.',
            );
        }
    }

    /**
     * Apply the new password to the user record.
     */
    public function changePassword(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->_user->setPassword($this->new_password);
        return $this->_user->save(false);
    }

    public function getUser(): User
    {
        return $this->_user;
    }
}
