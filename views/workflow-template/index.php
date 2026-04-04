<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use app\models\WorkflowTemplate;
use yii\helpers\Html;

$this->title = 'Workflow Templates';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <?php if (Yii::$app->user->can('workflow-template.create')) : ?>
        <?= Html::a('New Workflow', ['create'], ['class' => 'btn btn-primary btn-sm']) ?>
    <?php endif; ?>
</div>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted">No workflow templates defined yet.</div>
<?php else : ?>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Steps</th>
                <th>Created By</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php /** @var WorkflowTemplate $model */ ?>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <tr>
                    <td><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::encode((string)count($model->steps)) ?></td>
                    <td><?= Html::encode($model->creator?->username ?? '—') ?></td>
                    <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?></td>
                    <td class="text-end">
                        <?php if (Yii::$app->user->can('workflow.launch')) : ?>
                            <?= Html::a('Launch', ['launch', 'id' => $model->id], [
                                'class' => 'btn btn-success btn-sm',
                                'data' => ['confirm' => 'Launch this workflow?', 'method' => 'post'],
                            ]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
