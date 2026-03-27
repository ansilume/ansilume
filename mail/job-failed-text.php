<?php

declare(strict_types=1);

/**
 * Job failure notification — plain text version.
 *
 * @var yii\web\View $this
 * @var app\models\Job $job
 * @var string $jobUrl
 */

$template = $job->jobTemplate;
$launcher = $job->launcher;
$started = $job->started_at ? date('Y-m-d H:i:s T', $job->started_at) : '—';
$finished = $job->finished_at ? date('Y-m-d H:i:s T', $job->finished_at) : '—';
$exitCode = $job->exit_code !== null ? (string)$job->exit_code : '—';
?>
[FAILED] Job #<?= $job->id ?>

Template:    <?= $template->name ?? '—' ?>

Playbook:    <?= $template->playbook ?? '—' ?>

Launched by: <?= $launcher->username ?? '—' ?>

Started:     <?= $started ?>

Finished:    <?= $finished ?>

Exit code:   <?= $exitCode ?>


View job details:
<?= $jobUrl ?>
