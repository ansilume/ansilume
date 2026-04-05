<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Inventory $inventory */

if (!$inventory->targetsLocalhost()) {
    return;
}
?>
<div class="alert alert-warning d-flex gap-2 align-items-start" role="alert" data-testid="localhost-warning">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>
        <strong>This inventory targets the runner container itself.</strong>
        Playbooks run here execute against the runner's own filesystem.
        Tasks that install packages, create files, or modify system state
        will mutate the runner image and — because the project directory is
        bind-mounted at <code>/var/www</code> — can leak files into the host
        repository (e.g. installing nginx drops
        <code>/var/www/html/index.nginx-debian.html</code>).
        Safe for read-only smoke tests (ping, gather facts); avoid for
        package installs and filesystem changes.
    </div>
</div>
