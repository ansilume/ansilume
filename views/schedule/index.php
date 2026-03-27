<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Schedules';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Schedules</h2>
    <?php if (\Yii::$app->user->can('job.launch')) : ?>
        <?= Html::a('New Schedule', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)) : ?>
    <p class="text-muted">No schedules yet. Create one to run jobs automatically on a cron schedule.</p>
<?php else : ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Template</th>
                    <th>Cron</th>
                    <th>Timezone</th>
                    <th>Next Run</th>
                    <th>Last Run</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model) : ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td>
                        <?php if ($model->jobTemplate) : ?>
                            <?= Html::a(Html::encode($model->jobTemplate->name), ['/job-template/view', 'id' => $model->job_template_id]) ?>
                        <?php else : ?>
                            <span class="text-danger">Template missing</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= Html::encode($model->cron_expression) ?></code></td>
                    <td><?= Html::encode($model->timezone) ?></td>
                    <td class="text-nowrap">
                        <?= $model->next_run_at
                            ? date('Y-m-d H:i', $model->next_run_at) . ' UTC'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-nowrap">
                        <?= $model->last_run_at
                            ? date('Y-m-d H:i', $model->last_run_at) . ' UTC'
                            : '<span class="text-muted">Never</span>' ?>
                    </td>
                    <td>
                        <?php if ($model->enabled) : ?>
                            <span class="badge text-bg-success">Enabled</span>
                        <?php else : ?>
                            <span class="badge text-bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if (\Yii::$app->user->can('job.launch')) : ?>
                            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                            <form method="post" action="<?= Url::to(['toggle', 'id' => $model->id]) ?>" style="display:inline"
                                  onsubmit="return confirm('<?= $model->enabled ? 'Disable this schedule?' : 'Enable this schedule?' ?>')">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-<?= $model->enabled ? 'warning' : 'success' ?> ms-1">
                                    <?= $model->enabled ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
