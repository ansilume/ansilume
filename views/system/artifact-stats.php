<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array{total_bytes: int, artifact_count: int, job_count: int} $stats */
/** @var list<array{job_id: int, total_bytes: int, artifact_count: int}> $topJobs */
/** @var int $retentionDays */
/** @var int $maxFileSize */
/** @var int $maxArtifactsPerJob */
/** @var int $maxJobsWithArtifacts */
/** @var int $maxBytesPerJob */
/** @var int $maxTotalBytes */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Artifact Storage';

$humanBytes = static function (int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024.0 && $i < count($units) - 1) {
        $value /= 1024.0;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 1) . ' ' . $units[$i];
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Artifact Storage</h2>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="text-muted small text-uppercase">Total bytes</div>
                <div class="fs-3 fw-semibold"><?= Html::encode($humanBytes($stats['total_bytes'])) ?></div>
                <div class="text-muted small"><?= number_format($stats['total_bytes']) ?> B</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="text-muted small text-uppercase">Artifacts</div>
                <div class="fs-3 fw-semibold"><?= number_format($stats['artifact_count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="text-muted small text-uppercase">Jobs with artifacts</div>
                <div class="fs-3 fw-semibold"><?= number_format($stats['job_count']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Configuration</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <th class="ps-3" style="width:35%">Retention</th>
                    <td>
                        <?php if ($retentionDays > 0) : ?>
                            <?= number_format($retentionDays) ?> day<?= $retentionDays === 1 ? '' : 's' ?>
                            <span class="text-muted ms-2">— older artifacts purged by <code>php yii artifact/cleanup</code></span>
                        <?php else : ?>
                            <span class="text-muted">Disabled (keep forever)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="ps-3">Max file size</th>
                    <td><?= Html::encode($humanBytes($maxFileSize)) ?></td>
                </tr>
                <tr>
                    <th class="ps-3">Max artifacts per job</th>
                    <td><?= number_format($maxArtifactsPerJob) ?></td>
                </tr>
                <tr>
                    <th class="ps-3">Max jobs with artifacts</th>
                    <td><?= $maxJobsWithArtifacts === 0 ? '<span class="text-muted">Unlimited</span>' : number_format($maxJobsWithArtifacts) ?></td>
                </tr>
                <tr>
                    <th class="ps-3">Max bytes per job</th>
                    <td>
                        <?php if ($maxBytesPerJob > 0) : ?>
                            <?= Html::encode($humanBytes($maxBytesPerJob)) ?>
                        <?php else : ?>
                            <span class="text-muted">Unlimited</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="ps-3">Global byte quota</th>
                    <td>
                        <?php if ($maxTotalBytes > 0) : ?>
                            <?= Html::encode($humanBytes($maxTotalBytes)) ?>
                            <?php $pct = $maxTotalBytes > 0 ? min(100, (int)round(($stats['total_bytes'] / $maxTotalBytes) * 100)) : 0; ?>
                            <div class="progress mt-2" style="height:6px;max-width:300px">
                                <div class="progress-bar" style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $pct ?>% used</small>
                        <?php else : ?>
                            <span class="text-muted">Unlimited</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Top jobs by artifact size</div>
    <?php if ($topJobs === []) : ?>
        <div class="card-body text-muted">No artifacts have been collected yet.</div>
    <?php else : ?>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Job</th>
                        <th class="text-end">Artifacts</th>
                        <th class="text-end pe-3">Total size</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topJobs as $row) : ?>
                    <tr>
                        <td class="ps-3">
                            <?= Html::a('#' . $row['job_id'], Url::to(['/job/view', 'id' => $row['job_id']])) ?>
                        </td>
                        <td class="text-end"><?= number_format($row['artifact_count']) ?></td>
                        <td class="text-end pe-3"><?= Html::encode($humanBytes($row['total_bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
