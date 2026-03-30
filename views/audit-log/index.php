<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $filterAction */
/** @var string|null $filterUser */
/** @var string|null $filterObject */
/** @var app\models\User[] $users */

use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Audit Log';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Audit Log</h2>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="action" class="form-control form-control-sm"
               placeholder="Filter by action (e.g. job.launched)"
               value="<?= Html::encode($filterAction ?? '') ?>">
    </div>
    <div class="col-md-3">
        <select name="user_id" class="form-select form-select-sm">
            <option value="">— Any user —</option>
            <?php foreach ($users as $u) : ?>
                <option value="<?= $u->id ?>" <?= $filterUser == $u->id ? 'selected' : '' ?>><?= Html::encode($u->username) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" name="object_type" class="form-control form-control-sm"
               placeholder="Object type (e.g. job, credential)"
               value="<?= Html::encode($filterObject ?? '') ?>">
    </div>
    <div class="col-md-2">
        <div class="d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
            <?= Html::a('Clear', ['index'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
        </div>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-sm table-hover font-monospace" style="font-size:.85rem">
        <thead class="table-light">
            <tr><th>ID</th><th>When</th><th>User</th><th>Action</th><th>Object</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($dataProvider->getModels() as $entry) : ?>
            <tr>
                <td><?= Html::a($entry->id, ['view', 'id' => $entry->id]) ?></td>
                <td><?= date('Y-m-d H:i:s', $entry->created_at) ?></td>
                <td><?= Html::encode($entry->user->username ?? ($entry->user_id ? "#{$entry->user_id}" : 'system')) ?></td>
                <td><?= Html::encode($entry->action) ?></td>
                <td>
                    <?php if ($entry->object_type) : ?>
                        <span class="text-muted"><?= Html::encode($entry->object_type) ?></span>
                        <?php if ($entry->object_id !== null) :
                            ?>#<?= $entry->object_id ?><?php
                        endif; ?>
                    <?php else : ?>
                        —
                    <?php endif; ?>
                </td>
                <td><?= Html::encode($entry->ip_address ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($dataProvider->getModels())) : ?>
            <tr><td colspan="6" class="text-muted text-center py-3">No entries found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?>
