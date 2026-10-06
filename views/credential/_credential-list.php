<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var list<array{id: int, name: string|null, credential_type: string|null, role: string, deleted?: bool}> $credentials */
/** @var string $listId DOM id of the list */

// Credentials of a job template or job, in precedence order. Shows names,
// types and roles only, never secret material.

use app\models\Credential;
use yii\helpers\Html;

$canView = (bool)\Yii::$app->user?->can('credential.view');
?>
<?php if ($credentials === []) : ?>
    <span class="text-muted" id="<?= Html::encode($listId) ?>">None</span>
<?php else : ?>
    <ol class="list-unstyled mb-0" id="<?= Html::encode($listId) ?>">
        <?php foreach ($credentials as $credential) : ?>
            <?php $name = $credential['name'] ?? ('Credential #' . $credential['id']); ?>
            <li data-credential-id="<?= Html::encode((string)$credential['id']) ?>"
                data-role="<?= Html::encode($credential['role']) ?>">
                <?php if (!empty($credential['deleted'])) : ?>
                    <?= Html::encode($name) ?> <span class="text-muted">(deleted)</span>
                <?php elseif ($canView) : ?>
                    <?= Html::a(Html::encode($name), ['/credential/view', 'id' => $credential['id']]) ?>
                <?php else : ?>
                    <?= Html::encode($name) ?>
                <?php endif; ?>
                <span class="badge text-bg-light border ms-1"><?= Html::encode($credential['role']) ?></span>
                <?php if ($credential['credential_type'] !== null) : ?>
                    <span class="text-muted small ms-1">
                        <?= Html::encode(Credential::typeLabel($credential['credential_type'])) ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
