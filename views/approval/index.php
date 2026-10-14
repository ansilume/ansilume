<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var array<int, app\services\runstate\RequestContext> $contexts what each listed request is for, by request ID */
/** @var string|null $activeStatus the status the list is filtered by; null: every request */
/** @var app\services\runstate\Viewer $viewer who looks at the page */

use app\controllers\ApprovalController;
use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use yii\helpers\Html;

$this->title = ApprovalController::LIST_TITLE;
$filters = ['' => ApprovalController::FILTER_ALL];
foreach (ApprovalRequest::statuses() as $status) {
    $filters[$status] = ApprovalRequest::statusLabel($status);
}
?>

<h2><?= Html::encode($this->title) ?></h2>

<nav id="approval-status-filter" class="nav nav-pills mb-3" aria-label="<?= Html::encode(ApprovalController::FILTER_LABEL) ?>">
    <?php foreach ($filters as $status => $label) : ?>
        <?php $isActive = (string)$status === (string)$activeStatus; ?>
        <?= Html::a(Html::encode($label), $status === '' ? ['index'] : ['index', 'status' => $status], [
            'class' => 'nav-link py-1' . ($isActive ? ' active' : ''),
            'data-status' => (string)$status,
            'aria-current' => $isActive ? 'page' : null,
        ]) ?>
    <?php endforeach; ?>
</nav>

<?php if ($dataProvider->totalCount === 0) : ?>
    <div class="text-muted" id="approval-requests-empty"><?= Html::encode($activeStatus === null
        ? ApprovalController::NO_REQUESTS
        : sprintf(ApprovalController::NO_REQUESTS_WITH_STATUS, ApprovalRequest::statusLabel($activeStatus))) ?></div>
<?php else : ?>
    <div class="table-responsive">
    <table class="table table-hover" id="approval-requests-table">
        <thead class="table-light">
            <tr>
                <?php foreach (ApprovalController::LIST_COLUMNS as $column) : ?>
                    <th><?= Html::encode($column) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php /** @var ApprovalRequest $model */ ?>
            <?php foreach ($dataProvider->getModels() as $model) : ?>
                <?php $pending = $model->status === ApprovalRequest::STATUS_PENDING; ?>
                <tr data-request-id="<?= (int)$model->id ?>">
                    <td><?= Html::a(Html::encode((string)$model->id), ['view', 'id' => $model->id]) ?></td>
                    <td><?= Html::a('#' . Html::encode((string)$model->job_id), ['/job/view', 'id' => $model->job_id]) ?></td>
                    <td data-approval-for><?= $this->render('_for', ['context' => $contexts[(int)$model->id] ?? null, 'viewer' => $viewer]) ?></td>
                    <td><?= Html::encode($model->approvalRule?->name ?? '—') ?></td>
                    <td><span class="badge text-bg-<?= Html::encode(ApprovalRequest::statusCssClass($model->status)) ?>"><?= Html::encode(ApprovalRequest::statusLabel($model->status)) ?></span></td>
                    <td><?= TimeHelper::relative($model->requested_at !== null ? (int)$model->requested_at : null) ?></td>
                    <td data-approval-expires>
                        <?php if (!$pending) : ?>
                            —
                        <?php elseif ($model->expires_at === null) : ?>
                            <?= Html::encode(ApprovalController::EXPIRES_NEVER) ?>
                        <?php else : ?>
                            <?= TimeHelper::relative((int)$model->expires_at) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= TimeHelper::relative($model->resolved_at !== null ? (int)$model->resolved_at : null) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?= \yii\widgets\LinkPager::widget(['pagination' => $dataProvider->pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']) ?>
<?php endif; ?>
