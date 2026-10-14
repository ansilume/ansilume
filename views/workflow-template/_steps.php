<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowTemplate $model the workflow */
/** @var app\services\WorkflowStepsTable $stepsTable its steps */
/** @var bool $canChangeSteps the user may change the steps: they get the step actions */
// The steps section of the workflow template page: the success path, the
// steps table and the legend, for everyone who may view the workflow; the
// step actions only when $canChangeSteps and the table carries them. On a
// table of more than WorkflowStepsTable::MOVE_MENU_LIMIT steps, Move to…
// links to the step's edit page, at its Position field, instead of a menu.

use app\components\VersionedAssetUrl;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepRouteCells;
use app\services\WorkflowStepsTable;
use yii\helpers\Html;

$rows = $stepsTable->rows();
$successPath = $stepsTable->successPath();
$withActions = $canChangeSteps && $stepsTable->hasActions();
$workflowId = (int)$model->id;
// A route cell: its parts, each a span with its title, joined by "; ",
// then its note.
$routeCell = static function (string $route, array $cell): string {
    $parts = array_map(
        static fn (array $part): string => Html::tag('span', Html::encode($part['text']), ['title' => $part['title']]),
        $cell['parts']
    );
    $class = match ($cell['kind']) {
        WorkflowStepRouteCells::KIND_MISSING => 'text-danger',
        WorkflowStepRouteCells::KIND_UNUSED => 'text-muted',
        default => null,
    };
    $note = $cell['note'] === null ? '' : ' ' . Html::tag('span', Html::encode($cell['note']), ['class' => 'text-muted small', 'data-route-note' => true]);

    return Html::tag(
        'td',
        implode(Html::encode(WorkflowStepsTable::PART_SEPARATOR), $parts) . $note,
        ['data-route' => $route, 'data-route-kind' => $cell['kind'], 'class' => $class]
    );
};
?>
<?= Html::cssFile(VersionedAssetUrl::of('/css/workflow-steps.css')) ?>
<h4 id="workflow-steps"><?= Html::encode(WorkflowStepsTable::STEPS_HEADING) ?></h4>
<?php if ($rows === [] || $successPath === null) : ?>
    <div class="text-muted mb-3" id="wf-no-steps"><?= Html::encode(WorkflowStepsTable::NO_STEPS) ?></div>
