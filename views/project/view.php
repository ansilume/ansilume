<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Project $model */

use app\models\Project;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->name;
?>
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><?= Html::a('Projects', ['index']) ?></li>
                <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
            </ol>
        </nav>
        <h2 class="mb-0"><?= Html::encode($model->name) ?></h2>
    </div>
    <div class="btn-group">
        <?php if (\Yii::$app->user->can('project.update')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['sync', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Queue a sync for this project?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-primary ms-1">Sync</button>
            </form>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('project.delete')): ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Delete this project?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($model->status === Project::STATUS_ERROR && $model->last_sync_error): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <div>
        <strong>Last sync failed</strong><br>
        <code class="user-select-all" style="white-space:pre-wrap;"><?= Html::encode($model->last_sync_error) ?></code>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <?php
                        $badge = match ($model->status) {
                            Project::STATUS_SYNCED  => 'success',
                            Project::STATUS_SYNCING => 'primary',
                            Project::STATUS_ERROR   => 'danger',
                            default                 => 'secondary',
                        };
                        ?>
                        <span class="badge text-bg-<?= $badge ?>"><?= Html::encode(Project::statusLabel($model->status)) ?></span>
                    </dd>
                    <dt class="col-sm-4">SCM Type</dt>
                    <dd class="col-sm-8"><?= Html::encode(strtoupper($model->scm_type)) ?></dd>
                    <?php if ($model->scm_url): ?>
                        <dt class="col-sm-4">URL</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->scm_url) ?></code></dd>
                        <dt class="col-sm-4">Branch</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->scm_branch) ?></code></dd>
                    <?php endif; ?>
                    <?php if ($model->local_path): ?>
                        <dt class="col-sm-4">Local path</dt>
                        <dd class="col-sm-8"><code><?= Html::encode($model->local_path) ?></code></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4">Last synced</dt>
                    <dd class="col-sm-8"><?= $model->last_synced_at ? date('Y-m-d H:i:s', $model->last_synced_at) : '—' ?></dd>
                    <dt class="col-sm-4">Created by</dt>
                    <dd class="col-sm-8"><?= Html::encode($model->creator->username ?? '—') ?></dd>
                    <dt class="col-sm-4">Created at</dt>
                    <dd class="col-sm-8"><?= date('Y-m-d H:i', $model->created_at) ?></dd>
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

    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Job Templates</span>
                <?php if (\Yii::$app->user->can('job-template.create')): ?>
                    <?= Html::a('New Template', ['/job-template/create', 'project_id' => $model->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php $templates = $model->jobTemplates; ?>
                <?php if (empty($templates)): ?>
                    <p class="text-muted p-3 mb-0">No templates yet.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($templates as $tpl): ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <?= Html::a(Html::encode($tpl->name), ['/job-template/view', 'id' => $tpl->id]) ?>
                                <code class="text-muted small"><?= Html::encode($tpl->playbook) ?></code>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
