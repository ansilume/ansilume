<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\AuditLog $entry */

use yii\helpers\Html;

$this->title = 'Audit Entry #' . $entry->id;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Audit Log', ['index']) ?></li>
        <li class="breadcrumb-item active">#<?= $entry->id ?></li>
    </ol>
</nav>

<h2>Audit Entry #<?= $entry->id ?></h2>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-4">Action</dt>
                    <dd class="col-8"><code><?= Html::encode($entry->action) ?></code></dd>
                    <dt class="col-4">User</dt>
                    <dd class="col-8">
                        <?php if ($entry->user): ?>
                            <?= Html::a(Html::encode($entry->user->username), ['/user/view', 'id' => $entry->user_id]) ?>
                        <?php elseif ($entry->user_id): ?>
                            #<?= $entry->user_id ?> (deleted)
                        <?php else: ?>
                            <span class="text-muted">system</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-4">Object</dt>
                    <dd class="col-8">
                        <?= $entry->object_type ? Html::encode($entry->object_type) . ' #' . $entry->object_id : '—' ?>
                    </dd>
                    <dt class="col-4">IP</dt>
                    <dd class="col-8"><?= Html::encode($entry->ip_address ?? '—') ?></dd>
                    <dt class="col-4">User-Agent</dt>
                    <dd class="col-8 text-truncate" style="max-width:300px" title="<?= Html::encode($entry->user_agent ?? '') ?>">
                        <?= Html::encode($entry->user_agent ?? '—') ?>
                    </dd>
                    <dt class="col-4">Timestamp</dt>
                    <dd class="col-8"><?= date('Y-m-d H:i:s', $entry->created_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($entry->metadata): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Context</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height:300px;overflow-y:auto;"><?php
                    $decoded = json_decode($entry->metadata, true);
                    echo Html::encode($decoded
                        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : $entry->metadata);
                ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
