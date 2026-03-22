<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Credential $model */
/** @var array|null $sshInfo  SSH key metadata or null for non-SSH credentials */

use app\models\Credential;
use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Credentials', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('credential.update')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('credential.delete')): ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Delete this credential?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><span class="badge text-bg-secondary"><?= Html::encode(Credential::typeLabel($model->credential_type)) ?></span></dd>
                    <?php if ($model->credential_type === Credential::TYPE_SSH_KEY && $sshInfo): ?>
                        <dt class="col-5">Algorithm</dt>
                        <dd class="col-7">
                            <?php if ($sshInfo['algorithm'] && $sshInfo['algorithm'] !== 'unknown'): ?>
                                <code><?= Html::encode(strtoupper($sshInfo['algorithm'])) ?><?= $sshInfo['bits'] ? '-' . $sshInfo['bits'] : '' ?></code>
                                <?php if ($sshInfo['key_secure'] === false): ?>
                                    <span class="badge text-bg-danger ms-1">Insecure</span>
                                <?php elseif ($sshInfo['key_secure'] === null): ?>
                                    <span class="badge text-bg-secondary ms-1">Unknown</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ($model->username): ?>
                    <dt class="col-5">Username</dt>
                    <dd class="col-7"><?= Html::encode($model->username) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5">Private Key</dt>
                    <dd class="col-7"><span class="text-muted small">***REDACTED***</span></dd>
                    <dt class="col-5">Created by</dt>
                    <dd class="col-7"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
                    <dt class="col-5">Updated</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->updated_at) ?></dd>
                </dl>
            </div>
        </div>
        <?php if ($model->description): ?>
        <div class="card mt-3">
            <div class="card-header">Description</div>
            <div class="card-body"><?= nl2br(Html::encode($model->description)) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($model->credential_type === Credential::TYPE_SSH_KEY): ?>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Public Key</span>
                <?php if ($sshInfo && $sshInfo['public_key']): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="navigator.clipboard.writeText(document.getElementById('pubkey-display').value)">Copy</button>
                <?php endif; ?>
            </div>
            <?php if ($sshInfo && $sshInfo['public_key']): ?>
                <div class="card-body p-0">
                    <textarea id="pubkey-display" class="form-control font-monospace border-0 rounded-0"
                              rows="3" readonly style="background:transparent;resize:none;"><?= Html::encode($sshInfo['public_key']) ?></textarea>
                </div>
                <div class="card-footer text-muted small">
                    Add this public key as a Deploy Key on GitHub / GitLab, or to <code>~/.ssh/authorized_keys</code> on the target host.
                </div>
            <?php else: ?>
                <div class="card-body text-muted small">
                    Public key not available. Re-save this credential to derive and store the public key automatically.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
