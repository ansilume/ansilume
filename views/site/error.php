<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $name */
/** @var string $message */

use yii\helpers\Html;

$this->title = $name;
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-6 text-center">
        <h1 class="display-4"><?= Html::encode($name) ?></h1>
        <p class="lead text-muted"><?= Html::encode($message) ?></p>
        <a href="<?= \yii\helpers\Url::to(['/']) ?>" class="btn btn-primary">Back to Dashboard</a>
    </div>
</div>
