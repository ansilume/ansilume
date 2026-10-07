<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Credential $model */
/** @var string $secretStatus  one of CredentialService::SECRET_STATUS_* */
/** @var array{public_key: string, algorithm: string, bits: int, key_secure: bool|null}|null $sshInfo  SSH key metadata, null for other types or unusable secrets */
/** @var app\components\CredentialUsage $usage */

use app\components\CredentialSecretPolicy;
use app\helpers\ConfirmHelper;
use app\models\Credential;
use app\services\CredentialService;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->name;
$requiredKeys = CredentialSecretPolicy::requiredKeys($model->credential_type);
$secretLabel = $requiredKeys !== [] ? CredentialSecretPolicy::label($requiredKeys[0]) : 'Secret';
$deleteConfirm = $usage->isInUse()
    ? $usage->summary() . ' Deleting it detaches it from all of them, and jobs that need it will fail. Delete anyway?'
    : 'Delete credential "' . $model->name . '"?';
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
        <?php if ($model->credential_type === Credential::TYPE_VAULT && \Yii::$app->user?->can('job-template.update')) : ?>
            <?php if ($secretStatus === CredentialService::SECRET_STATUS_OK) : ?>
                <?= Html::a('Assign to job templates', ['/credential-assignment/index', 'id' => $model->id], ['class' => 'btn btn-outline-primary', 'id' => 'credential-assign-templates']) ?>
            <?php else : ?>
                <button type="button" class="btn btn-outline-primary" id="credential-assign-templates" disabled
                        title="Enter a usable secret first">Assign to job templates</button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('credential.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-1']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('credential.delete')) : ?>
            <form method="post" action="<?= Html::encode(Url::to(['delete', 'id' => $model->id])) ?>" style="display:inline" id="credential-delete-form"
                  onsubmit="<?= ConfirmHelper::attribute($deleteConfirm) ?>">
                <input type="hidden" name="<?= Html::encode(\Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(\Yii::$app->request->getCsrfToken()) ?>">
                <?php if ($usage->isInUse()) : ?>
                    <input type="hidden" name="force" value="1">
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-danger ms-1"><?= Html::encode($usage->isInUse() ? 'Delete anyway' : 'Delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($secretStatus === CredentialService::SECRET_STATUS_INCOMPLETE) : ?>
    <div class="alert alert-warning" id="credential-secret-status" data-status="<?= Html::encode($secretStatus) ?>">
        <?= Html::encode("No {$secretLabel} is stored for this credential, so jobs get none. Edit the credential and enter the " . strtolower($secretLabel) . '.') ?>
    </div>
<?php elseif ($secretStatus === CredentialService::SECRET_STATUS_UNDECRYPTABLE) : ?>
    <div class="alert alert-danger" id="credential-secret-status" data-status="<?= Html::encode($secretStatus) ?>">
        <?= Html::encode('The stored secret cannot be decrypted, usually because APP_SECRET_KEY changed after it was saved. Jobs that use this credential fail before they start. Edit the credential and enter the ' . strtolower($secretLabel) . ' again.') ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><span class="badge text-bg-secondary"><?= Html::encode(Credential::typeLabel($model->credential_type)) ?></span></dd>
                    <?php if ($model->credential_type === Credential::TYPE_SSH_KEY && $sshInfo) : ?>
                        <dt class="col-5">Algorithm</dt>
                        <dd class="col-7">
                            <?php if ($sshInfo['algorithm'] && $sshInfo['algorithm'] !== 'unknown') : ?>
                                <code><?= Html::encode(strtoupper($sshInfo['algorithm'])) ?><?= Html::encode($sshInfo['bits'] ? '-' . $sshInfo['bits'] : '') ?></code>
                                <?php if ($sshInfo['key_secure'] === false) : ?>
                                    <span class="badge text-bg-danger ms-1">Insecure</span>
                                <?php elseif ($sshInfo['key_secure'] === null) : ?>
                                    <span class="badge text-bg-secondary ms-1">Unknown</span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ($model->username) : ?>
                    <dt class="col-5">Username</dt>
                    <dd class="col-7"><?= Html::encode($model->username) ?></dd>
                    <?php endif; ?>
                    <?php if ($model->credential_type === Credential::TYPE_TOKEN) : ?>
                    <dt class="col-5">Env var</dt>
                    <dd class="col-7" id="credential-env-var">
                        <code><?= Html::encode($model->resolveTokenEnvVarName()) ?></code>
                        <?php if (trim((string)$model->env_var_name) === '') : ?>
                            <span class="text-muted small">(default)</span>
                        <?php endif; ?>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-5"><?= Html::encode($secretLabel) ?></dt>
                    <dd class="col-7" id="credential-secret">
                        <?php if ($secretStatus === CredentialService::SECRET_STATUS_OK) : ?>
                            <span class="text-muted small">***REDACTED***</span>
                        <?php elseif ($secretStatus === CredentialService::SECRET_STATUS_INCOMPLETE) : ?>
                            <span class="badge text-bg-warning">Missing</span>
                        <?php else : ?>
                            <span class="badge text-bg-danger">Cannot be decrypted</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Created by</dt>
                    <dd class="col-7"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= Html::encode(date('Y-m-d H:i', $model->created_at)) ?></dd>
                    <dt class="col-5">Updated</dt>
                    <dd class="col-7"><?= Html::encode(date('Y-m-d H:i', $model->updated_at)) ?></dd>
                </dl>
            </div>
        </div>
        <?php if ($model->description) : ?>
        <div class="card mt-3">
            <div class="card-header">Description</div>
            <div class="card-body"><?= nl2br(Html::encode($model->description)) ?></div>
        </div>
        <?php endif; ?>
        <?= $this->render('_usage', ['usage' => $usage]) ?>
    </div>

    <?php if ($model->credential_type === Credential::TYPE_SSH_KEY) : ?>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Public Key</span>
                <?php if ($sshInfo && $sshInfo['public_key']) : ?>
                    <button type="button" id="btn-copy-pubkey" class="btn btn-sm btn-outline-secondary"
                            onclick="copyToClipboard(document.getElementById('pubkey-display').value).then(function(){
                                var btn = document.getElementById('btn-copy-pubkey');
                                btn.textContent = 'Copied!';
                                btn.classList.replace('btn-outline-secondary','btn-success');
                                setTimeout(function(){ btn.textContent = 'Copy'; btn.classList.replace('btn-success','btn-outline-secondary'); }, 2000);
                            }).catch(function(){ alert('Copy failed — please copy manually.'); })">Copy</button>
                <?php endif; ?>
            </div>
            <?php if ($sshInfo && $sshInfo['public_key']) : ?>
                <div class="card-body p-0">
                    <textarea id="pubkey-display" class="form-control font-monospace border-0 rounded-0"
                              rows="3" readonly style="background:transparent;resize:none;"><?= Html::encode($sshInfo['public_key']) ?></textarea>
                </div>
                <div class="card-footer text-muted small">
                    Add this public key as a Deploy Key on GitHub / GitLab, or to <code>~/.ssh/authorized_keys</code> on the target host.
                </div>
            <?php elseif ($secretStatus !== CredentialService::SECRET_STATUS_OK) : ?>
                <div class="card-body text-muted small">
                    Public key not available until a usable private key is stored.
                </div>
            <?php else : ?>
                <div class="card-body text-muted small">
                    Public key not available. Re-save this credential to derive and store the public key automatically.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
