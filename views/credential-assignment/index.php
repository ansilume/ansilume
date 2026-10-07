<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Credential $vault */
/** @var list<array{id: int, name: string, project_id: int, project_name: string|null, current: string, vaults: list<array{id: int, name: string}>}> $candidates */
/** @var bool $secretUsable */

use app\helpers\ConfirmHelper;
use app\services\VaultCredentialAssignmentService as Assignment;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Assign ' . $vault->name;
$names = static fn (array $vaults): string => implode(', ', array_map(static fn (array $v): string => $v['name'], $vaults));
$selectable = count(array_filter($candidates, static fn (array $row): bool => $row['current'] !== Assignment::STATE_THIS));
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Credentials', ['/credential/index']) ?></li>
        <li class="breadcrumb-item"><?= Html::a(Html::encode($vault->name), ['/credential/view', 'id' => $vault->id]) ?></li>
        <li class="breadcrumb-item active">Assign to job templates</li>
    </ol>
</nav>
<h2><?= Html::encode('Assign "' . $vault->name . '" to job templates') ?></h2>
<p class="text-muted">
    A job template can have one vault password. Templates that have another one get it replaced; the replaced
    vault password keeps its position, so a primary stays primary. Jobs that are already waiting keep the vault
    password they were launched with.
</p>

<?php if (!$secretUsable) : ?>
    <div class="alert alert-danger" id="vault-assign-unusable">
        This vault password has no usable secret, so jobs would fail. Enter it on the credential page first.
    </div>
<?php elseif ($candidates === []) : ?>
    <p class="text-muted" id="vault-assign-empty">There are no job templates you may change.</p>
<?php else : ?>
    <form method="post" action="<?= Html::encode(Url::to(['assign', 'id' => $vault->id])) ?>" id="vault-assign-form"
          onsubmit="<?= ConfirmHelper::attribute('Assign "' . $vault->name . '" to the selected job templates? Templates with another vault password get it replaced.') ?>">
        <input type="hidden" name="<?= Html::encode(\Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(\Yii::$app->request->getCsrfToken()) ?>">
        <div class="table-responsive">
            <table class="table table-hover" id="vault-assign-templates">
                <thead class="table-light">
                    <tr>
                        <th><input type="checkbox" class="form-check-input" id="vault-assign-all" aria-label="Select all"></th>
                        <th>Job template</th>
                        <th>Project</th>
                        <th>Current vault password</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($candidates as $row) : ?>
                    <tr data-template-id="<?= Html::encode((string)$row['id']) ?>" data-current="<?= Html::encode($row['current']) ?>">
                        <td>
                            <input type="checkbox" class="form-check-input vault-assign-option" name="job_template_ids[]"
                                   value="<?= Html::encode((string)$row['id']) ?>" id="vault-assign-<?= Html::encode((string)$row['id']) ?>"
                                   aria-label="<?= Html::encode('Select ' . $row['name']) ?>"
                                <?= $row['current'] === Assignment::STATE_THIS ? 'disabled' : '' // xss-ok: literal attribute?>>
                        </td>
                        <td><label for="vault-assign-<?= Html::encode((string)$row['id']) ?>"><?= Html::encode($row['name']) ?></label></td>
                        <td class="text-muted small"><?= Html::encode($row['project_name'] ?? '') ?></td>
                        <td>
                            <?php if ($row['current'] === Assignment::STATE_NONE) : ?>
                                <span class="text-muted">none</span>
                            <?php elseif ($row['current'] === Assignment::STATE_THIS) : ?>
                                <span class="badge text-bg-success">this one</span>
                            <?php elseif ($row['current'] === Assignment::STATE_OTHER) : ?>
                                <span class="badge text-bg-warning"><?= Html::encode($names($row['vaults']) . ', will be replaced') ?></span>
                            <?php else : ?>
                                <span class="badge text-bg-danger"><?= Html::encode($names($row['vaults']) . ', will be fixed') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($selectable > Assignment::MAX_TEMPLATES) : ?>
            <p class="text-muted small" id="vault-assign-limit">
                <?= Html::encode('At most ' . Assignment::MAX_TEMPLATES . ' job templates can be assigned at once. "Select all" selects the first '
                    . Assignment::MAX_TEMPLATES . '; assign the rest in a second round.') ?>
            </p>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" id="vault-assign-submit">Assign</button>
        <?= Html::a('Cancel', ['/credential/view', 'id' => $vault->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </form>
    <script>
    // At most MAX_TEMPLATES per request: the server rejects more.
    document.getElementById('vault-assign-all').addEventListener('change', function (event) {
        var limit = <?= (int)Assignment::MAX_TEMPLATES ?>, picked = 0;
        document.querySelectorAll('.vault-assign-option:not(:disabled)').forEach(function (box) {
            box.checked = event.target.checked && picked++ < limit;
        });
    });
    </script>
<?php endif; ?>
