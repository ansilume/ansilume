<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\JobTemplate $model */
/** @var list<array{id: int, name: string, credential_type: string, role: string}> $attachedCredentials  in precedence order */
/** @var list<array{code: string, message: string, credential_ids: list<int>}> $warnings from JobTemplateWarnings */
/** @var bool $canChange job-template.update and operator access to the template's project */
/** @var bool $changeLocked $canChange, but not what the template runs: a gated template, no approval-rule.update */
/** @var bool $canViewRules approval-rule.view: the approval rule as a link, else as text */

use app\components\LintVerdict;
use app\helpers\TimeHelper;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateRuleAssignment;
use yii\helpers\Html;

$this->title = $model->name;
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Templates', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($model->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <h2><?= Html::encode($model->name) ?></h2>
    <div>
        <?php if (\Yii::$app->user?->can('job.launch')) : ?>
            <?= Html::a('Launch', ['launch', 'id' => $model->id], ['class' => 'btn btn-success']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('job-template.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-1']) ?>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('job-template.create')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['clone', 'id' => $model->id]) ?>" style="display:inline">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-secondary ms-1" title="Duplicate this template — you'll land on the edit form for the copy">Clone</button>
            </form>
        <?php endif; ?>
        <?php if (\Yii::$app->user?->can('job-template.delete')) : ?>
            <form method="post" action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Delete this template? Its waiting jobs are canceled and its schedules disabled.')">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="btn btn-outline-danger ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($model->inventory !== null) : ?>
    <?= $this->render('/inventory/_localhost-warning', ['inventory' => $model->inventory]) ?>
