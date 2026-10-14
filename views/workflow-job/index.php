<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var app\models\WorkflowJobSearch $search the list's filters as requested; invalid ones are ignored */
/** @var array<int, app\services\runstate\RunState> $runStates by run ID: what each listed run is doing at its current step */

use app\helpers\TimeHelper;
use app\models\WorkflowJob;
use app\services\runstate\Display;
use app\services\runstate\PollPayload;
use yii\helpers\Html;

$this->title = 'Workflow Jobs';
$activeWaitingFor = $search->activeWaitingFor();
// The filter links keep the other filters of the list (from a template page's "All runs of this workflow").
$kept = array_filter([
    'workflow_template_id' => $search->activeWorkflowTemplateId(),
    'status' => $search->activeStatus(),
], static fn ($value): bool => $value !== null);
$filters = [
    '' => PollPayload::FILTER_ALL,
    Display::WAITING_FOR_APPROVAL => Display::label(Display::STATE_AWAITING_APPROVAL),
    Display::WAITING_FOR_RESUME => Display::label(Display::STATE_PAUSED),
    Display::WAITING_FOR_RUNNER => Display::label(Display::STATE_QUEUED),
];
$filtered = $activeWaitingFor !== null || $kept !== [];
?>

<h2><?= Html::encode($this->title) ?></h2>

<nav id="wj-filter" class="nav nav-pills small mb-3">
    <?php foreach ($filters as $waitingFor => $label) :
        $active = $waitingFor === (string)$activeWaitingFor;
        $route = ['index'] + $kept + ($waitingFor === '' ? [] : ['waiting_for' => $waitingFor]);
        ?>
        <?= Html::a(Html::encode($label), $route, [
            'class' => 'nav-link py-1' . ($active ? ' active' : ''),
            'data-waiting-for' => $waitingFor,
            'aria-current' => $active ? 'page' : null,
        ]) ?>
    <?php endforeach; ?>
</nav>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted"><?= Html::encode($filtered ? PollPayload::NO_MATCHING_RUNS : PollPayload::NO_RUNS) ?></div>
<?php else : ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Workflow</th>
                    <th>Status</th>
                    <th>Current Step</th>
                    <th>Launched By</th>
                    <th>Started</th>
                    <th>Finished</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataProvider->getModels() as $model) :
                    /** @var WorkflowJob $model */
                    $state = $runStates[(int)$model->id] ?? null;
                    $stateName = $state?->state ?? (string)$model->status;
                    $current = $state?->current;
                    $approval = $current?->approval;
                    ?>
                    <tr>
                        <td><?= Html::a(Html::encode((string)$model->id), ['view', 'id' => $model->id]) ?></td>
                        <td data-run-workflow><?= $this->render('_workflow-name', [
                            'name' => $model->workflowTemplate?->name,
                            'id' => (int)$model->workflow_template_id,
                            'deleted' => (bool)$model->workflowTemplate?->isDeleted(),
                            'link' => false,
                        ]) ?></td>
                        <td><span class="badge text-bg-<?= Html::encode(Display::css($stateName)) ?>" data-run-state="<?= Html::encode($stateName) ?>"><?= Html::encode(Display::label($stateName)) ?></span></td>
                        <td>
                            <?php if ($current === null) : ?>
                                <?= Html::encode(PollPayload::NO_VALUE) ?>
                            <?php else : ?>
                                <?= Html::encode($current->stepName) ?>
                                <?php if ($approval !== null && $current->waitingFor === Display::WAITING_FOR_APPROVAL) :
                                    // The request a run waits for; a link only for a viewer who may open its page.
                                    $requestLabel = Html::encode(sprintf(PollPayload::REQUEST_LABEL, $approval->requestId));
                                    $requestOptions = ['class' => 'small ms-1', 'data-run-request' => true];
                                    ?>
                                    <?= $approval->linkable ? Html::a($requestLabel, ['/approval/view', 'id' => $approval->requestId], $requestOptions) : Html::tag('span', $requestLabel, $requestOptions) // xss-ok: encoded label in Html::a() or Html::tag()?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= Html::encode($model->launcher?->username ?? PollPayload::NO_VALUE) ?></td>
                        <td><?= TimeHelper::relative($model->started_at !== null ? (int)$model->started_at : null) ?></td>
                        <td><?= TimeHelper::relative($model->finished_at !== null ? (int)$model->finished_at : null) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']) ?>
<?php endif; ?>
