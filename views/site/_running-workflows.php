<?php

declare(strict_types=1);

/**
 * The dashboard's Running Workflows: each run with its current step and, when
 * it waits, what for (WorkflowRunStates, by the type the step had when it
 * ran): Awaiting Approval with a link to the request for a user who may see
 * it, Paused with Resume for a user who may resume it, or Queued.
 *
 * @var yii\web\View $this
 * @var list<app\models\WorkflowJob> $runningWorkflows the running runs the user may see, newest first
 * @var array<int, app\services\runstate\RunState> $runStates the state of each run, by run ID
 * @var list<int> $resumableWorkflowTemplateIds workflow templates whose paused runs the user may resume
 */

use app\controllers\ApprovalController;
use app\controllers\SiteController;
use app\helpers\TimeHelper;
use app\services\runstate\Display;
use app\services\runstate\Texts;
use yii\helpers\Html;
use yii\helpers\Url;

?>
<div class="card mb-3 border-info" id="dashboard-running-workflows">
    <div class="card-header text-bg-info d-flex justify-content-between align-items-center">
        <strong>Running Workflows (<?= count($runningWorkflows) ?>)</strong>
        <?= Html::a(Html::encode(SiteController::ALL_WORKFLOW_JOBS), Url::to(['/workflow-job/index']), ['class' => 'btn btn-sm btn-outline-dark']) ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Workflow</th><th>Current Step</th><th>Launched by</th><th>Started</th><th>Running for</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($runningWorkflows as $wf) : ?>
                <?php
                $state = $runStates[(int)$wf->id] ?? null;
                $current = $state?->current;
                $approval = $current?->approval;
                $waitingSince = $current?->waitingSince;
                ?>
                <tr data-run-id="<?= (int)$wf->id ?>">
                    <td><?= Html::a('#' . (int)$wf->id, Url::to(['/workflow-job/view', 'id' => $wf->id])) ?></td>
                    <td data-run-workflow><?= $this->render('@app/views/workflow-job/_workflow-name', [
                        'name' => $wf->workflowTemplate?->name,
                        'id' => (int)$wf->workflow_template_id,
                        'deleted' => (bool)$wf->workflowTemplate?->isDeleted(),
                        'link' => false,
                    ]) ?></td>
                    <td>
                        <?= Html::encode($current?->stepName ?? '—') ?>
                        <?php if ($state !== null && $state->waitingFor !== null) : ?>
                            <?= Html::tag('span', Html::encode(Display::label($state->state)), [
                                'class' => 'badge text-bg-' . Display::css($state->state) . ' ms-1',
                                'data-run-state' => $state->state,
                                'title' => $waitingSince === null ? null : sprintf(Texts::FACT_SINCE, TimeHelper::stamp($waitingSince)),
                            ]) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode($wf->launcher->username ?? '—') ?></td>
                    <td class="text-nowrap"><?= $wf->started_at ? Html::encode(TimeHelper::stamp((int)$wf->started_at)) : '—' ?></td>
                    <td class="text-nowrap"><?= $wf->started_at ? Html::encode(TimeHelper::duration(time() - (int)$wf->started_at)) : '—' ?></td>
                    <td class="text-nowrap">
                        <?php if ($state?->state === Display::STATE_PAUSED && in_array((int)$wf->workflow_template_id, $resumableWorkflowTemplateIds, true)) : ?>
                            <form action="<?= Url::to(['/workflow-job/resume', 'id' => $wf->id]) ?>" method="post" class="d-inline">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-success">Resume</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($state?->state === Display::STATE_AWAITING_APPROVAL && $approval !== null && $approval->linkable) : ?>
                            <?= Html::a(Html::encode(ApprovalController::REVIEW), Url::to(['/approval/view', 'id' => $approval->requestId]), [
                                'class' => 'btn btn-sm btn-outline-info',
                                'data-run-review' => $approval->requestId,
                            ]) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
