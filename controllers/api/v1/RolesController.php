<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\helpers\PermissionCatalog;
use app\models\RoleForm;
use app\services\RoleService;
use yii\web\NotFoundHttpException;

/**
 * API v1: RBAC role management.
 *
 * All endpoints require API token authentication via BaseApiController.
 * Role.* permissions are enforced per-action.
 */
class RolesController extends BaseApiController
{
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        if (!$this->requirePermission('role.view')) {
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
        if (!$this->requirePermission('role.view')) {
            return $this->forbidden();
        }

        $role = $this->service()->getRole($name);
        if ($role === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }
        return $this->success($this->serializeFull($role));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        if (!$this->requirePermission('role.create')) {
            return $this->forbidden();
        }

        $form = new RoleForm();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($form, $body);

        if (!$this->service()->createRole($form, (int)\Yii::$app->user->id)) {
            return $this->error($this->firstError($form), 422);
        }

        $role = $this->service()->getRole($form->name);
        if ($role === null) {
            return $this->error('Role disappeared after create.', 500);
        }
        return $this->success($this->serializeFull($role), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(string $name): array
    {
        if (!$this->requirePermission('role.update')) {
            return $this->forbidden();
        }

        $svc = $this->service();
        if ($svc->getRole($name) === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }

        $form = new RoleForm();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($form, $body);

        if (!$svc->updateRole($name, $form, (int)\Yii::$app->user->id)) {
            return $this->error($this->firstError($form), 422);
        }

        /** @var array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $role */
        $role = $svc->getRole($name);
        return $this->success($this->serializeFull($role));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(string $name): array
    {
        if (!$this->requirePermission('role.delete')) {
            return $this->forbidden();
        }

        $svc = $this->service();
        if ($svc->isSystemRole($name)) {
            return $this->error('Cannot delete system role.', 422);
        }
        if ($svc->getRole($name) === null) {
            throw new NotFoundHttpException("Role \"{$name}\" not found.");
        }
        if (!$svc->deleteRole($name, (int)\Yii::$app->user->id)) {
            return $this->error('Role could not be deleted.', 422);
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionPermissions(): array
    {
        if (!$this->requirePermission('role.view')) {
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

    private function requirePermission(string $permission): bool
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        return $user->can($permission);
    }

    /**
     * @return array{error: array{message: string}}
     */
    private function forbidden(): array
    {
        return $this->error('Forbidden.', 403);
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
