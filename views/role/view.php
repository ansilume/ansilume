<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $role */
/** @var \app\models\User[] $users */

use app\helpers\PermissionCatalog;
use yii\helpers\Html;

$this->title = 'Role: ' . $role['name'];
$directSet = array_flip($role['directPermissions']);
$inherited = array_diff($role['effectivePermissions'], $role['directPermissions']);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>
        <?= Html::encode($role['name']) ?>
        <?php if ($role['isSystem']) : ?>
            <span class="badge bg-secondary align-middle">system</span>
        <?php else : ?>
            <span class="badge bg-info align-middle">custom</span>
        <?php endif; ?>
    </h2>
    <div>
        <?php if (Yii::$app->user->can('role.update')) : ?>
            <?= Html::a('Edit', ['update', 'name' => $role['name']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (!$role['isSystem'] && Yii::$app->user->can('role.delete')) : ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'name' => $role['name']]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Delete role &quot;<?= Html::encode($role['name']) ?>&quot;? Users holding this role will be left without a role.')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($role['description'] !== '') : ?>
    <p class="text-muted"><?= Html::encode($role['description']) ?></p>
<?php endif; ?>

<h4 class="mt-4">Direct Permissions</h4>
<?php if (empty($role['directPermissions'])) : ?>
    <div class="text-muted">No permissions attached directly.</div>
<?php else : ?>
    <div class="row">
        <?php foreach (PermissionCatalog::groups() as $group) : ?>
            <?php
            $matches = [];
            foreach ($group['permissions'] as $perm) {
                if (isset($directSet[$perm['name']])) {
                    $matches[] = $perm;
                }
            }
            if (empty($matches)) {
                continue;
            }
            ?>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header py-2"><strong><?= Html::encode($group['label']) ?></strong></div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($matches as $perm) : ?>
                            <li class="list-group-item py-1">
                                <?= Html::encode($perm['label']) ?>
                                <code class="text-muted small ms-1"><?= Html::encode($perm['name']) ?></code>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($inherited)) : ?>
    <h4 class="mt-4">Inherited Permissions</h4>
    <p class="text-muted small">
        These come from child roles in the hierarchy (system roles only).
    </p>
    <ul class="list-inline">
        <?php foreach ($inherited as $permName) : ?>
            <li class="list-inline-item">
                <span class="badge bg-light text-dark border">
                    <?= Html::encode(PermissionCatalog::labelFor($permName)) ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h4 class="mt-4">Users</h4>
<?php if (empty($users)) : ?>
    <div class="text-muted">No users currently assigned to this role.</div>
<?php else : ?>
    <ul class="list-inline">
        <?php foreach ($users as $user) : ?>
            <li class="list-inline-item">
                <span class="badge bg-light text-dark border"><?= Html::encode($user->username) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
