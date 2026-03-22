<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Schedule $model */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = Html::encode($model->name);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user->can('job.launch')): ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary']) ?>
            <form method="post" action="<?= Url::to(['toggle', 'id' => $model->id]) ?>" style="display:inline"
                  onsubmit="return confirm('<?= $model->enabled ? 'Disable this schedule?' : 'Enable this schedule?' ?>')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-<?= $model->enabled ? 'warning' : 'success' ?> ms-1">
                    <?= $model->enabled ? 'Disable' : 'Enable' ?>
                </button>
            </form>
            <form method="post" action="<?= Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline"
                  onsubmit="return confirm('Delete this schedule permanently?')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-outline-secondary ms-1']) ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Schedule details</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <?= $model->enabled
                            ? '<span class="badge text-bg-success">Enabled</span>'
                            : '<span class="badge text-bg-secondary">Disabled</span>' ?>
                    </dd>

                    <dt class="col-sm-4">Template</dt>
                    <dd class="col-sm-8">
                        <?= $model->jobTemplate
                            ? Html::a(Html::encode($model->jobTemplate->name), ['/job-template/view', 'id' => $model->job_template_id])
                            : '<span class="text-danger">Missing</span>' ?>
                    </dd>

                    <dt class="col-sm-4">Cron expression</dt>
                    <dd class="col-sm-8"><code><?= Html::encode($model->cron_expression) ?></code></dd>

                    <dt class="col-sm-4">Timezone</dt>
                    <dd class="col-sm-8"><?= Html::encode($model->timezone) ?></dd>

                    <dt class="col-sm-4">Next run</dt>
                    <dd class="col-sm-8">
                        <?= $model->next_run_at ? date('Y-m-d H:i:s', $model->next_run_at) . ' UTC' : '—' ?>
                    </dd>

                    <dt class="col-sm-4">Last run</dt>
                    <dd class="col-sm-8">
                        <?= $model->last_run_at ? date('Y-m-d H:i:s', $model->last_run_at) . ' UTC' : 'Never' ?>
                    </dd>

                    <dt class="col-sm-4">Created by</dt>
                    <dd class="col-sm-8"><?= Html::encode($model->creator->username ?? '—') ?></dd>

                    <dt class="col-sm-4">Created</dt>
                    <dd class="col-sm-8"><?= date('Y-m-d H:i:s', $model->created_at) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if (!empty($model->extra_vars)): ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Extra vars override</div>
            <div class="card-body">
                <pre class="mb-0"><code><?= Html::encode(
                    json_encode(json_decode($model->extra_vars), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                ) ?></code></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
