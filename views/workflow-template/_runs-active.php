<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $id the notice's element ID */
/** @var list<int> $runIds the active runs it names, oldest first (at most WorkflowStepChangeGuard::RUN_LIST_LIMIT) */
/** @var int $runCount how many runs are active */
// The notice that active runs lock the steps: WorkflowStepChangeGuard::RUNS_ACTIVE with each named run
// linked and the others counted, as the guard's refusal names them (runsActiveMessage()).

use app\services\runstate\Texts;
use app\services\WorkflowStepChangeGuard;
use yii\helpers\Html;

[$runsBefore, $runsAfter] = explode('%s', WorkflowStepChangeGuard::RUNS_ACTIVE, 2);
$runLinks = array_map(
    static fn (int $runId): string => Html::a(Html::encode('#' . $runId), ['/workflow-job/view', 'id' => $runId]),
    array_slice($runIds, 0, WorkflowStepChangeGuard::RUN_LIST_LIMIT)
);
$moreRuns = $runCount - count($runLinks);
?>
<div class="alert alert-warning small" id="<?= Html::encode($id) ?>">
    <?= Html::encode($runsBefore) ?><?= implode(', ', $runLinks) // xss-ok: Html::a() with encoded text?><?= $moreRuns > 0 ? Html::encode(' ' . sprintf(Texts::ACTIVE_RUNS_MORE, $moreRuns)) : '' ?><?= Html::encode($runsAfter) ?>
</div>
