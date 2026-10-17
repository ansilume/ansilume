<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\components\ApprovalRuleUsage $usage */

use app\components\ApprovalRuleUsage;
use yii\helpers\Html;

?>
<div class="card mt-3" id="approval-rule-usage">
    <div class="card-header">Used by</div>
    <div class="card-body">
        <?php if (!$usage->isInUse()) : ?>
            <p class="text-muted mb-0"><?= Html::encode(ApprovalRuleUsage::NOT_IN_USE) ?></p>
        <?php else : ?>
            <?php if ($usage->inventoryTotal > 0) : ?>
                <?php // Every launch of a job template that uses one of these inventories waits for the rule.?>
                <h6 class="mb-2">Inventories</h6>
                <ul class="list-unstyled mb-3" id="approval-rule-usage-inventories">
                    <?php foreach ($usage->inventories as $inventory) : ?>
                        <li>
                            <?= Html::a(Html::encode($inventory['name']), ['/inventory/view', 'id' => $inventory['id']]) ?>
                            <?php if ($inventory['project_name'] !== null) : ?>
                                <span class="text-muted small ms-1"><?= Html::encode($inventory['project_name']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($usage->hiddenInventoryCount() > 0) : ?>
                        <li class="text-muted small"><?= Html::encode(sprintf(ApprovalRuleUsage::HIDDEN_INVENTORIES, $usage->hiddenInventoryCount())) ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($usage->jobTemplateTotal > 0) : ?>
                <h6 class="mb-2">Job templates</h6>
                <ul class="list-unstyled mb-3" id="approval-rule-usage-templates">
                    <?php foreach ($usage->jobTemplates as $template) : ?>
                        <li>
                            <?= Html::a(Html::encode($template['name']), ['/job-template/view', 'id' => $template['id']]) ?>
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
            <?php if ($usage->workflowStepTotal > 0) : ?>
                <h6 class="mb-2">Workflow steps</h6>
                <ul class="list-unstyled mb-3" id="approval-rule-usage-steps">
                    <?php foreach ($usage->workflowSteps as $step) : ?>
                        <li>
                            <?= Html::a(Html::encode($step['workflow_template_name']), ['/workflow-template/view', 'id' => $step['workflow_template_id']]) ?>
                            <span class="text-muted small ms-1"><?= Html::encode('step ' . $step['step_name']) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($usage->hiddenWorkflowStepCount() > 0) : ?>
                        <li class="text-muted small"><?= Html::encode('+ ' . $usage->hiddenWorkflowStepCount() . ' workflow step(s) in workflows you cannot see') ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($usage->pendingRequestCount > 0) : ?>
                <p class="mb-0" id="approval-rule-usage-pending"><?= Html::encode($usage->pendingRequestCount . ' approval request(s) are waiting for a decision under this rule.') ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
