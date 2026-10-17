<?php

declare(strict_types=1);

/**
 * The inventory a job was launched with, as JobInventory names it from the
 * job's runner payload: its name at launch, else its current name, else
 * JobInventory::UNNAMED with its ID. Linked to the inventory's page while
 * its row exists; else marked JobInventory::DELETED, muted and without a
 * link, as the job page marks a deleted job template; JobInventory::NONE for
 * a job without one, such as the placeholder of an approval step. The
 * Inventory row of the job page (views/job/view.php) and of the request page
 * (views/approval/view.php).
 *
 * @var yii\web\View $this
 * @var app\components\JobInventory $inventory the inventory the job was launched with
 */

use app\components\JobInventory;
use yii\helpers\Html;

if ($inventory->id === null) {
    echo Html::encode(JobInventory::NONE);
} elseif ($inventory->exists) {
    echo Html::a(Html::encode($inventory->label()), ['/inventory/view', 'id' => $inventory->id], ['data-inventory-id' => $inventory->id]);
} else {
    echo Html::encode($inventory->label()) . ' ' . Html::tag('span', Html::encode(JobInventory::DELETED), ['class' => 'text-muted']);
}
