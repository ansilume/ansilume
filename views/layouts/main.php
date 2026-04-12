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
        /* Yii2 ActiveForm uses Bootstrap 3 classes — make them visible under Bootstrap 5 */
        .help-block { display: block; font-size: .875em; color: #dc3545; margin-top: .25rem; }
        .has-error .form-control { border-color: #dc3545; }
        .has-error .control-label { color: #dc3545; }
        .has-success .form-control { border-color: #198754; }
        /* Override Bootstrap's table-light which is unreadable in dark mode */
        .table thead.table-light th,
        .table thead.table-light td { background-color: #1e2128; color: #adb5bd; border-color: rgba(255,255,255,.08); }
    </style>
    <link rel="icon" href="/ansilume-logo.svg" type="image/svg+xml">
    <meta name="csrf-token" content="<?= \Yii::$app->request->getCsrfToken() ?>">
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>

<?php
// Helper: is a given route prefix active? Match at segment boundary (/ or end).
$active = fn (string $prefix): string =>
    $route === $prefix || str_starts_with($route, $prefix . '/') ? ' active' : '';
?>

<div id="sidebar">
    <a class="sidebar-brand justify-content-center" href="<?= Url::to(['/']) ?>">
        <img src="/ansilume.svg" alt="Ansilume" style="width:100%; object-fit:contain; filter: brightness(0) invert(1);">
    </a>

    <?php if (!\Yii::$app->user?->isGuest) : ?>
    <nav class="nav flex-column mt-1">
        <a class="nav-link<?= $route === 'site/index' ? ' active' : '' ?>" href="<?= Url::to(['/']) ?>">Dashboard</a>
    </nav>

    <span class="nav-section">Operations</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('job') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/job/index']) ?>">Jobs</a>
        <?php if (\Yii::$app->user?->can('job.launch')) : ?>
        <a class="nav-link<?= $active('schedule') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/schedule/index']) ?>">Schedules</a>
        <?php endif; ?>
        <a class="nav-link<?= $active('analytics') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/analytics/index']) ?>">Analytics</a>
    </nav>

    <span class="nav-section">Automation</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('project') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/project/index']) ?>">Projects</a>
        <a class="nav-link<?= $active('inventory') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/inventory/index']) ?>">Inventories</a>
        <a class="nav-link<?= $active('credential') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/credential/index']) ?>">Credentials</a>
        <a class="nav-link<?= $active('job-template') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/job-template/index']) ?>">Job Templates</a>
        <a class="nav-link<?= $active('notification-template') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/notification-template/index']) ?>">Notifications</a>
    </nav>

    <span class="nav-section">Workflows</span>
    <nav class="nav flex-column">
        <a class="nav-link<?= $active('workflow-template') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/workflow-template/index']) ?>">Workflow Templates</a>
        <a class="nav-link<?= $active('workflow-job') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/workflow-job/index']) ?>">Executions</a>
        <a class="nav-link<?= $active('approval-rule') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/approval-rule/index']) ?>">Approval Rules</a>
        <a class="nav-link<?= $active('approval') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/approval/index']) ?>">Approval Requests</a>
    </nav>

        <?php $identity = \Yii::$app->user?->identity; ?>
        <?php $currentUser = $identity instanceof \app\models\User ? $identity : null; ?>
    <div class="sidebar-footer" title="Ansilume <?= Html::encode(\Yii::$app->params['version']) ?>">
        <?php if (\Yii::$app->user?->can('user.view')) : ?>
            <?php $sa = $currentUser?->is_superadmin
                ? ' <span class="badge text-bg-warning" style="font-size:.55rem;vertical-align:middle">SA</span>'
                : ''; ?>
        <span class="nav-section" style="padding-left:0">Admin<?= $sa // xss-ok: hardcoded HTML badge?></span>
        <nav class="nav flex-column">
            <a class="nav-link<?= $active('user') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/user/index']) ?>">Users</a>
            <a class="nav-link<?= $active('team') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/team/index']) ?>">Teams</a>
            <?php if (\Yii::$app->user?->can('role.view')) : ?>
            <a class="nav-link<?= $active('role') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/role/index']) ?>">Roles</a>
            <?php endif; ?>
            <?php if (\Yii::$app->user?->can('runner-group.view')) : ?>
            <a class="nav-link<?= $active('runner-group') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/runner-group/index']) ?>">Runners</a>
            <?php endif; ?>
            <a class="nav-link<?= $active('webhook') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/webhook/index']) ?>">Webhooks</a>
            <a class="nav-link<?= $active('audit-log') // xss-ok: hardcoded CSS class?>" href="<?= Url::to(['/audit-log/index']) ?>">Audit Log</a>
        </nav>
        <?php endif; ?>
        <nav class="nav flex-column">
            <a class="nav-link" href="<?= Url::to(['/profile/tokens']) ?>">API Tokens</a>
        </nav>
        <div style="border-top: 1px solid rgba(255,255,255,.07); margin: .5rem 0;"></div>
        <nav class="nav flex-column">
            <a class="nav-link" href="<?= Url::to(['/profile/security']) ?>">Security</a>
            <form method="post" action="<?= Url::to(['/site/logout']) ?>" style="display:contents">
                <input type="hidden" name="<?= \Yii::$app->request->csrfParam ?>" value="<?= \Yii::$app->request->getCsrfToken() ?>">
                <button type="submit" class="nav-link" style="background:none;border:none;width:100%;text-align:left;cursor:pointer;">Logout</button>
            </form>
        </nav>
        <div class="mt-2" style="font-size:.7rem;opacity:.5">v<?= Html::encode(\Yii::$app->params['version']) ?></div>
    </div>

    <?php endif; ?>
</div>

<div id="main-wrapper">
    <div id="topbar">
        <button id="sidebar-toggle" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('sidebar').classList.toggle('show')">&#9776;</button>
    </div>

    <div id="page-content">
        <?php foreach (\Yii::$app->session?->getAllFlashes() ?? [] as $type => $messages) : ?>
            <?php foreach ((array)$messages as $message) : ?>
                <div class="alert alert-<?= Html::encode($type) ?> alert-dismissible fade show" role="alert">
                    <?= Html::encode($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?= $content // xss-ok: Yii2 layout content — rendered view output, not user input?>
    </div>
</div>

<script src="/js/bootstrap.bundle.min.js"></script>
<script src="/js/copy-to-clipboard.js"></script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
