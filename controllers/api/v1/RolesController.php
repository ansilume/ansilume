<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\helpers\PermissionCatalog;
use app\models\RoleForm;
use app\services\RoleChangePolicy;
use app\services\RoleChangeRefusedException;
use app\services\RoleService;
use yii\web\NotFoundHttpException;

/**
 * API v1: RBAC role management.
 *
 * All endpoints require API token authentication via BaseApiController.
 * Role.* permissions are enforced per-action.
 *
 * What the caller may change (RoleChangePolicy), for a caller who is no
 * superadmin: PUT and DELETE of a role they may not assign answer 403
 * (RoleChangePolicy::CHANGE_REFUSED), after the 404 of a role that does not
 * exist and before every other check; POST of a role, and PUT that adds to
 * one, with a permission they do not hold answer 403
 * (RoleChangePolicy::PERMISSION_REFUSED, naming the first such permission),
 * after the 422 of a body that does not validate. Each writes nothing.
 */
class RolesController extends BaseApiController
{
    protected function apiAccessRules(): array
    {
        return [
            'index' => 'role.view',
            'view' => 'role.view',
            'permissions' => 'role.view',
            'create' => 'role.create',
            'update' => 'role.update',
            'delete' => 'role.delete',
        ];
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        if (!$this->userCan('role.view')) {
            return $this->forbidden();
        }

        $roles = $this->service()->listRoles();
        $out = array_map(fn (array $r): array => $this->serializeSummary($r), $roles);
        return $this->success($out);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(string $name): array
    {
        if (!$this->userCan('role.view')) {
            return $this->forbidden();
        }

        $role = $this->service()->getRole($name);
        if ($role === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }
        return $this->success($this->serializeFull($role));
    }

    /**
     * Creates the role: 422 for a body that does not validate, then 403
     * (RoleChangePolicy::PERMISSION_REFUSED) for a permission the caller may
     * not grant.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        if (!$this->userCan('role.create')) {
            return $this->forbidden();
        }

        $form = new RoleForm();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($form, $body);

        $policy = RoleChangePolicy::forCurrentUser();
        $err = $this->refusedSave($form, fn (): bool => $this->service()->createRole($form, $policy));
        if ($err !== null) {
            return $err;
        }

        $role = $this->service()->getRole($form->name);
        if ($role === null) {
            return $this->error('Role disappeared after create.', 500);
        }
        return $this->success($this->serializeFull($role), 201);
    }

    /**
     * Updates the role: 404, then 403 (RoleChangePolicy::CHANGE_REFUSED) for
     * a role the caller may not change, then 422 for a body that does not
     * validate, then 403 (PERMISSION_REFUSED) for an added permission the
     * caller may not grant.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(string $name): array
    {
        if (!$this->userCan('role.update')) {
            return $this->forbidden();
        }

        $svc = $this->service();
        $policy = RoleChangePolicy::forCurrentUser();
        $err = $this->refuseChange($policy, $name);
        if ($err !== null) {
            return $err;
        }

        $form = new RoleForm();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($form, $body);

        $err = $this->refusedSave($form, fn (): bool => $svc->updateRole($name, $form, $policy));
        if ($err !== null) {
            return $err;
        }

        /** @var array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $role */
        $role = $svc->getRole($name);
        return $this->success($this->serializeFull($role));
    }

    /**
     * Deletes the role: 404, then 403 (RoleChangePolicy::CHANGE_REFUSED) for
     * a role the caller may not change, then 422 for a system role.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(string $name): array
    {
        if (!$this->userCan('role.delete')) {
            return $this->forbidden();
        }

        $svc = $this->service();
        $policy = RoleChangePolicy::forCurrentUser();
        $err = $this->refuseChange($policy, $name);
        if ($err !== null) {
            return $err;
        }
        if ($svc->isSystemRole($name)) {
            return $this->error('Cannot delete system role.', 422);
        }
        if (!$svc->deleteRole($name, $policy)) {
            return $this->error('Role could not be deleted.', 422);
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionPermissions(): array
    {
        if (!$this->userCan('role.view')) {
            return $this->forbidden();
        }

        return $this->success(PermissionCatalog::groups());
    }

    // -- Helpers --------------------------------------------------------------

    private function service(): RoleService
    {
        /** @var RoleService $svc */
        $svc = \Yii::$app->get('roleService');
        return $svc;
    }

    /**
     * @return array{error: array{message: string}}
     */
    private function forbidden(): array
    {
        return $this->error('Forbidden.', 403);
    }

    /**
     * 404 for a role that does not exist, then 403
     * (RoleChangePolicy::CHANGE_REFUSED) when the caller may not change the
     * role $name; null when they may.
     *
     * @return array{error: array{message: string}}|null
     * @throws NotFoundHttpException
     */
    private function refuseChange(RoleChangePolicy $policy, string $name): ?array
    {
        if ($this->service()->getRole($name) === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }
        if ($policy->mayChange($name)) {
            return null;
        }

        return $this->error(RoleChangePolicy::CHANGE_REFUSED, 403);
    }

    /**
     * Saves the role with $save: null when saved, 422 with the form's first
     * error when it did not validate, 403 with the refusal when the caller
     * may not grant a permission (RoleChangeRefusedException).
     *
     * @param callable(): bool $save
     * @return array{error: array{message: string}}|null
     */
    private function refusedSave(RoleForm $form, callable $save): ?array
    {
        try {
            if ($save()) {
                return null;
            }
        } catch (RoleChangeRefusedException $e) {
            return $this->error($e->getMessage(), 403);
        }

        return $this->error($this->firstError($form), 422);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(RoleForm $form, array $body): void
    {
        if (array_key_exists('name', $body)) {
            $form->name = (string)$body['name'];
        }
        if (array_key_exists('description', $body)) {
            $form->description = (string)$body['description'];
        }
        if (array_key_exists('permissions', $body) && is_array($body['permissions'])) {
            $form->permissions = array_values(array_map('strval', $body['permissions']));
        }
    }

    private function firstError(RoleForm $form): string
    {
        foreach ($form->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }

    /**
     * @param array{name: string, description: string, isSystem: bool, permissionCount: int, userCount: int} $r
     * @return array<string, mixed>
     */
    private function serializeSummary(array $r): array
    {
        return [
            'name' => $r['name'],
            'description' => $r['description'],
            'is_system' => $r['isSystem'],
            'permission_count' => $r['permissionCount'],
            'user_count' => $r['userCount'],
        ];
    }

    /**
     * @param array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $r
     * @return array<string, mixed>
     */
    private function serializeFull(array $r): array
    {
        return [
            'name' => $r['name'],
            'description' => $r['description'],
            'is_system' => $r['isSystem'],
            'direct_permissions' => $r['directPermissions'],
            'effective_permissions' => $r['effectivePermissions'],
            'user_ids' => $r['userIds'],
        ];
    }
}
