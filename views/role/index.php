<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, array{name: string, description: string, isSystem: bool, permissionCount: int, userCount: int}> $roles */

use yii\helpers\Html;

$this->title = 'Roles';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <?php if (Yii::$app->user->can('role.create')) : ?>
        <?= Html::a('New Role', ['create'], ['class' => 'btn btn-primary btn-sm']) ?>
    <?php endif; ?>
</div>

<p class="text-muted">
    Roles control what users can do. Built-in roles (viewer, operator, admin) cannot be renamed
    or deleted, but their permissions can be adjusted. Custom roles are flat — they carry their
    own set of permissions and do not inherit from anything.
</p>

<?php if (empty($roles)) : ?>
    <div class="text-muted">No roles defined.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Type</th>
                <th class="text-end">Permissions</th>
                <th class="text-end">Users</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($roles as $row) : ?>
                <tr>
                    <td>
                        <?= Html::a(
                            Html::encode($row['name']),
                            ['view', 'name' => $row['name']]
                        ) ?>
                    </td>
                    <td><?= Html::encode($row['description']) ?></td>
                    <td>
                        <?php if ($row['isSystem']) : ?>
                            <span class="badge bg-secondary">system</span>
                        <?php else : ?>
                            <span class="badge bg-info">custom</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= Html::encode((string)$row['permissionCount']) ?></td>
                    <td class="text-end"><?= Html::encode((string)$row['userCount']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