<?php else : ?>
    <p class="small mb-1" id="wf-success-path">
        <span class="text-muted"><?= Html::encode(WorkflowStepsTable::SUCCESS_PATH_LABEL) ?></span>
        <span data-success-path><?= Html::encode($successPath['text']) ?></span>
    </p>
    <?php if ($successPath['loops']) : ?>
        <p class="small text-muted mb-1" id="wf-success-path-loop"><?= Html::encode(WorkflowStepsTable::SUCCESS_PATH_LOOP_NOTE) ?></p>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-bordered" id="workflow-steps-table">
            <thead class="table-light">
                <tr>
                    <?php foreach (WorkflowStepsTable::COLUMNS as $index => $column) : ?>
                        <?= Html::tag('th', Html::encode($column), $index === 0 ? ['title' => WorkflowStepsTable::POSITION_TITLE] : []) ?>
                    <?php endforeach; ?>
                    <?php if ($withActions) : ?>
                        <th class="text-nowrap"><?= Html::encode(WorkflowStepsTable::ACTIONS_COLUMN) ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?= Html::beginTag('tr', [
                    'id' => 'step-' . $row['id'],
                    'data-step-id' => $row['id'],
                    'data-position' => $row['position'],
                    'data-step-type' => $row['type'],
                ]) ?>
                    <td data-position-cell>
                        <span data-position><?= Html::encode((string)$row['position']) ?></span>
                        <?php if ($row['isStart']) : ?>
                            <span class="badge text-bg-success" data-start-badge title="<?= Html::encode(WorkflowStepsTable::START_TITLE) ?>"><?= Html::encode(WorkflowStepsTable::START_BADGE) ?></span>
                        <?php endif; ?>
                        <?php if (!$row['reachable']) : ?>
                            <span class="badge text-bg-secondary" data-unreachable-badge title="<?= Html::encode(WorkflowStepsTable::UNREACHABLE_TITLE) ?>"><?= Html::encode(WorkflowStepsTable::UNREACHABLE_BADGE) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode($row['name']) ?></td>
                    <td>
                        <span class="badge text-bg-secondary"><?= Html::encode($row['typeLabel']) ?></span>
                        <?php if ($row['gatedTitle'] !== null) : ?>
                            <span class="badge text-bg-warning" data-gated-badge title="<?= Html::encode($row['gatedTitle']) ?>"><?= Html::encode(WorkflowStepsTable::GATED_BADGE) ?></span>
                        <?php endif; ?>
                        <?php if ($row['waiting'] !== null) : ?>
                            <span class="badge text-bg-info" data-step-waiting><?= Html::encode($row['waiting']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-target-cell>
                        <?php if ($row['target']['route'] !== null) : ?>
                            <?= Html::a(Html::encode($row['target']['text']), $row['target']['route']) ?>
                        <?php elseif ($row['target']['text'] !== '') : ?>
                            <?= Html::encode($row['target']['text']) ?>
                        <?php endif; ?>
                        <?php if ($row['target']['problem'] !== null) : ?>
                            <span class="text-danger small" data-target-problem><?= Html::encode($row['target']['problem']) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($row['routes'] as $route => $cell) : ?>
                        <?= $routeCell($route, $cell) // xss-ok: every text encoded by $routeCell?>
                    <?php endforeach; ?>
                    <?php if ($withActions && $row['actions'] !== null) : ?>
                        <?php $actions = $row['actions']; ?>
                        <td class="text-nowrap" data-step-actions>
                            <?= Html::a(Html::encode(WorkflowStepsTable::EDIT), ['/workflow-template/edit-step', 'id' => $workflowId, 'step' => $row['id']], [
                                'class' => 'btn btn-outline-primary btn-sm',
                                'data-action' => 'edit',
                                'aria-label' => $actions['edit'],
                            ]) ?>
                            <?= Html::a(Html::encode(WorkflowStepsTable::INSERT_BELOW), ['/workflow-template/view', 'id' => $workflowId, 'after' => $row['id'], '#' => 'add-step'], [
                                'class' => 'btn btn-outline-secondary btn-sm',
                                'data-action' => 'insert-below',
                                'aria-label' => $actions['insertBelow'],
                            ]) ?>
                            <?php foreach (['up' => $row['isFirst'], 'down' => $row['isLast']] as $direction => $atTheEnd) : ?>
                                <?= Html::beginForm(['/workflow-template/move-step', 'id' => $workflowId], 'post', ['class' => 'd-inline']) ?>
                                    <?= Html::hiddenInput('step_id', (string)$row['id']) ?>
                                    <?= Html::hiddenInput('direction', $direction) ?>
                                    <?= Html::submitButton(Html::encode($direction === 'up' ? WorkflowStepsTable::MOVE_UP_BUTTON : WorkflowStepsTable::MOVE_DOWN_BUTTON), [
                                        'class' => 'btn btn-outline-secondary btn-sm' . ($atTheEnd ? ' opacity-25' : ''),
                                        'title' => $direction === 'up' ? WorkflowStepsTable::MOVE_UP_TITLE : WorkflowStepsTable::MOVE_DOWN_TITLE,
                                        'aria-label' => $direction === 'up' ? $actions['moveUp'] : $actions['moveDown'],
                                        'data-action' => 'move-' . $direction,
                                        'disabled' => $atTheEnd,
                                    ]) ?>
                                <?= Html::endForm() ?>
                            <?php endforeach; ?>
                            <?php if ($actions['moveToEditPage']) : ?>
                                <?= Html::a(Html::encode(WorkflowStepsTable::MOVE_TO), [
                                    '/workflow-template/edit-step',
                                    'id' => $workflowId,
                                    'step' => $row['id'],
                                    '#' => WorkflowStepFormView::POSITION_FIELD_ID,
                                ], [
                                    'class' => 'btn btn-outline-secondary btn-sm',
                                    'data-action' => 'move-to',
                                    'aria-label' => $actions['moveTo'],
                                    'title' => WorkflowStepsTable::MOVE_TO_EDIT_PAGE_TITLE,
                                ]) ?>
                            <?php elseif ($actions['moveTargets'] !== []) : ?>
                                <details class="wf-move-menu" data-action="move-to">
                                    <summary class="btn btn-outline-secondary btn-sm" aria-label="<?= Html::encode($actions['moveTo']) ?>"><?= Html::encode(WorkflowStepsTable::MOVE_TO) ?></summary>
                                    <?= Html::beginForm(['/workflow-template/move-step', 'id' => $workflowId], 'post', ['class' => 'wf-move-menu-items']) ?>
                                        <?= Html::hiddenInput('step_id', (string)$row['id']) ?>
                                        <?php foreach ($actions['moveTargets'] as $target) : ?>
                                            <div class="wf-move-target">
                                                <?= Html::submitButton(Html::encode($target['label']), [
                                                    'name' => 'position',
                                                    'value' => $target['value'],
                                                    'class' => 'btn btn-link btn-sm p-0 text-start',
                                                ]) ?>
                                                <?php foreach ($target['hints'] as $hint) : ?>
                                                    <small class="d-block text-warning" data-move-hint><?= Html::encode($hint) ?></small>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?= Html::endForm() ?>
                                </details>
                            <?php endif; ?>
                            <?= Html::beginForm(['/workflow-template/remove-step', 'id' => $workflowId], 'post', ['class' => 'd-inline']) ?>
                                <?= Html::hiddenInput('step_id', (string)$row['id']) ?>
                                <?= Html::submitButton(Html::encode(WorkflowStepsTable::REMOVE), [
                                    'class' => 'btn btn-outline-danger btn-sm ms-1',
                                    'data-action' => 'remove',
                                    'data-confirm' => $actions['confirm'],
                                    'aria-label' => $actions['remove'],
                                ]) ?>
                            <?= Html::endForm() ?>
                        </td>
                    <?php endif; ?>
                <?= Html::endTag('tr') ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="form-text small mb-3" id="wf-steps-legend"><?= Html::encode(WorkflowStepsTable::LEGEND) ?></p>
<?php endif; ?>
