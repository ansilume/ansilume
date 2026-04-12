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

<?php if (YII_DEBUG) : ?>
    <?php
    $host = (string)\Yii::$app->request->hostName;
    $swaggerUrl = 'http://' . $host . ':8088';
    ?>
<div class="alert alert-info d-flex align-items-center flex-wrap" role="alert" id="dev-api-explorer">
    <div class="me-auto">
        <strong>Dev mode:</strong> Interactive API explorer is available.
    </div>
    <?= Html::a(
        'OpenAPI spec',
        '/openapi.yaml',
        ['class' => 'btn btn-sm btn-outline-primary me-2', 'target' => '_blank', 'rel' => 'noopener']
    ) ?>
    <?= Html::a(
        'Swagger UI',
        $swaggerUrl,
        ['class' => 'btn btn-sm btn-primary', 'target' => '_blank', 'rel' => 'noopener']
    ) ?>
</div>
<?php endif; ?>

<?php if ($newToken) : ?>
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

<?php if (empty($tokens)) : ?>
    <p class="text-muted">No tokens yet.</p>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr><th>Name</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($tokens as $token) : ?>
            <tr>
                <td><?= Html::encode($token->name) ?></td>
                <td><?= date('Y-m-d', $token->created_at) ?></td>
                <td><?= $token->last_used_at ? date('Y-m-d H:i', $token->last_used_at) : '<span class="text-muted">Never</span>' ?></td>
                <td>
                    <?php if ($token->expires_at !== null) : ?>
                        <?php if ($token->isExpired()) : ?>
                            <span class="badge text-bg-danger">Expired</span>
                        <?php else : ?>
                            <?= date('Y-m-d', $token->expires_at) ?>
                        <?php endif; ?>
                    <?php else : ?>
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

<?php
$endpointsByTag = \app\helpers\OpenApiHelper::getEndpointsByTag();
$apiVersion = \app\helpers\OpenApiHelper::getVersion();
$apiBase = rtrim(\Yii::$app->params['appBaseUrl'] ?? \yii\helpers\Url::to('/', true), '/');
?>
<div class="card mt-4">
    <div class="card-header d-flex align-items-center">
        API Reference
        <?php if ($apiVersion !== '') : ?>
            <span class="badge text-bg-secondary ms-2">v<?= Html::encode($apiVersion) ?></span>
        <?php endif; ?>
        <?= Html::a('OpenAPI spec', '/openapi.yaml', ['class' => 'btn btn-sm btn-outline-secondary ms-auto', 'target' => '_blank', 'rel' => 'noopener']) ?>
    </div>
    <div class="card-body">
        <p>All requests require the header <code>Authorization: Bearer &lt;token&gt;</code>.</p>
        <p>Responses are JSON. Successful responses contain a <code>"data"</code> key, errors contain <code>"error"</code>.</p>

        <?php foreach ($endpointsByTag as $tag => $endpoints) : ?>
            <h6 class="mt-3"><?= Html::encode($tag) ?></h6>
            <table class="table table-sm mb-3">
                <thead><tr><th style="width:80px">Method</th><th style="width:320px">Endpoint</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ($endpoints as $ep) : ?>
                        <tr>
                            <td><span class="badge <?= Html::encode($ep['badge']) ?>"><?= Html::encode($ep['method']) ?></span></td>
                            <td><code><?= Html::encode($ep['path']) ?></code></td>
                            <td><?= Html::encode($ep['summary']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <h6>Example</h6>
        <pre class="bg-body-secondary p-3 rounded"><code>curl -s -H "Authorization: Bearer YOUR_TOKEN" \
     <?= Html::encode($apiBase . '/api/v1/jobs') ?> | jq .</code></pre>
    </div>
</div>

</div>
</div>
