<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var app\models\JobSearchForm $searchForm */
/** @var app\models\JobTemplate[] $templates */
/** @var app\models\User[] $users */
/** @var array $statusOptions */

use app\models\Job;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\widgets\LinkPager;

$this->title = 'Jobs';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Jobs</h2>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— Any —</option>
                    <?php foreach ($statusOptions as $val => $label): ?>
                        <option value="<?= Html::encode($val) ?>" <?= $searchForm->status === $val ? 'selected' : '' ?>>
                            <?= Html::encode($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Template</label>
                <select name="template_id" class="form-select form-select-sm">
                    <option value="">— Any —</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?= $tpl->id ?>" <?= $searchForm->template_id == $tpl->id ? 'selected' : '' ?>>
                            <?= Html::encode($tpl->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Launched by</label>
                <select name="launched_by" class="form-select form-select-sm">
                    <option value="">— Any —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u->id ?>" <?= $searchForm->launched_by == $u->id ? 'selected' : '' ?>>
                            <?= Html::encode($u->username) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= Html::encode($searchForm->date_from ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= Html::encode($searchForm->date_to ?? '') ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
        </div>
        <?php if ($searchForm->status || $searchForm->template_id || $searchForm->launched_by || $searchForm->date_from || $searchForm->date_to): ?>
            <div class="mt-1">
                <?= Html::a('Clear filters', ['index'], ['class' => 'small text-muted']) ?>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)): ?>
    <p class="text-muted">No jobs match the current filters.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Template</th><th>Status</th>
                    <th>Launched by</th><th>Queued</th><th>Started</th><th>Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $job): ?>
                <tr>
                    <td><?= Html::a('#' . $job->id, ['view', 'id' => $job->id]) ?></td>
                    <td><?= Html::encode($job->jobTemplate->name ?? '—') ?></td>
                    <td>
                        <span class="badge text-bg-<?= Job::statusCssClass($job->status) ?>">
                            <?= Html::encode(Job::statusLabel($job->status)) ?>
                        </span>
                    </td>
                    <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                    <td><?= $job->queued_at   ? date('Y-m-d H:i', $job->queued_at)   : '—' ?></td>
                    <td><?= $job->started_at  ? date('Y-m-d H:i', $job->started_at)  : '—' ?></td>
                    <td>
                        <?php
                        if ($job->started_at && $job->finished_at) {
                            $s = $job->finished_at - $job->started_at;
                            echo gmdate($s >= 3600 ? 'H:i:s' : 'i:s', $s);
                        } elseif ($job->started_at) {
                            echo '<span class="text-primary">Running…</span>';
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
<?php endif; ?>
