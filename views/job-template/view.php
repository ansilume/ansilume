<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\JobTemplate $model */

use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Templates', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('job.launch')): ?>
            <?= Html::a('Launch', ['launch', 'id' => $model->id], ['class' => 'btn btn-success']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('job-template.update')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-1']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user->can('job-template.delete')): ?>
            <?= Html::a('Delete', ['delete', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger ms-1',
                'data'  => ['method' => 'post', 'confirm' => 'Delete this template?'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Execution</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Project</dt>
                    <dd class="col-7"><?= $model->project ? Html::a(Html::encode($model->project->name), ['/project/view', 'id' => $model->project_id]) : '—' ?></dd>
                    <dt class="col-5">Playbook</dt>
                    <dd class="col-7"><code><?= Html::encode($model->playbook) ?></code></dd>
                    <dt class="col-5">Inventory</dt>
                    <dd class="col-7"><?= $model->inventory ? Html::a(Html::encode($model->inventory->name), ['/inventory/view', 'id' => $model->inventory_id]) : '—' ?></dd>
                    <dt class="col-5">Credential</dt>
                    <dd class="col-7"><?= $model->credential ? Html::a(Html::encode($model->credential->name), ['/credential/view', 'id' => $model->credential_id]) : '<span class="text-muted">None</span>' ?></dd>
                    <dt class="col-5">Forks</dt>
                    <dd class="col-7"><?= $model->forks ?></dd>
                    <dt class="col-5">Verbosity</dt>
                    <dd class="col-7"><?= $model->verbosity ?></dd>
                    <?php if ($model->limit): ?><dt class="col-5">Limit</dt><dd class="col-7"><code><?= Html::encode($model->limit) ?></code></dd><?php endif; ?>
                    <?php if ($model->tags): ?><dt class="col-5">Tags</dt><dd class="col-7"><code><?= Html::encode($model->tags) ?></code></dd><?php endif; ?>
                    <?php if ($model->skip_tags): ?><dt class="col-5">Skip tags</dt><dd class="col-7"><code><?= Html::encode($model->skip_tags) ?></code></dd><?php endif; ?>
                    <dt class="col-5">Become</dt>
                    <dd class="col-7">
                        <?php if ($model->become): ?>
                            Yes — <code><?= Html::encode($model->become_method) ?></code> as <code><?= Html::encode($model->become_user) ?></code>
                        <?php else: ?>
                            No
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($model->extra_vars): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Default Extra Vars</div>
            <div class="card-body p-0">
                <pre class="job-log m-0" style="max-height:200px;overflow-y:auto;"><?= Html::encode($model->extra_vars) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (\Yii::$app->user->can('job-template.update')): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Inbound Trigger</span>
                <?php if ($model->trigger_token): ?>
                    <?= Html::a('Revoke Token', ['revoke-trigger-token', 'id' => $model->id], [
                        'class' => 'btn btn-sm btn-outline-danger',
                        'data-method' => 'post',
                        'data-confirm' => 'Revoke the trigger token? Any existing integrations will stop working.',
                    ]) ?>
                <?php else: ?>
                    <?= Html::a('Generate Token', ['generate-trigger-token', 'id' => $model->id], [
                        'class' => 'btn btn-sm btn-outline-primary',
                        'data-method' => 'post',
                    ]) ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php $rawToken = \Yii::$app->session->getFlash('trigger_token_raw'); ?>
                <?php if ($rawToken): ?>
                    <div class="alert alert-warning">
                        <strong>Copy this token now — it will not be shown again.</strong>
                        <div class="mt-2">
                            <code id="trigger-token-display"><?= Html::encode($rawToken) ?></code>
                            <button class="btn btn-sm btn-outline-secondary ms-2"
                                    onclick="navigator.clipboard.writeText('<?= Html::encode($rawToken) ?>')">Copy</button>
                        </div>
                        <div class="mt-2 text-muted small">
                            Trigger URL: <code><?= \yii\helpers\Url::to(['/trigger/fire', 'token' => $rawToken], true) ?></code>
                        </div>
                    </div>
                <?php elseif ($model->trigger_token): ?>
                    <p class="mb-1 text-muted">A trigger token is active. The trigger URL is:</p>
                    <code><?= \yii\helpers\Url::to('/trigger/' . str_repeat('*', 16), true) ?></code>
                    <p class="mt-2 mb-0 small text-muted">
                        POST to <code>/trigger/{token}</code> with optional JSON body:
                        <code>{"extra_vars": {}, "limit": "host1"}</code>
                    </p>
                <?php else: ?>
                    <p class="mb-0 text-muted">No trigger token configured. Generate one to allow external systems to launch this template via HTTP POST.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header">Recent Jobs</div>
            <div class="card-body p-0">
                <?php $jobs = $model->getJobs()->with('launcher')->orderBy(['id' => SORT_DESC])->limit(10)->all(); ?>
                <?php if (empty($jobs)): ?>
                    <p class="text-muted p-3 mb-0">No jobs yet.</p>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Status</th><th>Launched by</th><th>Started</th><th>Duration</th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><?= Html::a('#' . $job->id, ['/job/view', 'id' => $job->id]) ?></td>
                                <td><span class="badge text-bg-<?= \app\models\Job::statusCssClass($job->status) ?>"><?= Html::encode(\app\models\Job::statusLabel($job->status)) ?></span></td>
                                <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                                <td><?= $job->started_at ? date('Y-m-d H:i', $job->started_at) : '—' ?></td>
                                <td><?php
                                    if ($job->started_at && $job->finished_at) {
                                        echo gmdate('i:s', $job->finished_at - $job->started_at);
                                    } else { echo '—'; }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
