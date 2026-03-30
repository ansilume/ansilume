<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RunnerGroup $group */
/** @var app\models\Runner[] $runners */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $group->name;

$apiUrl = \Yii::$app->request->hostInfo;

// Show token flash (only displayed once after creation or regen)
$tokenFlash = \Yii::$app->session?->getFlash('runner_token');
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Runner Groups', ['index']) ?></li>
        <li class="breadcrumb-item active"><?= Html::encode($group->name) ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h2 class="mb-0"><?= Html::encode($group->name) ?></h2>
        <?php if ($group->description) : ?>
            <p class="text-muted mt-1 mb-0"><?= Html::encode($group->description) ?></p>
        <?php endif; ?>
    </div>
    <?php if (\Yii::$app->user?->can('runner-group.update')) : ?>
        <?= Html::a('Edit Group', ['update', 'id' => $group->id], ['class' => 'btn btn-outline-secondary']) ?>
    <?php endif; ?>
</div>

<?php if ($tokenFlash) : ?>
<div class="alert alert-warning border-warning" role="alert">
    <h5 class="alert-heading">Token for runner <strong><?= Html::encode($tokenFlash['runner_name']) ?></strong></h5>
    <p class="mb-2">This token is shown <strong>once only</strong>. Copy it now — it cannot be retrieved again.</p>
    <div class="input-group mb-3">
        <input type="text" class="form-control font-monospace" id="token-display"
               value="<?= Html::encode($tokenFlash['raw_token']) ?>" readonly>
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('token-display').value)">Copy</button>
    </div>
    <p class="mb-1"><strong>Docker one-liner:</strong></p>
    <?php
    $dockerSnippet = "docker run --rm \\\n" .
        "  -e RUNNER_TOKEN=" . $tokenFlash['raw_token'] . " \\\n" .
        "  -e API_URL=" . $apiUrl . " \\\n" .
        "  ghcr.io/ansilume/ansilume:latest \\\n" .
        "  php yii runner/start";
    ?>
    <pre class="bg-dark text-light p-2 rounded small mb-0"><?= Html::encode($dockerSnippet) ?></pre>
</div>
<?php endif; ?>

<!-- Add Runner form -->
<?php if (\Yii::$app->user?->can('runner-group.update')) : ?>
<div class="card mb-4">
    <div class="card-header">Add Runner</div>
    <div class="card-body">
        <form method="post" action="<?= Url::to(['/runner/create']) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
            <input type="hidden" name="group_id" value="<?= $group->id ?>">
            <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. standort1-runner-a" required maxlength="128">
            </div>
            <div class="col-md-5">
                <label class="form-label">Description <span class="text-muted">(optional)</span></label>
                <input type="text" name="description" class="form-control" placeholder="Location or purpose">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100">Create &amp; Get Token</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Runners list -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Runners (<?= count($runners) ?>)</span>
        <?php
        $onlineCount = count(array_filter($runners, fn ($r) => $r->isOnline()));
        $badge = count($runners) > 0 && $onlineCount === 0 ? 'danger' : ($onlineCount > 0 ? 'success' : 'secondary');
        ?>
        <span class="badge text-bg-<?= $badge ?>"><?= $onlineCount ?>/<?= count($runners) ?> online</span>
    </div>
    <?php if (empty($runners)) : ?>
        <div class="card-body text-muted small">No runners yet. Add one above.</div>
    <?php else : ?>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Last seen</th>
                    <th>Description</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runners as $runner) : ?>
                <tr>
                    <td class="fw-semibold"><?= Html::encode($runner->name) ?></td>
                    <td>
                        <?php if ($runner->isOnline()) : ?>
                            <span class="badge text-bg-success">Online</span>
                        <?php elseif ($runner->last_seen_at !== null) : ?>
                            <span class="badge text-bg-secondary">Offline</span>
                        <?php else : ?>
                            <span class="badge text-bg-warning">Never seen</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small">
                        <?= $runner->last_seen_at ? date('Y-m-d H:i:s', $runner->last_seen_at) : '—' ?>
                    </td>
                    <td class="text-muted small"><?= Html::encode($runner->description ?? '') ?></td>
                    <td class="text-end">
                        <?php if (\Yii::$app->user?->can('runner-group.update')) : ?>
                            <form method="post" action="<?= Url::to(['/runner/regenerate-token', 'id' => $runner->id]) ?>" style="display:inline" onsubmit="return confirm('Regenerate token for &quot;<?= addslashes($runner->name) ?>&quot;? The old token will stop working immediately.')">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning me-1">Regen Token</button>
                            </form>
                            <form method="post" action="<?= Url::to(['/runner/delete', 'id' => $runner->id]) ?>" style="display:inline" onsubmit="return confirm('Delete runner &quot;<?= addslashes($runner->name) ?>&quot;?')">
                                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
