<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\JobTemplate|app\models\Project $model */
/** @var string $scope what is linted, after the card's title, such as "--profile production" */
/** @var string $outputId the id of the element of the output */
/** @var int $maxHeight the output's height in pixels, at most */
/** @var string $emptyText what the card says while no result is stored and no run is queued */
/** @var bool $workerMissing a run is queued, and no queue worker beats (LintCardTrait::lintCard()) */

use app\components\LintState;
use app\components\LintVerdict;
use app\controllers\ProjectController;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

// The lint card of a job template's and a project's page: the stored result
// of the last lint run and, while a run is queued (lint_queued_at), the badge
// "Lint queued" with when it was requested, the stored result marked as the
// previous run, the hint that no queue worker runs, and a poll of the page
// controller's lint-status that reloads the page once the run has stored its
// result (LintCardTrait).
$lintState = LintState::of($model);
$lintVerdict = LintVerdict::of($model->lint_exit_code, $model->lint_output);
?>
<div class="card" id="lint-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Ansible Lint <small class="text-muted fw-normal">(<?= Html::encode($scope) ?>)</small></span>
        <span>
            <?php if ($lintState->isQueued()) : ?>
                <span class="badge text-bg-info" data-testid="lint-queued"><?= Html::encode(LintState::QUEUED) ?></span>
                <small class="text-muted ms-1 me-2" data-testid="lint-queued-at"><?= Html::encode(sprintf(LintState::QUEUED_SINCE, date('Y-m-d H:i:s', (int)$lintState->queuedAt()))) ?></small>
            <?php endif; ?>
            <span class="badge <?= Html::encode(LintVerdict::badgeClass($lintVerdict)) ?>" data-testid="lint-badge"><?= Html::encode(LintVerdict::label($lintVerdict)) ?></span>
            <?php if ($model->lint_at !== null) : ?>
                <small class="text-muted ms-2"><?= date('Y-m-d H:i', $model->lint_at) // xss-ok: date() output?></small>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if ($workerMissing) : ?>
            <div class="alert alert-danger small m-3 mb-0" role="status" data-testid="lint-no-worker">&#9888; <?= Html::encode(ProjectController::NO_QUEUE_WORKER) ?> <code><?= Html::encode(ProjectController::START_QUEUE_WORKER) ?></code></div>
        <?php endif; ?>
        <?php if ($model->lint_output) : ?>
            <?php if ($lintState->isQueued()) : ?>
                <p class="text-muted small px-3 pt-3 mb-2" data-testid="lint-previous"><?= Html::encode(sprintf(LintState::PREVIOUS_RUN, $model->lint_at !== null ? date('Y-m-d H:i', $model->lint_at) : '—')) ?></p>
            <?php endif; ?>
            <?php if ($lintVerdict === LintVerdict::VAULT_SKIPPED) : ?>
                <p class="text-muted small px-3 pt-3 mb-2" data-testid="lint-vault-note"><?= Html::encode(LintVerdict::VAULT_NOTE) ?></p>
                <details><summary class="px-3 small">Show ansible-lint output</summary>
            <?php endif; ?>
            <pre class="job-log m-0" id="<?= Html::encode($outputId) ?>" style="max-height:<?= (int)$maxHeight // xss-ok: integer?>px;overflow-y:auto;"></pre>
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
                var el = document.getElementById(<?= Json::htmlEncode($outputId) // xss-ok: hex-escaped JSON?>);
                el.innerHTML = au.ansi_to_html(<?= Json::htmlEncode($model->lint_output) // xss-ok: hex-escaped JSON?>);
            })();
            </script>
        <?php else : ?>
            <p class="text-muted p-3 mb-0" data-testid="lint-empty"><?= Html::encode($lintState->isQueued() ? LintState::FIRST_RUN_QUEUED : $emptyText) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php if ($lintState->isQueued()) : ?>
<script>
(function () {
    // While the run is queued: ask every two seconds whether it still is,
    // and reload the page once its result is stored. A refused poll (401 or
    // 403: signed out, or no access any more; 404: the record is gone) would
    // be refused again: polling stops, and a note asks for a reload. Any
    // other answer that is not OK is a failed poll: the next one asks again.
    var statusUrl = <?= Json::htmlEncode(Url::to(['lint-status', 'id' => $model->id])) // xss-ok: hex-escaped JSON?>;
    var stoppedNote = <?= Json::htmlEncode(LintState::LIVE_STOPPED) // xss-ok: hex-escaped JSON?>;
    var card = document.getElementById('lint-card');
    var stopStatuses = [401, 403, 404];
    var timer = null;
    var stopped = false;

    function stop() {
        clearInterval(timer);
        if (stopped) return;
        stopped = true;
        var note = document.createElement('div');
        note.className = 'card-footer py-1 px-2 small text-body-secondary';
        note.setAttribute('role', 'status');
        note.setAttribute('data-testid', 'lint-live-stopped');
        note.textContent = stoppedNote;
        card.appendChild(note);
    }

    function poll() {
        if (stopped) return;
        // Asked for JSON, a signed-out poll is answered 403, not sent to the
        // login page, which would keep the poll as the address to return to.
        fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                if (stopStatuses.indexOf(r.status) !== -1) {
                    stop();
                    return null;
                }
                if (!r.ok) {
                    throw new Error('lint poll failed: ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                if (data !== null && data.queued === false && !stopped) {
                    clearInterval(timer);
                    window.location.reload();
                }
            })
            .catch(function () { /* a failed poll: the next one asks again */ });
    }

    timer = setInterval(poll, 2000);
})();
</script>
<?php endif; ?>
