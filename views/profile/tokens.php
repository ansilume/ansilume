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
                    <form method="post" action="<?= \yii\helpers\Url::to(['/profile/delete-token', 'id' => $token->id]) ?>" style="display:inline" onsubmit="return confirm('Revoke token &quot;<?= addslashes(Html::encode($token->name)) ?>&quot;?')">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="card mt-4">
    <div class="card-header">API Reference</div>
    <div class="card-body">
        <p>All requests require the header <code>Authorization: Bearer &lt;token&gt;</code>.</p>
        <p>Responses are JSON. Successful responses contain a <code>"data"</code> key, errors contain <code>"error"</code>.</p>

        <h6 class="mt-3">Jobs</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/jobs</code></td><td>List jobs (supports <code>?status=</code>, <code>?template_id=</code>, pagination)</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/jobs/{id}</code></td><td>Job detail with logs and status</td></tr>
                <tr><td><span class="badge text-bg-primary">POST</span></td><td><code>/api/v1/jobs</code></td><td>Launch a job — body: <code>{"template_id": 1}</code>, optional: <code>extra_vars</code>, <code>limit</code>, <code>verbosity</code></td></tr>
                <tr><td><span class="badge text-bg-primary">POST</span></td><td><code>/api/v1/jobs/{id}/cancel</code></td><td>Cancel a running job</td></tr>
            </tbody>
        </table>

        <h6>Job Templates</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/job-templates</code></td><td>List templates</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/job-templates/{id}</code></td><td>Template detail</td></tr>
            </tbody>
        </table>

        <h6>Projects</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/projects</code></td><td>List projects</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/projects/{id}</code></td><td>Project detail</td></tr>
            </tbody>
        </table>

        <h6>Inventories</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/inventories</code></td><td>List inventories</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/inventories/{id}</code></td><td>Inventory detail (content excluded for security)</td></tr>
            </tbody>
        </table>

        <h6>Credentials</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/credentials</code></td><td>List credentials (secrets never returned)</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/credentials/{id}</code></td><td>Credential metadata</td></tr>
            </tbody>
        </table>

        <h6>Schedules</h6>
        <table class="table table-sm mb-3">
            <thead><tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/schedules</code></td><td>List schedules</td></tr>
                <tr><td><span class="badge text-bg-success">GET</span></td><td><code>/api/v1/schedules/{id}</code></td><td>Schedule detail</td></tr>
                <tr><td><span class="badge text-bg-primary">POST</span></td><td><code>/api/v1/schedules/{id}/toggle</code></td><td>Enable/disable a schedule</td></tr>
            </tbody>
        </table>

        <h6>Example</h6>
        <pre class="bg-body-secondary p-3 rounded"><code>curl -s -H "Authorization: Bearer YOUR_TOKEN" \
     <?= Html::encode(\yii\helpers\Url::to(['/api/v1/jobs'], true)) ?> | jq .</code></pre>
    </div>
</div>

</div>
</div>