<?php endif; ?>
<?= $this->render('_warnings', ['warnings' => $warnings]) ?>
<?php if ($changeLocked) : ?>
    <div class="alert alert-info" id="job-template-locked-notice"><?= Html::encode(GatedJobTemplateLock::NOTICE) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Execution</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Project</dt>
                    <dd class="col-7"><?= $model->project ? Html::a(Html::encode($model->project->name), ['/project/view', 'id' => $model->project_id]) : '—' ?></dd>
                    <dt class="col-5">Playbook</dt>
                    <dd class="col-7"><code><?= Html::encode($model->playbook) ?></code></dd>
                    <dt class="col-5">Inventory</dt>
                    <dd class="col-7"><?= $model->inventory ? Html::a(Html::encode($model->inventory->name), ['/inventory/view', 'id' => $model->inventory_id]) : '—' ?></dd>
                    <dt class="col-5">Credentials</dt>
                    <dd class="col-7"><?= $this->render('/credential/_credential-list', ['credentials' => $attachedCredentials, 'listId' => 'template-credentials']) ?></dd>
                    <dt class="col-5">Approval rule</dt>
                    <dd class="col-7" id="job-template-approval-rule">
                        <?php $approvalRule = $model->approval_rule_id === null ? null : $model->approvalRule; ?>
                        <?php if ($approvalRule === null) : ?>
                            <span class="text-muted">None</span>
                        <?php elseif ($canViewRules) : ?>
                            <?= Html::a(Html::encode(JobTemplateRuleAssignment::label($approvalRule)), ['/approval-rule/view', 'id' => $approvalRule->id]) ?>
                        <?php else : ?>
                            <?= Html::encode(JobTemplateRuleAssignment::label($approvalRule)) ?>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5">Forks</dt>
                    <dd class="col-7"><?= $model->forks // xss-ok: integer?></dd>
                    <dt class="col-5">Verbosity</dt>
                    <dd class="col-7"><?= $model->verbosity // xss-ok: integer?></dd>
                    <dt class="col-5">Timeout</dt>
                    <dd class="col-7"><?= $model->timeout_minutes // xss-ok: integer?> min</dd>
                    <?php if ($model->limit) :
                        ?><dt class="col-5">Limit</dt><dd class="col-7"><code><?= Html::encode($model->limit) ?></code></dd><?php
                    endif; ?>
                    <?php if ($model->tags) :
                        ?><dt class="col-5">Tags</dt><dd class="col-7"><code><?= Html::encode($model->tags) ?></code></dd><?php
                    endif; ?>
                    <?php if ($model->skip_tags) :
                        ?><dt class="col-5">Skip tags</dt><dd class="col-7"><code><?= Html::encode($model->skip_tags) ?></code></dd><?php
                    endif; ?>
                    <dt class="col-5">Become</dt>
                    <dd class="col-7">
                        <?php if ($model->become) : ?>
                            Yes — <code><?= Html::encode($model->become_method) ?></code> as <code><?= Html::encode($model->become_user) ?></code>
                        <?php else : ?>
                            No
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if ($model->extra_vars) : ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Default Extra Vars</div>
            <div class="card-body p-0">
                <?php // Values of password survey fields' keys show as the placeholder.?>
                <pre class="job-log m-0" style="max-height:200px;overflow-y:auto;" data-testid="template-extra-vars"><?= Html::encode((string)$model->getMaskedExtraVars()) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // The raw token right after it was generated, shown once. Read and
    // deleted here, card or not: the layout prints every flash that is left.
    // Shown only while it is still the template's token.
    $flashedToken = \Yii::$app->session?->getFlash('trigger_token_raw', null, true);
    $freshToken = is_string($flashedToken) && is_string($model->trigger_token)
        && hash_equals($model->trigger_token, hash('sha256', $flashedToken)) ? $flashedToken : null;
    ?>
    <?php // Whether a trigger exists and whom it runs as: only for users who may change the template.?>
    <?php if ($canChange) : ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Inbound Trigger</span>
                <?php if ($model->trigger_token) : ?>
                    <form method="post" action="<?= \yii\helpers\Url::to(['revoke-trigger-token', 'id' => $model->id]) ?>" style="display:inline" onsubmit="return confirm('Revoke the trigger token? Any existing integrations will stop working.')">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Revoke Token</button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?= \yii\helpers\Url::to(['generate-trigger-token', 'id' => $model->id]) ?>" style="display:inline">
                        <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Generate Token</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($model->trigger_token) : ?>
                    <?php
                    $triggerUserId = $model->getTriggerUserId();
                    $triggerUserName = \app\models\User::findOne($triggerUserId)->username ?? ('user #' . $triggerUserId);
                    $triggerUserNote = $model->trigger_token_created_by === null
                        ? 'the template\'s creator, because this token was generated before Ansilume recorded who generated '
                            . 'tokens. Revoke it and generate a new one to run the trigger as yourself.'
                        : 'who generated the token.';
                    ?>
                    <p class="mb-3" data-testid="trigger-runs-as">
                        Runs as <strong><?= Html::encode($triggerUserName) ?></strong>, <?= Html::encode($triggerUserNote) ?>
                        <br><small class="text-muted">Calls are refused while this user is disabled or may not launch this template.</small>
                    </p>
                    <?= $this->render('/trigger/_usage', [
                        'rawToken' => $freshToken,
                        'endpoint' => '/trigger/fire',
                        'idPrefix' => '',
                        'bodyExample' => '{"extra_vars":{"env":"prod"},"limit":"host1"}',
                        // Deprecated: the token in the query of /trigger/fire, or the path /trigger/<token>.
                        'deprecatedUrlForms' => ['?token=…', '/trigger/<token>'],
                    ]) ?>
                <?php else : ?>
                    <p class="mb-0 text-muted" data-testid="trigger-not-configured">No trigger token configured. Generate one to allow external systems to launch this template via HTTP POST. The trigger will run as you.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <?php
            $lintVerdict = LintVerdict::of($model->lint_exit_code, $model->lint_output);
            ?>
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Ansible Lint <small class="text-muted fw-normal">(--profile production)</small></span>
                <span>
                    <span class="badge <?= Html::encode(LintVerdict::badgeClass($lintVerdict)) ?>" data-testid="lint-badge"><?= Html::encode(LintVerdict::label($lintVerdict)) ?></span>
                    <?php if ($model->lint_at !== null) : ?>
                        <small class="text-muted ms-2"><?= date('Y-m-d H:i', $model->lint_at) // xss-ok: date() output?></small>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if ($model->lint_output) : ?>
                    <?php if ($lintVerdict === LintVerdict::VAULT_SKIPPED) : ?>
                        <p class="text-muted small px-3 pt-3 mb-2" data-testid="lint-vault-note"><?= Html::encode(LintVerdict::VAULT_NOTE) ?></p>
                        <details><summary class="px-3 small">Show ansible-lint output</summary>
                    <?php endif; ?>
                    <pre class="job-log m-0" id="template-lint-output" style="max-height:300px;overflow-y:auto;"></pre>
                    <?php if ($lintVerdict === LintVerdict::VAULT_SKIPPED) : ?>
                        </details>
                    <?php endif; ?>
                    <script src="<?= \Yii::$app->request->baseUrl ?>/js/ansi_up.min.js"></script>
                    <script>
                    (function () {
                        var au = new AnsiUp();
                        au.use_classes = false;
                        au.ansi_colors[0][0].rgb = [118, 118, 118];
                        au.ansi_colors[0][4].rgb = [77, 159, 236];
                        au.ansi_colors[0][5].rgb = [198, 120, 221];
                        var el = document.getElementById('template-lint-output');
                        el.innerHTML = au.ansi_to_html(<?= \yii\helpers\Json::htmlEncode($model->lint_output) // xss-ok: hex-escaped JSON?>);
                    })();
                    </script>
                <?php else : ?>
                    <p class="text-muted p-3 mb-0">Lint output will appear here after saving the template or syncing the project.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">Recent Jobs</div>
            <div class="card-body p-0">
                <?php $jobs = $model->getJobs()->with('launcher')->orderBy(['id' => SORT_DESC])->limit(10)->all(); ?>
                <?php if (empty($jobs)) : ?>
                    <p class="text-muted p-3 mb-0">No jobs yet.</p>
                <?php else : ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Status</th><th>Launched by</th><th>Started</th><th>Duration</th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job) : ?>
                            <tr>
                                <td><?= Html::a('#' . $job->id, ['/job/view', 'id' => $job->id]) ?></td>
                                <td><span class="badge text-bg-<?= \app\models\Job::statusCssClass($job->status) ?>"><?= Html::encode(\app\models\Job::statusLabel($job->status)) ?></span></td>
                                <td><?= Html::encode($job->launcher->username ?? '—') ?></td>
                                <td><?= TimeHelper::relative($job->started_at) ?></td>
                                <td><?php
                                if ($job->started_at !== null && $job->finished_at !== null) {
                                        echo gmdate('i:s', $job->finished_at - $job->started_at);
                                } else {
                                    echo '—';
                                }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
