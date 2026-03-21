<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApiToken[] $tokens */
/** @var string|null $newToken */

use yii\helpers\Html;

$this->title = 'API Tokens';
?>
<div class="row justify-content-center">
<div class="col-lg-8">

<h2>API Tokens</h2>
<p class="text-muted">Use Bearer tokens to authenticate API requests: <code>Authorization: Bearer &lt;token&gt;</code></p>

<?php if ($newToken): ?>
<div class="alert alert-success">
    <strong>New token created.</strong> Copy it now — it will not be shown again.<br>
    <code class="user-select-all fs-6"><?= Html::encode($newToken) ?></code>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Create New Token</div>
    <div class="card-body">
        <form method="post" action="<?= \yii\helpers\Url::to(['/profile/create-token']) ?>" class="row g-2 align-items-end">
            <?= \yii\helpers\Html::hiddenInput(\Yii::$app->request->csrfParam, \Yii::$app->request->csrfToken) ?>
            <div class="col-md-8">
                <label class="form-label">Token name</label>
                <input type="text" name="name" class="form-control" placeholder="CI/CD pipeline" required maxlength="128">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Generate Token</button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($tokens)): ?>
    <p class="text-muted">No tokens yet.</p>
<?php else: ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr><th>Name</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
            <tr>
                <td><?= Html::encode($token->name) ?></td>
                <td><?= date('Y-m-d', $token->created_at) ?></td>
                <td><?= $token->last_used_at ? date('Y-m-d H:i', $token->last_used_at) : '<span class="text-muted">Never</span>' ?></td>
                <td>
                    <?php if ($token->expires_at): ?>
                        <?php if ($token->isExpired()): ?>
                            <span class="badge text-bg-danger">Expired</span>
                        <?php else: ?>
                            <?= date('Y-m-d', $token->expires_at) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">Never</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?= Html::a('Revoke', ['/profile/delete-token', 'id' => $token->id], [
                        'class' => 'btn btn-sm btn-outline-danger',
                        'data'  => ['method' => 'post', 'confirm' => "Revoke token "{$token->name}"?"],
                    ]) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
</div>
