<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\components\InventoryUsage $usage the job templates that use the inventory (InventoryUsageService::forInventory()) */

use app\components\InventoryUsage;
use yii\helpers\Html;

// The job templates the viewer may see, each with its project and its own
// approval rule; then how many are in projects the viewer cannot see, and
// how many deleted job templates still refer to the inventory.
$hidden = $usage->hiddenJobTemplateCount();
?>
<div class="card mt-4" id="inventory-job-templates">
    <div class="card-header">Job templates</div>
    <div class="card-body">
        <?php if ($usage->jobTemplates !== []) : ?>
            <div class="table-responsive">
                <table class="table table-sm mb-2" id="inventory-job-templates-list">
                    <thead>
                        <tr><th>Job template</th><th>Project</th><th>Own approval rule</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usage->jobTemplates as $template) : ?>
                        <?= Html::beginTag('tr', ['data-job-template-id' => $template['id']]) ?>
                            <td><?= Html::a(Html::encode($template['name']), ['/job-template/view', 'id' => $template['id']]) ?></td>
                            <td><?= Html::encode($template['project_name'] ?? '—') ?></td>
                            <td data-template-rule><?= Html::encode($template['approval_rule_name'] ?? '—') ?></td>
                        <?= Html::endTag('tr') ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($usage->jobTemplateTotal === 0) : ?>
            <p class="text-muted mb-0" id="inventory-job-templates-empty"><?= Html::encode(InventoryUsage::CARD_EMPTY) ?></p>
        <?php endif; ?>
        <?php if ($hidden > 0) : ?>
            <p class="text-muted small mb-0" id="inventory-job-templates-hidden"><?= Html::encode(sprintf(InventoryUsage::CARD_HIDDEN, $hidden)) ?></p>
        <?php endif; ?>
        <?php if ($usage->deletedJobTemplateCount > 0) : ?>
            <p class="text-muted small mb-0" id="inventory-job-templates-deleted"><?= Html::encode(sprintf(InventoryUsage::CARD_DELETED, $usage->deletedJobTemplateCount)) ?></p>
        <?php endif; ?>
    </div>
</div>
