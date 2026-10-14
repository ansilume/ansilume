<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model the workflow */
/** @var list<app\models\WorkflowJob> $activeRuns its active runs, oldest first, at most WorkflowStepChangeGuard::RUN_LIST_LIMIT, launcher loaded; none for a viewer without workflow.view */
/** @var array<int, app\services\runstate\RunState> $runStates by run ID: what each run is doing (WorkflowRunStates::forRuns()) */
/** @var int $activeRunCount how many runs of the workflow are active, also those not listed */
// The template page's card of the runs that are active now, with what each
// waits for; shown to everyone who may see runs, not only to editors.

use app\helpers\TimeHelper;
use app\models\WorkflowJob;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use yii\helpers\Html;

if ($activeRuns === []) {
    return;
}
$now = time();
$more = $activeRunCount - count($activeRuns);
?>
<div class="card mb-4" id="wf-active-runs">
    <div class="card-header"><?= Html::encode(Texts::ACTIVE_RUNS_TITLE) ?></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($activeRuns as $run) :
            /** @var WorkflowJob $run */
            $state = $runStates[(int)$run->id] ?? null;
            $stateName = $state?->state ?? (string)$run->status;
            $current = $state?->current;
            $since = $current?->waitingSince ?? $current?->startedAt;
            $approval = $current?->approval;
            ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-run-id="<?= Html::encode((string)$run->id) ?>">
                <?= Html::a(Html::encode('#' . $run->id), ['/workflow-job/view', 'id' => $run->id]) ?>
                <span class="badge text-bg-<?= Html::encode(Display::css($stateName)) ?>" data-run-state="<?= Html::encode($stateName) ?>"><?= Html::encode(Display::label($stateName)) ?></span>
                <?php if ($current !== null) : ?>
                    <span><?= Html::encode($since === null
                        ? sprintf(Texts::ACTIVE_RUN_AT, $current->stepName, PollPayload::NO_VALUE)
                        : sprintf(Texts::ACTIVE_RUN_AT, $current->stepName, TimeHelper::stamp($since, $now))) ?></span>
                <?php endif; ?>
                <?php if ($approval !== null && $current?->waitingFor === Display::WAITING_FOR_APPROVAL) :
                    // The request the run waits for; a link only for a viewer who may open its page.
                    $requestLabel = Html::encode(sprintf(PollPayload::REQUEST_LABEL, $approval->requestId));
                    ?>
                    <?= $approval->linkable ? Html::a($requestLabel, ['/approval/view', 'id' => $approval->requestId], ['data-run-request' => true]) : Html::tag('span', $requestLabel, ['data-run-request' => true]) // xss-ok: encoded label in Html::a() or Html::tag()?>
                <?php endif; ?>
                <span class="text-muted small"><?= Html::encode(sprintf(Texts::ACTIVE_RUN_BY, $run->launcher?->username ?? PollPayload::NO_VALUE)) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-footer small">
        <?php if ($more > 0) : ?>
            <span class="text-muted me-2" id="wf-active-runs-more"><?= Html::encode(sprintf(Texts::ACTIVE_RUNS_MORE, $more)) ?></span>
        <?php endif; ?>
        <?= Html::a(
            Html::encode(Texts::ACTIVE_RUNS_ALL),
            ['/workflow-job/index', 'workflow_template_id' => $model->id, 'status' => WorkflowJob::STATUS_RUNNING],
            ['id' => 'wf-active-runs-all']
        ) ?>
    </div>
</div>
