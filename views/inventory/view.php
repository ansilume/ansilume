<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Inventory $model */

use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Inventories', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user?->can('inventory.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('inventory.delete')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Delete this inventory?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><span class="badge text-bg-secondary"><?= Html::encode(strtoupper($model->inventory_type)) ?></span></dd>
                    <?php if ($model->project) : ?>
                        <dt class="col-5">Project</dt>
                        <dd class="col-7"><?= Html::a(Html::encode($model->project->name), ['/project/view', 'id' => $model->project_id]) ?></dd>
                    <?php endif; ?>
                    <?php if ($model->source_path) : ?>
                        <dt class="col-5">Path</dt>
                        <dd class="col-7"><code><?= Html::encode($model->source_path) ?></code></dd>
                    <?php endif; ?>
                    <dt class="col-5">Created by</dt>
                    <dd class="col-7"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-5">Created</dt>
                    <dd class="col-7"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($model->content) : ?>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">Content</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height: 400px; overflow-y:auto;"><?= Html::encode($model->content) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
/** @var \app\services\InventoryService $invService */
$invService = \Yii::$app->get('inventoryService');
$cached = $invService->getCached($model);
$cachedJson = $cached !== null ? json_encode($cached) : 'null';
?>
<div class="mt-4">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Resolved Hosts &amp; Groups</span>
            <div>
                <?php if ($model->parsed_at) : ?>
                    <small class="text-muted me-2" id="parsed-at-label">Parsed <?= date('Y-m-d H:i', $model->parsed_at) ?></small>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-parse-inventory">
                    <?= Html::encode($cached !== null ? 'Refresh' : 'Parse Inventory') ?>
                </button>
            </div>
        </div>
        <div class="card-body" id="inventory-result">
            <?php if ($cached === null) : ?>
                <p class="text-muted mb-0">Click "Parse Inventory" to resolve hosts and groups via <code>ansible-inventory</code>.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$parseUrl = \yii\helpers\Url::to(['parse-hosts', 'id' => $model->id]);
$csrf = \Yii::$app->request->csrfParam;
$csrfToken = \Yii::$app->request->getCsrfToken();

$js = <<<JS
var cachedData = {$cachedJson};

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function renderInventory(data) {
    var container = document.getElementById('inventory-result');

    if (data.error) {
        container.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(data.error) + '</div>';
        return;
    }

    var html = '';

    // Groups
    var groupNames = Object.keys(data.groups).sort();
    if (groupNames.length > 0) {
        html += '<h6>Groups <span class="badge text-bg-secondary">' + groupNames.length + '</span></h6>';
        html += '<div class="accordion mb-3" id="inv-groups">';
        groupNames.forEach(function(name, idx) {
            var g = data.groups[name];
            var hosts = g.hosts || [];
            var children = g.children || [];
            var vars = g.vars || {};
            var varKeys = Object.keys(vars);
            var collapseId = 'group-' + idx;

            html += '<div class="accordion-item">';
            html += '<h2 class="accordion-header">';
            html += '<button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#' + collapseId + '">';
            html += '<strong>' + escapeHtml(name) + '</strong>';
            html += '<span class="badge text-bg-info ms-2">' + hosts.length + ' host' + (hosts.length !== 1 ? 's' : '') + '</span>';
            if (children.length) html += '<span class="badge text-bg-warning ms-2">' + children.length + ' children</span>';
            html += '</button></h2>';
            html += '<div id="' + collapseId + '" class="accordion-collapse collapse" data-bs-parent="#inv-groups">';
            html += '<div class="accordion-body py-2">';

            if (hosts.length) {
                html += '<div class="mb-2"><strong>Hosts:</strong> ' + hosts.map(escapeHtml).join(', ') + '</div>';
            }
            if (children.length) {
                html += '<div class="mb-2"><strong>Children:</strong> ' + children.map(escapeHtml).join(', ') + '</div>';
            }
            if (varKeys.length) {
                html += '<div><strong>Group vars:</strong>';
                html += '<pre class="mb-0 mt-1" style="font-size:.85em">' + escapeHtml(JSON.stringify(vars, null, 2)) + '</pre>';
                html += '</div>';
            }
            if (!hosts.length && !children.length && !varKeys.length) {
                html += '<em class="text-muted">Empty group</em>';
            }

            html += '</div></div></div>';
        });
        html += '</div>';
    }

    // Hosts
    var hostNames = Object.keys(data.hosts).sort();
    if (hostNames.length > 0) {
        html += '<h6>All Hosts <span class="badge text-bg-secondary">' + hostNames.length + '</span></h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-striped mb-0">';
        html += '<thead><tr><th>Host</th><th>Variables</th></tr></thead><tbody>';
        hostNames.forEach(function(h) {
            var vars = data.hosts[h];
            var varKeys = Object.keys(vars);
            html += '<tr><td><code>' + escapeHtml(h) + '</code></td><td>';
            if (varKeys.length) {
                html += '<pre class="mb-0" style="font-size:.85em">' + escapeHtml(JSON.stringify(vars, null, 2)) + '</pre>';
            } else {
                html += '<span class="text-muted">—</span>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    if (!groupNames.length && !hostNames.length) {
        html = '<p class="text-muted mb-0">No hosts or groups found.</p>';
    }

    container.innerHTML = html;
}

// Render cached data on load
if (cachedData) {
    renderInventory(cachedData);
}

document.getElementById('btn-parse-inventory').addEventListener('click', function() {
    var btn = this;
    var container = document.getElementById('inventory-result');
    btn.disabled = true;
    btn.textContent = 'Parsing…';
    container.innerHTML = '<p class="text-muted">Running ansible-inventory…</p>';

    fetch('{$parseUrl}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: '{$csrf}=' + encodeURIComponent('{$csrfToken}')
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Refresh';
        var label = document.getElementById('parsed-at-label');
        if (label) {
            label.textContent = 'Parsed just now';
        }
        renderInventory(data);
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Refresh';
        container.innerHTML = '<div class="alert alert-danger mb-0">Request failed: ' + escapeHtml(err.message) + '</div>';
    });
});
JS;

$this->registerJs($js);
?>
