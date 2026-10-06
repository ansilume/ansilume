<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\components\CredentialUsage $usage */

use app\models\Credential;
use yii\helpers\Html;

?>
<div class="card mt-3" id="credential-usage">
    <div class="card-header">Used by</div>
    <div class="card-body">
        <?php if (!$usage->isInUse()) : ?>
            <p class="text-muted mb-0">Not used by any job template, project or waiting job.</p>
        <?php else : ?>
            <?php if ($usage->jobTemplateTotal > 0) : ?>
                <h6 class="mb-2">Job templates</h6>
                <ul class="list-unstyled mb-3" id="credential-usage-templates">
                    <?php foreach ($usage->jobTemplates as $template) : ?>
                        <li>
                            <?= Html::a(Html::encode($template['name']), ['/job-template/view', 'id' => $template['id']]) ?>
                            <span class="badge <?= Html::encode($template['role'] === Credential::ROLE_PRIMARY ? 'text-bg-primary' : 'text-bg-secondary') ?> ms-1"><?= Html::encode($template['role']) ?></span>
                            <?php if ($template['project_name'] !== null) : ?>
                                <span class="text-muted small ms-1"><?= Html::encode($template['project_name']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($usage->hiddenJobTemplateCount() > 0) : ?>
                        <li class="text-muted small"><?= Html::encode('+ ' . $usage->hiddenJobTemplateCount() . ' job template(s) in projects you cannot see') ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($usage->projectTotal > 0) : ?>
                <h6 class="mb-2">Projects (SCM credential)</h6>
                <ul class="list-unstyled mb-3" id="credential-usage-projects">
                    <?php foreach ($usage->projects as $project) : ?>
                        <li><?= Html::a(Html::encode($project['name']), ['/project/view', 'id' => $project['id']]) ?></li>
                    <?php endforeach; ?>
                    <?php if ($usage->hiddenProjectCount() > 0) : ?>
                        <li class="text-muted small"><?= Html::encode('+ ' . $usage->hiddenProjectCount() . ' project(s) you cannot see') ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($usage->pendingJobCount > 0) : ?>
                <p class="mb-0" id="credential-usage-pending"><?= Html::encode($usage->pendingJobCount . ' job(s) waiting to start will use this credential.') ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
