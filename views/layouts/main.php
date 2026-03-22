<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var string $content */

use yii\helpers\Html;
use yii\helpers\Url;

$this->beginPage();

// Required for Html::a() data-method="post" and data-confirm to work
\yii\web\YiiAsset::register($this);

$route = Yii::$app->requestedRoute ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($this->title) ?> — Ansilume</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <style>
        :root { --sidebar-width: 220px; }

        body { background-color: #0f1117; }

        /* Sidebar */
        #sidebar {
            position: fixed; top: 0; left: 0; bottom: 0;
            width: var(--sidebar-width);
            background: #13161b;
            border-right: 1px solid rgba(255,255,255,.06);
            display: flex; flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }
        #sidebar .sidebar-brand {
            display: flex; align-items: center; gap: .6rem;
            padding: 1.1rem 1.2rem;
            color: #fff; font-weight: 700; font-size: 1.05rem;
            letter-spacing: .04em; text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,.07);
        }
        #sidebar .sidebar-brand:hover { color: #fff; }
        #sidebar .nav-section {
            font-size: .68rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .08em; color: #495057;
            padding: 1.1rem 1.2rem .3rem;
        }
        #sidebar .nav-link {
            color: #8a9099; padding: .45rem 1.2rem;
            border-radius: 0; font-size: .9rem;
            display: flex; align-items: center; gap: .55rem;
        }
        #sidebar .nav-link:hover { color: #e9ecef; background: rgba(255,255,255,.05); }
        #sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.09); }
        #sidebar .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,.07);
            padding: .75rem 1.2rem;
        }
        #sidebar .sidebar-footer .nav-link { padding: .4rem 0; }

        /* Main content offset */
        #main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex; flex-direction: column;
        }
        #page-content {
            flex: 1;
            padding: 1.75rem 2rem;
        }

        /* Responsive: collapse sidebar on small screens */
        @media (max-width: 767px) {
            #sidebar { transform: translateX(-100%); transition: transform .2s; }
            #sidebar.show { transform: translateX(0); }
            #main-wrapper { margin-left: 0; }
            #sidebar-toggle { display: flex !important; }
        }

        /* Top bar (mobile toggle) */
        #topbar {
            background: #13161b;
            border-bottom: 1px solid rgba(255,255,255,.06);
            padding: .5rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
        }
        #sidebar-toggle { display: none; }

        /* Content tweaks */
        .job-status-badge { font-size: .75rem; }
        pre.job-log { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: .375rem; overflow-x: auto; }
        /* ANSI color overrides for dark terminal background.
           The default 16-color palette has dim variants (30-37) that are
           near-black and unreadable on #1e1e1e. Remap the worst offenders. */
        pre.job-log .ansi-black-fg   { color: #767676; }
        pre.job-log .ansi-blue-fg    { color: #4d9fec; }
        pre.job-log .ansi-magenta-fg { color: #c678dd; }
        pre.job-log .ansi-cyan-fg    { color: #56b6c2; }
        pre.job-log .ansi-white-fg   { color: #d4d4d4; }
        /* Yii2 ActiveForm uses Bootstrap 3 classes — make them visible under Bootstrap 5 */
        .help-block { display: block; font-size: .875em; color: #dc3545; margin-top: .25rem; }
        .has-error .form-control { border-color: #dc3545; }
        .has-error .control-label { color: #dc3545; }
        .has-success .form-control { border-color: #198754; }
    </style>
    <meta name="csrf-token" content="<?= \Yii::$app->request->getCsrfToken() ?>">
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>

<?php
// Helper: is a given route prefix active?
$active = fn(string $prefix): string => str_starts_with($route, $prefix) ? ' active' : '';
?>

<div id="sidebar">
    <a class="sidebar-brand justify-content-center" href="<?= Url::to(['/']) ?>" style="background:#fff; padding:1rem 1.2rem;">
        <img src="/ansilume.png" alt="Ansilume" style="width:100%; max-width:160px; object-fit:contain;">
    </a>

    <?php if (!\Yii::$app->user->isGuest): ?>

    <span class="nav-section">Automation</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('project') ?>"      href="<?= Url::to(['/project/index']) ?>">Projects</a>
        <a class="nav-link<?= $active('inventory') ?>"    href="<?= Url::to(['/inventory/index']) ?>">Inventories</a>
        <a class="nav-link<?= $active('credential') ?>"   href="<?= Url::to(['/credential/index']) ?>">Credentials</a>
        <a class="nav-link<?= $active('job-template') ?>" href="<?= Url::to(['/job-template/index']) ?>">Templates</a>
    </nav>

    <span class="nav-section">Operations</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('job') ?>"      href="<?= Url::to(['/job/index']) ?>">Jobs</a>
        <?php if (\Yii::$app->user->can('job.launch')): ?>
        <a class="nav-link<?= $active('schedule') ?>" href="<?= Url::to(['/schedule/index']) ?>">Schedules</a>
        <?php endif; ?>
    </nav>

    <?php if (\Yii::$app->user->can('user.view')): ?>
    <span class="nav-section">Admin</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('user') ?>"      href="<?= Url::to(['/user/index']) ?>">Users</a>
        <a class="nav-link<?= $active('team') ?>"      href="<?= Url::to(['/team/index']) ?>">Teams</a>
        <a class="nav-link<?= $active('audit-log') ?>" href="<?= Url::to(['/audit-log/index']) ?>">Audit Log</a>
        <a class="nav-link<?= $active('webhook') ?>"   href="<?= Url::to(['/webhook/index']) ?>">Webhooks</a>
    </nav>
    <?php endif; ?>

    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="text-white" style="font-size:.85rem"><?= Html::encode(\Yii::$app->user->identity->username) ?></span>
            <?php if (\Yii::$app->user->identity->is_superadmin): ?>
                <span class="badge text-bg-warning" style="font-size:.6rem">SA</span>
            <?php endif; ?>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="<?= Url::to(['/profile/tokens']) ?>">API Tokens</a>
            <?= Html::a('Logout', ['/site/logout'], [
                'class' => 'nav-link',
                'data'  => ['method' => 'post'],
            ]) ?>
        </nav>
    </div>

    <?php endif; ?>
</div>

<div id="main-wrapper">
    <div id="topbar">
        <button id="sidebar-toggle" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('sidebar').classList.toggle('show')">&#9776;</button>
    </div>

    <div id="page-content">
        <?php foreach (\Yii::$app->session->getAllFlashes() as $type => $messages): ?>
            <?php foreach ((array)$messages as $message): ?>
                <div class="alert alert-<?= Html::encode($type) ?> alert-dismissible fade show" role="alert">
                    <?= Html::encode($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?= $content ?>
    </div>
</div>

<script src="/js/bootstrap.bundle.min.js"></script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
