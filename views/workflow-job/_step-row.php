<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<string, mixed> $step one step of runstate\PollPayload::of(): an execution of a step in the run */
// web/js/workflow-run-live.js builds and updates the same markup from the poll.

use app\services\runstate\PollPayload;
use yii\helpers\Html;

/** @var array{id: int, label: string, status: string, status_label: string, url: string|null}|null $approval */
$approval = $step['approval'];
$links = [];
// The job of an approval step is the placeholder that holds its request: the request is linked instead.
if ($step['job_id'] !== null && $step['show_job_link'] === true) {
    $links[] = Html::a(Html::encode('#' . $step['job_id']), ['/job/view', 'id' => $step['job_id']], ['data-wjs-job-link' => true]);
}
if ($approval !== null) {
    // A link only for a viewer who may open the request page.
    $links[] = $approval['url'] === null
        ? Html::tag('span', Html::encode($approval['label']), ['data-wjs-request-link' => true])
        : Html::a(Html::encode($approval['label']), $approval['url'], ['data-wjs-request-link' => true]);
}
?>
<tr data-wjs-id="<?= Html::encode((string)$step['id']) ?>" class="<?= Html::encode($step['is_current'] === true ? 'table-active' : '') ?>">
    <td><?= Html::encode((string)$step['step_index']) ?></td>
    <td data-wjs-name-cell><?= Html::encode((string)$step['step_name']) ?></td>
    <td data-wjs-type-cell><?= Html::encode((string)$step['step_type_label']) ?></td>
    <td data-wjs-status-cell>
        <span class="badge text-bg-<?= Html::encode((string)$step['state_css']) ?>"><?= Html::encode((string)$step['state_label']) ?></span>
        <?php if ((string)$step['error_message'] !== '') : ?>
        <div class="small text-danger mt-1" data-wjs-error><?= Html::encode((string)$step['error_message']) ?></div>
        <?php endif; ?>
        <?php if ((string)$step['decision_note'] !== '') : ?>
        <div class="small text-body-secondary mt-1" data-wjs-decision><?= Html::encode((string)$step['decision_note']) ?></div>
        <?php endif; ?>
    </td>
    <td data-wjs-job-cell><?= $links === [] ? Html::encode(PollPayload::NO_VALUE) : implode(Html::encode(PollPayload::LINK_SEPARATOR), $links) // xss-ok: Html::a() and Html::tag() with encoded text?></td>
    <td data-wjs-started-cell><?= Html::encode($step['started_label'] ?? PollPayload::NO_VALUE) ?></td>
    <td data-wjs-duration-cell><?= Html::encode($step['duration_label'] ?? PollPayload::NO_VALUE) ?></td>
    <td data-wjs-finished-cell><?= Html::encode($step['finished_label'] ?? PollPayload::NO_VALUE) ?></td>
</tr>
