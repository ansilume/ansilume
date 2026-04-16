<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

/**
 * Form model for creating and editing users.
 * Keeps password handling separate from the User ActiveRecord.
 *
 * Supports both local accounts (bcrypt password) and LDAP-backed accounts
 * (no local password — credentials live in the directory). The auth_source
 * is chosen at creation time and is **immutable** afterwards: switching an
 * existing local user to LDAP would orphan their password hash, and the
 * reverse would let a directory-managed account log in with whatever local
 * password was last set. Either direction is a footgun, so we forbid it.
 */
class UserForm extends Model
{
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $role = 'viewer';
    public int $status = User::STATUS_ACTIVE;
    public bool $is_superadmin = false;
    public string $auth_source = User::AUTH_SOURCE_LOCAL;
    public string $ldap_dn = '';
    public string $ldap_uid = '';

    private ?User $_user = null;

    public static function fromUser(User $user): self
    {
        $form = new self();
        $form->_user = $user;
        $form->username = $user->username;
        $form->email = $user->email;
        $form->status = $user->status;
        $form->is_superadmin = (bool)$user->is_superadmin;
        $form->auth_source = $user->auth_source;
        $form->ldap_dn = (string)$user->ldap_dn;
        $form->ldap_uid = (string)$user->ldap_uid;

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
            [['auth_source'], 'in', 'range' => [User::AUTH_SOURCE_LOCAL, User::AUTH_SOURCE_LDAP]],
            [['ldap_dn'], 'string', 'max' => 512],
            [['ldap_uid'], 'string', 'max' => 255],
        ];

        if ($this->_user === null) {
            // Password is required for new local users only — LDAP users
            // never carry a local password hash that could be set here.
            if ($this->auth_source === User::AUTH_SOURCE_LOCAL) {
                $rules[] = [['password'], 'required'];
            }
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
            'auth_source' => 'Authentication source',
            'ldap_dn' => 'LDAP DN',
            'ldap_uid' => 'LDAP UID',
        ];
    }

    /**
     * Dynamic list of assignable roles. Built from the RBAC auth manager so
     * custom roles created via the role management UI appear automatically.
     * The three built-in roles always come first in a fixed order; any
     * additional custom roles follow alphabetically.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        $builtinLabels = [
            'viewer' => 'Viewer (read-only)',
            'operator' => 'Operator (launch + manage)',
            'admin' => 'Admin (full access)',
        ];

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $roles = $auth->getRoles();

        $options = [];
        foreach ($builtinLabels as $name => $label) {
            if (isset($roles[$name])) {
                $options[$name] = $label;
            }
        }

        $custom = [];
        foreach ($roles as $name => $role) {
            if (isset($builtinLabels[$name])) {
                continue;
            }
            /** @var \yii\rbac\Role $role */
            $label = $role->description !== null && $role->description !== ''
                ? $name . ' — ' . $role->description
                : $name;
            $custom[$name] = $label;
        }
        ksort($custom);

        return $options + $custom;
    }

    /**
     * Available auth sources for the create form. Editing an existing user
     * does not show a dropdown — auth_source is immutable post-creation.
     *
     * @return array<string, string>
     */
    public static function authSourceOptions(): array
    {
        return [
            User::AUTH_SOURCE_LOCAL => 'Local (password stored in Ansilume)',
            User::AUTH_SOURCE_LDAP => 'LDAP / Active Directory (managed externally)',
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

        if ($isNew) {
            $this->initialiseAuthSource($user);
            $user->generateAuthKey();
        } elseif ($user->isLocal() && $this->password !== '') {
            // Edit flow: password change is only meaningful for local accounts.
            // LDAP users have no local password to change; the form hides
            // the field, and even if it were posted we ignore it here.
            $user->setPassword($this->password);
        }

        if (!$user->save(false)) {
            $this->copyModelErrors($user);
            return false;
        }

        $this->_user = $user;
        $this->syncRole($user);
        return true;
    }

    /**
     * On insert, lock in auth_source and seed source-specific fields.
     * Called only for fresh User records — never on update.
     */
    private function initialiseAuthSource(User $user): void
    {
        if ($this->auth_source === User::AUTH_SOURCE_LDAP) {
            $user->markAsLdapManaged();
            $user->ldap_dn = $this->ldap_dn !== '' ? $this->ldap_dn : null;
            $user->ldap_uid = $this->ldap_uid !== '' ? $this->ldap_uid : null;
            return;
        }
        $user->auth_source = User::AUTH_SOURCE_LOCAL;
        if ($this->password !== '') {
            $user->setPassword($this->password);
        }
    }

    private function copyModelErrors(User $user): void
    {
        foreach ($user->errors as $attr => $errs) {
            foreach ($errs as $err) {
                $this->addError($attr, $err);
            }
        }
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
