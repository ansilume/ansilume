<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var app\models\JobSearchForm $searchForm */
/** @var app\models\JobTemplate[] $templates */
/** @var app\models\RunnerGroup[] $runnerGroups */
/** @var app\models\User[] $users */
/** @var array $statusOptions */

use app\models\Job;
use app\models\JobHostSummary;
use app\models\RunnerGroup;
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
                    <?php foreach ($statusOptions as $val => $label) : ?>
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
                    <?php foreach ($templates as $tpl) : ?>
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
                    <?php foreach ($users as $u) : ?>
                        <option value="<?= $u->id ?>" <?= $searchForm->launched_by == $u->id ? 'selected' : '' ?>>
                            <?= Html::encode($u->username) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Runner</label>
                <select name="runner_group_id" class="form-select form-select-sm">
                    <option value="">— Any —</option>
                    <?php foreach ($runnerGroups as $rg) : ?>
                        <option value="<?= $rg->id ?>" <?= $searchForm->runner_group_id == $rg->id ? 'selected' : '' ?>>
                            <?= Html::encode($rg->name) ?>
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
        <?php if ($searchForm->status || $searchForm->template_id || $searchForm->launched_by || $searchForm->runner_group_id || $searchForm->date_from || $searchForm->date_to) : ?>
            <div class="mt-1">
                <?= Html::a('Clear filters', ['index'], ['class' => 'small text-muted']) ?>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php $models = $dataProvider->getModels(); ?>
<?php if (empty($models)) : ?>
    <p class="text-muted">No jobs match the current filters.</p>
<?php else : ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Status</th><th>Template</th>
                    <th class="text-center">Hosts</th><th>Recap</th>
                    <th>Launched by</th><th>Runner</th><th>Started</th><th>Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($models as $job) : ?>
                <tr>
                    <td><?= Html::a('#' . $job->id, ['view', 'id' => $job->id]) ?></td>
                    <td>
                        <?= Html::a(
                            Html::encode(Job::statusLabel($job->status)),
                            ['view', 'id' => $job->id],
                            ['class' => 'badge text-bg-' . Job::statusCssClass($job->status) . ' text-decoration-none']
                        ) ?>
                    </td>
                    <td>
                        <?= Html::encode($job->jobTemplate->name ?? '—') ?>
                        <?php if ($job->jobTemplate && $job->jobTemplate->isDeleted()) : ?>
                            <span class="text-muted">(deleted)</span>
                        <?php endif; ?>
                    </td>
                    <?php $recap = JobHostSummary::aggregate($job->hostSummaries); ?>
                    <td class="text-center">
                        <?= $recap['hosts'] > 0 ? $recap['hosts'] : '<span class="text-muted">—</span>' // xss-ok: integer or hardcoded HTML?>
                    </td>
                    <td>
                        <?php if ($recap['hosts'] > 0) : ?>
                        <span class="d-flex gap-1 flex-wrap" style="font-size:.7rem; line-height:1.6;">
                            <?php if ($recap['ok'] > 0) : ?>
                                <span class="badge text-bg-success"><?= $recap['ok'] // xss-ok: integer?> ok</span>
                            <?php endif; ?>
                            <?php if ($recap['changed'] > 0) : ?>
                                <span class="badge text-bg-warning"><?= $recap['changed'] // xss-ok: integer?> changed</span>
                            <?php endif; ?>
                            <?php if ($recap['failed'] > 0) : ?>
                                <span class="badge text-bg-danger"><?= $recap['failed'] // xss-ok: integer?> failed</span>
                            <?php endif; ?>
                            <?php if ($recap['unreachable'] > 0) : ?>
                                <span class="badge text-bg-dark"><?= $recap['unreachable'] // xss-ok: integer?> unreach</span>
                            <?php endif; ?>
                            <?php if ($recap['skipped'] > 0) : ?>
                                <span class="badge text-bg-secondary"><?= $recap['skipped'] // xss-ok: integer?> skip</span>
                            <?php endif; ?>
                        </span>
                        <?php else : ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                    <td><?= Html::encode($job->jobTemplate->runnerGroup->name ?? '—') ?></td>
                    <td><?= $job->started_at ? date('Y-m-d H:i', $job->started_at) : '—' ?></td>
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
