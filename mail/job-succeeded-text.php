<?php

declare(strict_types=1);

/**
 * Job success notification — plain text version.
 *
 * @var yii\web\View $this
 * @var app\models\Job $job
 * @var string $jobUrl
 */

$template = $job->jobTemplate;
$launcher = $job->launcher;
$started = $job->started_at ? date('Y-m-d H:i:s T', $job->started_at) : '—';
$finished = $job->finished_at ? date('Y-m-d H:i:s T', $job->finished_at) : '—';

$duration = '—';
if ($job->started_at !== null && $job->finished_at !== null) {
    $secs = $job->finished_at - $job->started_at;
    if ($secs < 60) {
        $duration = $secs . 's';
    } elseif ($secs < 3600) {
        $duration = sprintf('%dm %ds', intdiv($secs, 60), $secs % 60);
    } else {
        $duration = sprintf('%dh %dm', intdiv($secs, 3600), intdiv($secs % 3600, 60));
    }
}
?>
[SUCCEEDED] Job #<?= $job->id ?>

Template:    <?= $template->name ?? '—' ?>

Playbook:    <?= $template->playbook ?? '—' ?>

Launched by: <?= $launcher->username ?? '—' ?>

Started:     <?= $started ?>

Finished:    <?= $finished ?>

Duration:    <?= $duration ?>


View job details:
<?= $jobUrl ?>
