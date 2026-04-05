<?php

declare(strict_types=1);

namespace app\models;

use app\helpers\PermissionCatalog;
use yii\base\Model;

/**
 * Form model for creating and editing RBAC roles.
 *
 * Custom roles are flat: they reference permissions directly and do not
 * inherit from other roles. Built-in roles (viewer/operator/admin) keep
 * their nested hierarchy internally — the form only edits their directly
 * attached children.
 */
class RoleForm extends Model
{
    public const RESERVED_NAMES = ['superadmin'];

    public string $name = '';
    public string $description = '';
    /** @var string[] */
    public array $permissions = [];

    /**
     * If true, the role being edited is a system role — the name field is
     * read-only and attempts to change it are silently ignored.
     */
    public bool $isSystemRole = false;

    /**
     * Original name when editing; null when creating.
     */
    public ?string $originalName = null;

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 40],
            [['name'], 'match', 'pattern' => '/^[a-z][a-z0-9_-]{2,39}$/',
                'message' => 'Name must start with a lowercase letter and may contain '
                    . 'lowercase letters, digits, hyphens, and underscores (3–40 characters).'],
            [['name'], 'validateNotReserved'],
            [['name'], 'validateUnique'],
            [['description'], 'string', 'max' => 255],
            [['permissions'], 'each', 'rule' => ['string']],
            [['permissions'], 'validatePermissionsExist'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Name',
            'description' => 'Description',
            'permissions' => 'Permissions',
        ];
    }

    public function validateNotReserved(string $attribute): void
    {
        if (in_array($this->{$attribute}, self::RESERVED_NAMES, true)) {
            $this->addError($attribute, 'This name is reserved.');
        }
    }

    public function validateUnique(string $attribute): void
    {
        $name = (string)$this->{$attribute};
        if ($name === '') {
            return;
        }
        // Only check uniqueness if the name has actually changed
        if ($this->originalName !== null && $name === $this->originalName) {
            return;
        }
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        if ($auth->getRole($name) !== null || $auth->getPermission($name) !== null) {
            $this->addError($attribute, 'A role or permission with this name already exists.');
        }
    }

    public function validatePermissionsExist(string $attribute): void
    {
        $known = PermissionCatalog::allPermissionNames();
        foreach ($this->permissions as $p) {
            if (!in_array($p, $known, true)) {
                $this->addError($attribute, 'Unknown permission: ' . $p);
                return;
            }
        }
    }
}
