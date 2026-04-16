<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\User $user */
/** @var yii\rbac\Role[] $roles */

use app\models\User;
use yii\helpers\Html;

$this->title = $user->username;
$isSelf = ($user->id === (int)\Yii::$app->user?->id);
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Users', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($user->username) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($user->username) ?></h2>
    <div>
        <?php if (\Yii::$app->user?->can('user.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $user->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php endif; ?>
        <?php if (!$isSelf && \Yii::$app->user?->can('user.delete')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['toggle-status', 'id' => $user->id]) ?>" style="display:inline" onsubmit="return confirm('Change status?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-warning ms-1"><?= $user->status === User::STATUS_ACTIVE ? 'Deactivate' : 'Activate' // xss-ok: hardcoded strings?></button>
            </form>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $user->id]) ?>" style="display:inline" onsubmit="return confirm('Permanently delete this user?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Email</dt>
                    <dd class="col-7"><?= Html::encode($user->email) ?></dd>
                    <dt class="col-5">Source</dt>
                    <dd class="col-7">
                        <?php if ($user->isLdap()) : ?>
                            <span class="badge text-bg-info">LDAP / Active Directory</span>
                        <?php else : ?>
                            <span class="badge text-bg-secondary">Local</span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($user->isLdap()) : ?>
                        <?php if ($user->ldap_dn !== null && $user->ldap_dn !== '') : ?>
                            <dt class="col-5">LDAP DN</dt>
                            <dd class="col-7"><code class="small"><?= Html::encode($user->ldap_dn) ?></code></dd>
                        <?php endif; ?>
                        <?php if ($user->ldap_uid !== null && $user->ldap_uid !== '') : ?>
                            <dt class="col-5">Directory UID</dt>
                            <dd class="col-7"><code class="small"><?= Html::encode($user->ldap_uid) ?></code></dd>
                        <?php endif; ?>
                        <dt class="col-5">Last synced</dt>
                        <dd class="col-7">
                            <?php if ($user->last_synced_at !== null) : ?>
                                <?= date('Y-m-d H:i', $user->last_synced_at) ?>
                            <?php else : ?>
                                <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <?php if ($user->status === User::STATUS_ACTIVE) : ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php else : ?>
                            <span class="badge text-bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Superadmin</dt>
                    <dd class="col-7"><?= $user->is_superadmin ? '<span class="badge text-bg-warning">Yes</span>' : 'No' // xss-ok: hardcoded strings?></dd>
                    <dt class="col-5">Roles</dt>
                    <dd class="col-7">
                        <?php foreach ($roles as $role) : ?>
                            <span class="badge text-bg-primary"><?= Html::encode($role->name) ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($roles)) :
                            ?><span class="text-muted">None</span><?php
                        endif; ?>
                    </dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $user->created_at) ?></dd>
                    <dt class="col-5">Updated</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $user->updated_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>
