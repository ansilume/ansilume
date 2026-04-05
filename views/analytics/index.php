<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\AnalyticsQuery $query */
/** @var array<string, mixed> $data */
/** @var array<int, string> $projects */
/** @var array<int, string> $templates */
/** @var array<int, string> $users */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Analytics';
?>

<h2><?= Html::encode($this->title) ?></h2>

<!-- Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <?= Html::beginForm(['index'], 'get', ['class' => 'row g-2 align-items-end']) ?>
            <div class="col-auto">
                <label class="form-label form-label-sm">From</label>
                <?= Html::input(
                    'date',
                    'date_from',
                    Html::encode((string)$query->date_from),
                    ['class' => 'form-control form-control-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <label class="form-label form-label-sm">To</label>
                <?= Html::input(
                    'date',
                    'date_to',
                    Html::encode((string)$query->date_to),
                    ['class' => 'form-control form-control-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <label class="form-label form-label-sm">Project</label>
                <?= Html::dropDownList(
                    'project_id',
                    (string)$query->project_id,
                    ['' => 'All'] + $projects,
                    ['class' => 'form-select form-select-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <label class="form-label form-label-sm">Template</label>
                <?= Html::dropDownList(
                    'template_id',
                    (string)$query->template_id,
                    ['' => 'All'] + $templates,
                    ['class' => 'form-select form-select-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <label class="form-label form-label-sm">User</label>
                <?= Html::dropDownList(
                    'user_id',
                    (string)$query->user_id,
                    ['' => 'All'] + $users,
                    ['class' => 'form-select form-select-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <label class="form-label form-label-sm">Granularity</label>
                <?= Html::dropDownList(
                    'granularity',
                    Html::encode($query->granularity),
                    ['daily' => 'Daily', 'weekly' => 'Weekly'],
                    ['class' => 'form-select form-select-sm']
                ) ?>
            </div>
            <div class="col-auto">
                <?= Html::submitButton('Apply', ['class' => 'btn btn-primary btn-sm']) ?>
            </div>
        <?= Html::endForm() ?>
    </div>
</div>

<?php if ($query->hasErrors()) : ?>
    <div class="alert alert-danger">
        <?php foreach ($query->getFirstErrors() as $err) : ?>
            <div><?= Html::encode($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php elseif (!empty($data)) : ?>
    <!-- Summary Cards -->
    <?php $s = $data['summary']; ?>
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Jobs</div>
                    <div class="fs-4 fw-bold"><?= Html::encode((string)$s['total_jobs']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Succeeded</div>
                    <div class="fs-4 fw-bold text-success"><?= Html::encode((string)$s['succeeded']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Failed</div>
                    <div class="fs-4 fw-bold text-danger"><?= Html::encode((string)$s['failed']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Success Rate</div>
                    <div class="fs-4 fw-bold"><?= Html::encode((string)$s['success_rate']) ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Avg Duration</div>
                    <div class="fs-4 fw-bold"><?= Html::encode((string)$s['avg_duration_seconds']) ?>s</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">MTTR</div>
                    <div class="fs-4 fw-bold"><?= Html::encode((string)$s['mttr_seconds']) ?>s</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-trend">Job Trend</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-templates">Template Reliability</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-projects">Project Activity</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-users">User Activity</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-hosts">Host Health</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-workflows">Workflows</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-approvals">Approvals</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#tab-runners">Runners</a>
        </li>
    </ul>

    <?php
    $filterQs = http_build_query(array_filter($query->toArray(), fn ($v) => $v !== null));
    ?>

    <div class="tab-content">
        <!-- Job Trend -->
        <div class="tab-pane active" id="tab-trend">
            <canvas id="trendChart" height="80"></canvas>
            <?php if (Yii::$app->user->can('analytics.export')) : ?>
                <div class="mt-2">
                    <?= Html::a(
                        'Export CSV',
                        Url::to(['export', 'report' => 'job-trend', 'format' => 'csv'] + $query->toArray()),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Template Reliability -->
        <div class="tab-pane" id="tab-templates">
            <?php if (empty($data['templateReliability'])) : ?>
                <div class="text-muted">No data for this period.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Template</th>
                            <th>Total</th>
                            <th>Succeeded</th>
                            <th>Failed</th>
                            <th>Success Rate</th>
                            <th>Avg Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['templateReliability'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['template_name']) ?></td>
                                <td><?= Html::encode((string)$row['total']) ?></td>
                                <td class="text-success">
                                    <?= Html::encode((string)$row['succeeded']) ?>
                                </td>
                                <td class="text-danger">
                                    <?= Html::encode((string)$row['failed']) ?>
                                </td>
                                <td><?= Html::encode((string)$row['success_rate']) ?>%</td>
                                <td><?= Html::encode((string)$row['avg_duration_seconds']) ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'template-reliability', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Project Activity -->
        <div class="tab-pane" id="tab-projects">
            <?php if (empty($data['projectActivity'])) : ?>
                <div class="text-muted">No data for this period.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Project</th>
                            <th>Total</th>
                            <th>Succeeded</th>
                            <th>Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['projectActivity'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['project_name']) ?></td>
                                <td><?= Html::encode((string)$row['total']) ?></td>
                                <td class="text-success">
                                    <?= Html::encode((string)$row['succeeded']) ?>
                                </td>
                                <td class="text-danger">
                                    <?= Html::encode((string)$row['failed']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'project-activity', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- User Activity -->
        <div class="tab-pane" id="tab-users">
            <?php if (empty($data['userActivity'])) : ?>
                <div class="text-muted">No data for this period.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Total</th>
                            <th>Succeeded</th>
                            <th>Failed</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['userActivity'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['username']) ?></td>
                                <td><?= Html::encode((string)$row['total']) ?></td>
                                <td class="text-success">
                                    <?= Html::encode((string)$row['succeeded']) ?>
                                </td>
                                <td class="text-danger">
                                    <?= Html::encode((string)$row['failed']) ?>
                                </td>
                                <td><?= Html::encode((string)$row['success_rate']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'user-activity', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Host Health -->
        <div class="tab-pane" id="tab-hosts">
            <?php if (empty($data['hostHealth'])) : ?>
                <div class="text-muted">No data for this period.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Host</th>
                            <th>OK</th>
                            <th>Changed</th>
                            <th>Failed</th>
                            <th>Unreachable</th>
                            <th>Skipped</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['hostHealth'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['host']) ?></td>
                                <td class="text-success">
                                    <?= Html::encode((string)$row['ok']) ?>
                                </td>
                                <td><?= Html::encode((string)$row['changed']) ?></td>
                                <td class="text-danger">
                                    <?= Html::encode((string)$row['failed']) ?>
                                </td>
                                <td class="text-warning">
                                    <?= Html::encode((string)$row['unreachable']) ?>
                                </td>
                                <td class="text-muted">
                                    <?= Html::encode((string)$row['skipped']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'host-health', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Workflows -->
        <div class="tab-pane" id="tab-workflows">
            <?php $ws = $data['workflowSummary']; ?>
            <div class="row mb-3">
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Total</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$ws['total']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Succeeded</div>
                        <div class="fs-4 fw-bold text-success"><?= Html::encode((string)$ws['succeeded']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Failed</div>
                        <div class="fs-4 fw-bold text-danger"><?= Html::encode((string)$ws['failed']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Canceled</div>
                        <div class="fs-4 fw-bold text-warning"><?= Html::encode((string)$ws['canceled']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Success Rate</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$ws['success_rate']) ?>%</div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Avg Duration</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$ws['avg_duration_seconds']) ?>s</div>
                    </div></div>
                </div>
            </div>
            <?php if (empty($data['workflowActivity'])) : ?>
                <div class="text-muted">No workflow runs in this period.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Workflow Template</th>
                            <th>Total</th>
                            <th>Succeeded</th>
                            <th>Failed</th>
                            <th>Success Rate</th>
                            <th>Avg Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['workflowActivity'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['template_name']) ?></td>
                                <td><?= Html::encode((string)$row['total']) ?></td>
                                <td class="text-success"><?= Html::encode((string)$row['succeeded']) ?></td>
                                <td class="text-danger"><?= Html::encode((string)$row['failed']) ?></td>
                                <td><?= Html::encode((string)$row['success_rate']) ?>%</td>
                                <td><?= Html::encode((string)$row['avg_duration_seconds']) ?>s</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'workflow-activity', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Approvals -->
        <div class="tab-pane" id="tab-approvals">
            <?php $as = $data['approvalSummary']; ?>
            <div class="row mb-3">
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Total Requests</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$as['total']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Approved</div>
                        <div class="fs-4 fw-bold text-success"><?= Html::encode((string)$as['approved']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Rejected</div>
                        <div class="fs-4 fw-bold text-danger"><?= Html::encode((string)$as['rejected']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Timed Out</div>
                        <div class="fs-4 fw-bold text-warning"><?= Html::encode((string)$as['timed_out']) ?></div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Approval Rate</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$as['approval_rate']) ?>%</div>
                    </div></div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center"><div class="card-body py-2">
                        <div class="text-muted small">Avg Decision Time</div>
                        <div class="fs-4 fw-bold"><?= Html::encode((string)$as['avg_decision_seconds']) ?>s</div>
                    </div></div>
                </div>
            </div>
            <?php if (Yii::$app->user->can('analytics.export')) : ?>
                <?= Html::a(
                    'Export CSV',
                    Url::to(
                        ['export', 'report' => 'approval-summary', 'format' => 'csv']
                        + $query->toArray()
                    ),
                    ['class' => 'btn btn-outline-secondary btn-sm']
                ) ?>
            <?php endif; ?>
        </div>

        <!-- Runners -->
        <div class="tab-pane" id="tab-runners">
            <?php if (empty($data['runnerActivity'])) : ?>
                <div class="text-muted">No runners registered.</div>
            <?php else : ?>
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Runner</th>
                            <th>Group</th>
                            <th>Total Jobs</th>
                            <th>Succeeded</th>
                            <th>Failed</th>
                            <th>Success Rate</th>
                            <th>Avg Duration</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['runnerActivity'] as $row) : ?>
                            <tr>
                                <td><?= Html::encode((string)$row['runner_name']) ?></td>
                                <td><?= Html::encode((string)$row['runner_group']) ?></td>
                                <td><?= Html::encode((string)$row['total']) ?></td>
                                <td class="text-success"><?= Html::encode((string)$row['succeeded']) ?></td>
                                <td class="text-danger"><?= Html::encode((string)$row['failed']) ?></td>
                                <td><?= Html::encode((string)$row['success_rate']) ?>%</td>
                                <td><?= Html::encode((string)$row['avg_duration_seconds']) ?>s</td>
                                <td>
                                    <?php if ($row['last_seen_at'] !== null) : ?>
                                        <?= Html::encode(date('Y-m-d H:i', (int)$row['last_seen_at'])) ?>
                                    <?php else : ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (Yii::$app->user->can('analytics.export')) : ?>
                    <?= Html::a(
                        'Export CSV',
                        Url::to(
                            ['export', 'report' => 'runner-activity', 'format' => 'csv']
                            + $query->toArray()
                        ),
                        ['class' => 'btn btn-outline-secondary btn-sm']
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart.js for Job Trend -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <script>
    (function() {
        var trendData = <?= json_encode($data['jobTrend'] ?? []) ?>;
        var labels = trendData.map(function(r) { return r.period; });
        var succeeded = trendData.map(function(r) { return r.succeeded; });
        var failed = trendData.map(function(r) { return r.failed; });
        var ctx = document.getElementById('trendChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Succeeded',
                            data: succeeded,
                            backgroundColor: 'rgba(45,199,45,0.7)'
                        },
                        {
                            label: 'Failed',
                            data: failed,
                            backgroundColor: 'rgba(255,0,0,0.7)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true }
                    }
                }
            });
        }
    })();
    </script>
<?php endif; ?>
