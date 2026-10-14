<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\WorkflowJob $model */
/** @var bool $mayResume operator access to the project of every job step of the workflow, and workflow.launch */
/** @var bool $mayCancel operator access to the project of every job step of the workflow, and workflow.cancel */
/** @var array<string, mixed> $payload runstate\PollPayload::of(): what /workflow-job/status answers, rendered here once */

use app\components\VersionedAssetUrl;
use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Workflow Job #' . $model->id;
/** @var list<array<string, mixed>> $steps */
$steps = $payload['steps'];
/** @var array{resume: bool, cancel: bool} $actions */
$actions = $payload['actions'];
$finished = (bool)$payload['is_finished'];
// The extra vars the run was launched with, a password answer as
// $encrypted$: the sealed answers are never shown.
$launchExtraVars = $model->launchExtraVars();
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0"><?= Html::encode($this->title) ?></h2>
    <div id="wj-actions">
        <?php // Rendered while the run is unfinished for whoever may use them; the page's script shows and hides them as the poll says.?>
        <?php if ($mayResume && !$finished) : ?>
            <form id="wj-resume-form" action="<?= Html::encode(Url::to(['resume', 'id' => $model->id])) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Resume this workflow?')"<?= $actions['resume'] ? '' : ' hidden' // xss-ok: a fixed attribute?>>
                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                <button type="submit" class="btn btn-success btn-sm">Resume</button>
            </form>
        <?php endif; ?>
        <?php if ($mayCancel && !$finished) : ?>
            <form id="wj-cancel-form" action="<?= Html::encode(Url::to(['cancel', 'id' => $model->id])) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Cancel this workflow?')"<?= $actions['cancel'] ? '' : ' hidden' // xss-ok: a fixed attribute?>>
                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->csrfToken) ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Cancel</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?= $this->render('_banner', ['banner' => $payload['banner']]) ?>

<div class="row">
    <div class="col-lg-10">
        <table class="table table-bordered mb-4">
            <tr>
                <th style="width:180px">Status</th>
                <td>
                    <span id="wj-status" class="badge text-bg-<?= Html::encode((string)$payload['state_css']) ?>"><?= Html::encode((string)$payload['state_label']) ?></span>
                    <?php if (!$finished) : ?>
                        <span id="wj-live" class="badge text-bg-primary ms-2">Live</span>
                        <?php // The script shows the note in place of the Live badge once its poll is refused.?>
                        <span id="wj-live-stopped" class="small text-body-secondary ms-2" role="status" hidden><?= Html::encode(Texts::LIVE_STOPPED_RUN) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Workflow</th>
                <td id="wj-workflow"><?= $this->render('_workflow-name', [
                    'name' => $model->workflowTemplate?->name,
                    'id' => (int)$model->workflow_template_id,
                    'deleted' => (bool)$model->workflowTemplate?->isDeleted(),
                    'link' => true,
                ]) ?></td>
            </tr>
            <tr>
                <th>Launched By</th>
                <td><?= Html::encode($model->launcher?->username ?? PollPayload::NO_VALUE) ?></td>
            </tr>
            <tr>
                <th>Started</th>
                <td><?= Html::encode($payload['started_label'] ?? PollPayload::NO_VALUE) ?></td>
            </tr>
            <tr>
                <th>Finished</th>
                <td id="wj-finished"><?= Html::encode($payload['finished_label'] ?? PollPayload::NO_VALUE) ?></td>
            </tr>
            <?php if ($launchExtraVars !== []) : ?>
            <tr>
                <th>Extra Vars</th>
                <td>
                    <pre class="job-log m-0" id="wj-extra-vars" style="max-height:150px;overflow-y:auto;"><?= Html::encode((string)json_encode((object)$launchExtraVars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                    <div class="form-text small">Every job step of the run received these. Password answers show as <code>$encrypted$</code>.</div>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <h4>Steps</h4>
        <?php // Always render the table: the page's script appends rows for
        // steps that start after the page loaded (a step's execution is
        // created when the run reaches it).?>
        <?php if ($steps === []) : ?>
            <div class="text-muted small mb-2" id="wj-empty-hint">Waiting for the first step…</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered" id="wj-steps-table">
                <thead class="table-light">
                    <tr>
                        <th title="<?= Html::encode(PollPayload::ORDER_TITLE) ?>">#</th>
                        <th>Step</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Job / Request</th>
                        <th>Started</th>
                        <th>Duration</th>
                        <th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $step) : ?>
                        <?= $this->render('_step-row', ['step' => $step]) ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!$finished) : ?>
    <?php // The script polls /workflow-job/status and updates the page in place until the run has finished; it never reloads.?>
<div id="wj-live-root" hidden
     data-poll-url="<?= Html::encode(Url::to(['status', 'id' => $model->id])) ?>"
     data-job-url="<?= Html::encode(Url::to(['/job/view', 'id' => 'JOBID'])) ?>"
     data-banner="<?= Html::encode(Json::htmlEncode($payload['banner'])) ?>"></div>
    <?= Html::jsFile(VersionedAssetUrl::of('/js/workflow-run-live.js')) ?>
<?php endif; ?>
