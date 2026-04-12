<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $name */
/** @var string $message */
/** @var int $statusCode */

use yii\helpers\Html;

$this->title = $name;
$statusCode = \Yii::$app->response->statusCode;
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-6 text-center">
        <div class="display-1 text-muted mb-3"><?= $statusCode ?></div>
        <h1 class="h3"><?= Html::encode($name) ?></h1>
        <p class="text-muted mt-3"><?= Html::encode($message) ?></p>
        <?php if ($statusCode === 404) : ?>
            <p class="text-muted small">The page you requested does not exist or has been moved.</p>
        <?php elseif ($statusCode === 403) : ?>
            <p class="text-muted small">You do not have permission to access this resource. Contact your administrator if you believe this is an error.</p>
        <?php elseif ($statusCode >= 500) : ?>
            <p class="text-muted small">An internal error occurred. If this persists, check the application logs or contact your administrator.</p>
        <?php endif; ?>
        <a href="<?= \yii\helpers\Url::to(['/']) ?>" class="btn btn-primary mt-3">Back to Dashboard</a>
    </div>
</div>
