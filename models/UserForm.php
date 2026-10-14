<?php

declare(strict_types=1);

namespace app\models;

use app\services\AssignedRoles;
use app\services\RoleGrantPolicy;
use app\services\UserPrivileges;
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
 *
 * What the user who saves may give (RoleGrantPolicy): the Role field offers
 * the roles they may assign, and the user's role; another role is refused
 * with an error on the field that says why (RoleGrantPolicy::refusalOf()):
 * the admin role, and a role that contains it, only from a superadmin or a
 * holder of the admin role. Only a superadmin's form takes the Superadmin
 * field: for anyone else a posted value is ignored. Saving with the role the
 * form shows, a role assigned to the user, leaves every role assigned to the
 * user as it is; another role replaces them all.
 */
class UserForm extends Model
{
    /**
     * The hint of the Role field for a user with several assigned roles. %s:
     * their names, sorted by name and joined with ', '.
     */
    public const SEVERAL_ROLES_HINT = 'Assigned roles: %s. Saving with the role shown keeps all of them; another role replaces them all.';

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

    private readonly RoleGrantPolicy $grants;

    /** @var list<string> the roles assigned to the edited user, sorted by name; [] for a new user */
    private array $assignedRoles = [];

    /** The role the form showed as the user's when it was built; null for a new user. */
    private ?string $shownRole = null;

    /**
     * @var array{roles?: array{from: list<string>|null, to: list<string>}, is_superadmin?: array{from: bool|null, to: bool}}
     *     what save() changed (UserPrivileges::changesSince())
     */
    private array $privilegeChanges = [];

    /**
     * @param RoleGrantPolicy|null $grants what the user who saves may give; null: what the signed-in user may
     *     give (RoleGrantPolicy::forCurrentUser()), which is nothing without one
     * @param array<string, mixed> $config
     */
    public function __construct(?RoleGrantPolicy $grants = null, array $config = [])
    {
        $this->grants = $grants ?? RoleGrantPolicy::forCurrentUser();
        parent::__construct($config);
    }

    /**
     * The edit form of $user. Role is the role assigned to the user, the
     * first by name when several are, and viewer when none is: never a
     * default role, which every user holds (AssignedRoles). Saved with an
     * assigned role it shows, the form keeps every role assigned to the
     * user; the viewer choice of a user without one assigns viewer.
     */
    public static function fromUser(User $user, ?RoleGrantPolicy $grants = null): self
    {
        $form = new self($grants);
        $form->_user = $user;
        $form->username = $user->username;
        $form->email = $user->email;
        $form->status = $user->status;
        $form->is_superadmin = (bool)$user->is_superadmin;
        $form->auth_source = $user->auth_source;
        $form->ldap_dn = (string)$user->ldap_dn;
        $form->ldap_uid = (string)$user->ldap_uid;
        $form->assignedRoles = AssignedRoles::fromApp()->of((int)$user->id);
        $form->role = $form->assignedRoles[0] ?? $form->role;
        $form->shownRole = $form->role;

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
            [['role'], 'in', 'range' => array_keys(self::roleOptions())],
            [['role'], 'validateRoleGrant'],
            [['auth_source'], 'in', 'range' => [User::AUTH_SOURCE_LOCAL, User::AUTH_SOURCE_LDAP]],
            [['ldap_dn'], 'string', 'max' => 512],
            [['ldap_uid'], 'string', 'max' => 255],
        ];
        if ($this->grants->isSuperadmin()) {
            // Only a superadmin's form takes the field: for anyone else it is
            // no attribute load() sets, so a posted value is ignored.
            $rules[] = [['is_superadmin'], 'boolean'];
        }

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
     * The choices of the Role field: the roles the user who saves may
     * assign, and the role the form showed as the user's, also where they
     * may not assign it, so that the form can be saved unchanged.
     *
     * @return array<string, string> role name => label, in the order of roleOptions()
     */
    public function roleChoices(): array
    {
        return $this->grants->assignable(self::roleOptions(), $this->shownRole);
    }

    /**
     * The hint of the Role field: for a user with several assigned roles
     * (SEVERAL_ROLES_HINT), that saving with the role shown keeps them all;
     * null otherwise.
     */
    public function roleHint(): ?string
    {
        if (count($this->assignedRoles) < 2) {
            return null;
        }

        return sprintf(self::SEVERAL_ROLES_HINT, implode(', ', $this->assignedRoles));
    }

    /**
     * Whether the form takes the Superadmin field: only from a superadmin.
     */
    public function canSetSuperadmin(): bool
    {
        return $this->grants->isSuperadmin();
    }

    /**
     * Inline validator of Role: a role the form does not keep is one the
     * user who saves may assign, else the error says why
     * (RoleGrantPolicy::refusalOf(): ROLE_REFUSED, or ADMIN_ROLE_REFUSED for
     * the admin role or a role that contains it).
     */
    public function validateRoleGrant(string $attribute): void
    {
        $refusal = $this->keepsRoles() ? null : $this->grants->refusalOf($this->role);
        if ($refusal !== null) {
            $this->addError($attribute, $refusal);
        }
    }

    /**
     * What the last successful save() changed of the user's roles and
     * superadmin flag, as user.created and user.updated record it
     * (UserPrivileges::changesSince()); [] before.
     *
     * @return array{roles?: array{from: list<string>|null, to: list<string>}, is_superadmin?: array{from: bool|null, to: bool}}
     */
    public function privilegeChanges(): array
    {
        return $this->privilegeChanges;
    }

    /**
     * Save the user and assign the selected role, unless the form keeps the
     * user's roles (keepsRoles()). The superadmin flag changes only when a
     * superadmin saves.
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $isNew = ($this->_user === null);
        $user = $this->_user ?? new User();
        $before = $isNew ? null : new UserPrivileges($this->assignedRoles, (bool)$user->is_superadmin);

        $user->username = $this->username;
        $user->email = $this->email;
        $user->status = $this->status;
        $user->is_superadmin = $this->grants->isSuperadmin() ? $this->is_superadmin : (bool)$user->is_superadmin;

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
        $this->privilegeChanges = UserPrivileges::of($user)->changesSince($before);
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

    /**
     * Whether saving keeps every role assigned to the user: Role is the role
     * the form showed, and that role is assigned to the user (not the viewer
     * choice of a user without an assigned role).
     */
    private function keepsRoles(): bool
    {
        return $this->role === ($this->assignedRoles[0] ?? null);
    }

    /**
     * Replaces the roles assigned to $user with Role, unless the form keeps
     * them (keepsRoles()).
     */
    private function syncRole(User $user): void
    {
        if ($this->keepsRoles()) {
            return;
        }
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $auth->revokeAll($user->id);

        $role = $auth->getRole($this->role);
        if ($role !== null) {
            $auth->assign($role, $user->id);
        }
    }
}
