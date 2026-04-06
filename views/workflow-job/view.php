<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowJob $model */

use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use yii\helpers\Html;

$this->title = 'Workflow Job #' . $model->id;
$steps = $model->stepExecutions;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php
        // Check if there is a currently running pause step
        $hasPausedStep = false;
        if (!$model->isFinished()) {
            foreach ($steps as $wjs) {
                if (
                    $wjs->status === WorkflowJobStep::STATUS_RUNNING
                    && $wjs->workflowStep?->step_type === WorkflowStep::TYPE_PAUSE
                ) {
                    $hasPausedStep = true;
                    break;
                }
            }
        }
        ?>
        <?php if ($hasPausedStep && Yii::$app->user->can('workflow.launch')) : ?>
            <?= Html::a('Resume', ['resume', 'id' => $model->id], [
                'class' => 'btn btn-success btn-sm',
                'data' => ['confirm' => 'Resume this workflow?', 'method' => 'post'],
            ]) ?>
        <?php endif; ?>
        <?php if (!$model->isFinished() && Yii::$app->user->can('workflow.cancel')) : ?>
            <?= Html::a('Cancel', ['cancel', 'id' => $model->id], [
                'class' => 'btn btn-outline-danger btn-sm ms-1',
                'data' => ['confirm' => 'Cancel this workflow?', 'method' => 'post'],
            ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-10">
        <table class="table table-bordered mb-4">
            <tr>
                <th style="width:180px">Status</th>
                <td><span class="badge text-bg-<?= WorkflowJob::statusCssClass($model->status) ?>"><?= Html::encode(WorkflowJob::statusLabel($model->status)) ?></span></td>
            </tr>
            <tr>
                <th>Workflow</th>
                <td><?= Html::a(Html::encode($model->workflowTemplate?->name ?? '—'), ['/workflow-template/view', 'id' => $model->workflow_template_id]) ?></td>
            </tr>
            <tr>
                <th>Launched By</th>
                <td><?= Html::encode($model->launcher?->username ?? '—') ?></td>
            </tr>
            <tr>
                <th>Started</th>
                <td><?= $model->started_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->started_at)) : '—' ?></td>
            </tr>
            <tr>
                <th>Finished</th>
                <td><?= $model->finished_at ? Html::encode(date('Y-m-d H:i:s', (int)$model->finished_at)) : '—' ?></td>
            </tr>
        </table>

        <h4>Steps</h4>
        <?php if (empty($steps)) : ?>
            <div class="text-muted">No steps executed.</div>
        <?php else : ?>
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Step</th>
                        <th>Status</th>
                        <th>Child Job</th>
                        <th>Started</th>
                        <th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $i => $wjs) : ?>
                        <?php
                        $isCurrent = $model->current_step_id === $wjs->workflow_step_id;
                        $cssClass = WorkflowJobStep::statusCssClass($wjs->status);
                        $label = WorkflowJobStep::statusLabel($wjs->status);
                        ?>
                        <tr class="<?= Html::encode($isCurrent ? 'table-active' : '') ?>">
                            <td><?= Html::encode((string)($i + 1)) ?></td>
                            <td><?= Html::encode($wjs->workflowStep?->name ?? '—') ?></td>
                            <td>
                                <span class="badge text-bg-<?= Html::encode($cssClass) ?>">
                                    <?= Html::encode($label) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($wjs->job_id) : ?>
                                    <?= Html::a(
                                        '#' . Html::encode((string)$wjs->job_id),
                                        ['/job/view', 'id' => $wjs->job_id]
                                    ) ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $wjs->started_at
                                    ? Html::encode(date('H:i:s', (int)$wjs->started_at))
                                    : '—' ?>
                            </td>
                            <td>
                                <?= $wjs->finished_at
                                    ? Html::encode(date('H:i:s', (int)$wjs->finished_at))
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
