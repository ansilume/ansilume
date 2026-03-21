<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\Project;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Projects';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Projects</h2>
    <?php if (\Yii::$app->user->can('project.create')): ?>
        <?= Html::a('New Project', ['create'], ['class' => 'btn btn-primary']) ?>
    <?php endif; ?>
</div>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)): ?>
    <p class="text-muted">No projects yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>SCM</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Last Synced</th>
                    <th>Created by</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <tr>
                    <td><?= $model->id ?></td>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode(strtoupper($model->scm_type)) ?></td>
                    <td><code><?= Html::encode($model->scm_branch) ?></code></td>
                    <td>
                        <?php
                        $badge = match ($model->status) {
                            Project::STATUS_SYNCED  => 'success',
                            Project::STATUS_SYNCING => 'primary',
                            Project::STATUS_ERROR   => 'danger',
                            default                 => 'secondary',
                        };
                        ?>
                        <span class="badge text-bg-<?= $badge ?>">
                            <?= Html::encode(Project::statusLabel($model->status)) ?>
                        </span>
                    </td>
                    <td><?= $model->last_synced_at ? date('Y-m-d H:i', $model->last_synced_at) : '—' ?></td>
                    <td><?= Html::encode($model->creator->username ?? '—') ?></td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('View', ['view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if (\Yii::$app->user->can('project.update')): ?>
                            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary ms-1']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
