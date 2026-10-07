<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var list<array{code: string, message: string, credential_ids: list<int>}> $warnings from JobTemplateWarnings */

use yii\helpers\Html;

?>
<?php foreach ($warnings as $warning) : ?>
    <div class="alert alert-warning" role="alert" data-testid="template-warning" data-code="<?= Html::encode($warning['code']) ?>">
        <?= Html::encode($warning['message']) ?>
    </div>
<?php endforeach; ?>
