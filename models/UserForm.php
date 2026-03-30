<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

/**
 * Form model for creating and editing users.
 * Keeps password handling separate from the User ActiveRecord.
 */
class UserForm extends Model
{
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'viewer';
    public int $status = User::STATUS_ACTIVE;
    public bool $is_superadmin = false;

    private ?User $_user = null;

    public static function fromUser(User $user): self
    {
        $form = new self();
        $form->_user = $user;
        $form->username = $user->username;
        $form->email = $user->email;
        $form->status = $user->status;
        $form->is_superadmin = (bool)$user->is_superadmin;

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRolesByUser($user->id);
        if (!empty($roles)) {
            $form->role = array_key_first($roles);
        }

        return $form;
    }

    public function rules(): array
    {
        $rules = [
            [['username', 'email', 'role'], 'required'],
            [['username'], 'string', 'max' => 64],
            [['email'], 'email'],
            [['password'], 'string', 'min' => 8],
            [['status'], 'in', 'range' => [User::STATUS_INACTIVE, User::STATUS_ACTIVE]],
            [['is_superadmin'], 'boolean'],
            [['role'], 'in', 'range' => array_keys(self::roleOptions())],
        ];

        if ($this->_user === null) {
            // Password required for new users
            $rules[] = [['password'], 'required'];
            $rules[] = [['username'], 'unique', 'targetClass' => User::class];
            $rules[] = [['email'], 'unique', 'targetClass' => User::class];
        } else {
            // Unique check must exclude the current user
            $rules[] = [['username'], 'unique', 'targetClass' => User::class,
                'filter' => ['!=', 'id', $this->_user->id]];
            $rules[] = [['email'], 'unique', 'targetClass' => User::class,
                'filter' => ['!=', 'id', $this->_user->id]];
        }

        return $rules;
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'email' => 'Email',
            'password' => 'Password',
            'role' => 'Role',
            'status' => 'Status',
            'is_superadmin' => 'Superadmin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            'viewer' => 'Viewer (read-only)',
            'operator' => 'Operator (launch + manage)',
            'admin' => 'Admin (full access)',
        ];
    }

    /**
     * Save the user and assign the selected role.
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $isNew = ($this->_user === null);
        $user = $this->_user ?? new User();

        $user->username = $this->username;
        $user->email = $this->email;
        $user->status = $this->status;
        $user->is_superadmin = $this->is_superadmin;

        if ($this->password !== '') {
            $user->setPassword($this->password);
        }
        if ($isNew) {
            $user->generateAuthKey();
        }

        if (!$user->save(false)) {
            foreach ($user->errors as $attr => $errs) {
                foreach ($errs as $err) {
                    $this->addError($attr, $err);
                }
            }
            return false;
        }

        $this->_user = $user;
        $this->syncRole($user);
        return true;
    }

    public function getUser(): ?User
    {
        return $this->_user;
    }

    private function syncRole(User $user): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $auth->revokeAll($user->id);

        $role = $auth->getRole($this->role);
        if ($role !== null) {
            $auth->assign($role, $user->id);
        }
    }
}
