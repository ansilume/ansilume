<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\User;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Users';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Users</h2>
    <?php if (\Yii::$app->user?->can('user.create')) : ?>
        <?= Html::a('New User', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php if (empty($dataProvider->getModels())) : ?>
    <p class="text-muted">No users yet.</p>
<?php else : ?>
<div class="mb-2">
    <input type="text" class="form-control form-control-sm" placeholder="Filter users…"
           data-table-filter="user-table" style="max-width:300px">
</div>
<div class="table-responsive">
    <table class="table table-hover" id="user-table">
        <thead class="table-light">
            <tr><th>#</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>MFA</th><th>Superadmin</th><th>Created</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($dataProvider->getModels() as $user) : ?>
            <?php
            /** @var \yii\rbac\ManagerInterface $auth */
            $auth = \Yii::$app->authManager;
            $roles = $auth->getRolesByUser($user->id);
            ?>
            <tr>
                <td><?= $user->id ?></td>
                <td><?= Html::a(Html::encode($user->username), ['view', 'id' => $user->id]) ?></td>
                <td><?= Html::encode($user->email) ?></td>
                <td>
                    <?php foreach ($roles as $role) : ?>
                        <span class="badge text-bg-secondary"><?= Html::encode($role->name) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($roles)) :
                        ?><span class="text-muted">—</span><?php
                    endif; ?>
                </td>
                <td>
                    <?php if ($user->status === User::STATUS_ACTIVE) : ?>
                        <span class="badge text-bg-success">Active</span>
                    <?php else : ?>
                        <span class="badge text-bg-secondary">Inactive</span>
                    <?php endif; ?>
                </td>
                <td><?= $user->totp_enabled ? '<span class="badge text-bg-info">TOTP</span>' : '<span class="text-muted">—</span>' // xss-ok: hardcoded strings?></td>
                <td><?= $user->is_superadmin ? '<span class="badge text-bg-warning">Yes</span>' : '—' // xss-ok: hardcoded strings?></td>
                <td><?= date('Y-m-d', $user->created_at) ?></td>
                <td class="text-end text-nowrap">
                    <?= Html::a('View', ['view', 'id' => $user->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                    <?php if (\Yii::$app->user?->can('user.update')) : ?>
                        <?= Html::a('Edit', ['update', 'id' => $user->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
