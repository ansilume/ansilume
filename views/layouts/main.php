<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use yii\helpers\Html;

$this->beginPage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($this->title) ?> — Ansilume</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand { font-weight: 700; letter-spacing: .05em; }
        .job-status-badge { font-size: .75rem; }
        pre.job-log { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: .375rem; overflow-x: auto; }
    </style>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>

<nav class="navbar navbar-expand-md navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= \yii\helpers\Url::to(['/']) ?>">Ansilume</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <?php if (!\Yii::$app->user->isGuest): ?>
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/project/index']) ?>">Projects</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/inventory/index']) ?>">Inventories</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/credential/index']) ?>">Credentials</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/job-template/index']) ?>">Templates</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/job/index']) ?>">Jobs</a></li>
                <?php if (\Yii::$app->user->can('job.launch')): ?>
                <li class="nav-item"><a class="nav-link" href="<?= \yii\helpers\Url::to(['/schedule/index']) ?>">Schedules</a></li>
                <?php endif; ?>
                <?php if (\Yii::$app->user->can('user.view')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
                    <ul class="dropdown-menu">
                        <li><?= Html::a('Users', ['/user/index'], ['class' => 'dropdown-item']) ?></li>
                        <li><?= Html::a('Teams', ['/team/index'], ['class' => 'dropdown-item']) ?></li>
                        <li><?= Html::a('Audit Log', ['/audit-log/index'], ['class' => 'dropdown-item']) ?></li>
                        <li><?= Html::a('Webhooks', ['/webhook/index'], ['class' => 'dropdown-item']) ?></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <?= Html::encode(\Yii::$app->user->identity->username) ?>
                        <?php if (\Yii::$app->user->identity->is_superadmin): ?>
                            <span class="badge text-bg-warning ms-1" style="font-size:.65rem">SA</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><?= Html::a('API Tokens', ['/profile/tokens'], ['class' => 'dropdown-item']) ?></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <?= Html::a('Logout', ['/site/logout'], [
                                'class' => 'dropdown-item',
                                'data'  => ['method' => 'post'],
                            ]) ?>
                        </li>
                    </ul>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="container-fluid px-4">
    <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $messages): ?>
        <?php foreach ((array)$messages as $message): ?>
            <div class="alert alert-<?= Html::encode($type) ?> alert-dismissible fade show" role="alert">
                <?= Html::encode($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
