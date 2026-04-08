<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */

use app\models\ApprovalRule;
use yii\helpers\Html;

$this->title = $model->name;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('approval-rule.update')) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('approval-rule.delete')) : ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Delete this approval rule?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Approver Type</th>
                <td><?= Html::encode(ApprovalRule::approverTypes()[$model->approver_type] ?? $model->approver_type) ?></td>
            </tr>
            <tr>
                <th>Required Approvals</th>
                <td><?= Html::encode((string)$model->required_approvals) ?></td>
            </tr>
            <tr>
                <th>Timeout</th>
                <td><?= $model->timeout_minutes !== null ? Html::encode($model->timeout_minutes . ' minutes') : 'None' ?></td>
            </tr>
            <tr>
                <th>Timeout Action</th>
                <td><?= Html::encode(ApprovalRule::timeoutActions()[$model->timeout_action] ?? $model->timeout_action) ?></td>
            </tr>
            <?php if ($model->description) : ?>
            <tr>
                <th>Description</th>
                <td><?= Html::encode($model->description) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Approver</th>
                <td><?php
                    $cfg = $model->getParsedConfig();
                switch ($model->approver_type) {
                    case ApprovalRule::APPROVER_TYPE_ROLE:
                        echo 'Role: <strong>' . Html::encode((string)($cfg['role'] ?? '—')) . '</strong>';
                        break;
                    case ApprovalRule::APPROVER_TYPE_TEAM:
                        $teamId = $cfg['team_id'] ?? null;
                        $team = $teamId !== null ? \app\models\Team::findOne((int)$teamId) : null;
                        echo 'Team: <strong>' . ($team !== null ? Html::encode($team->name) : '#' . Html::encode((string)$teamId)) . '</strong>';
                        break;
                    case ApprovalRule::APPROVER_TYPE_USERS:
                        $userIds = $cfg['user_ids'] ?? [];
                        if (is_array($userIds) && $userIds !== []) {
                            $names = \app\models\User::find()
                                ->where(['id' => $userIds])
                                ->select('username')
                                ->column();
                            echo 'Users: <strong>' . Html::encode(implode(', ', $names)) . '</strong>';
                        } else {
                            echo 'Users: <em class="text-muted">none configured</em>';
                        }
                        break;
                    default:
                        echo Html::encode((string)$model->approver_config);
                }
                ?></td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?> by <?= Html::encode($model->creator?->username ?? '—') ?></td>
            </tr>
        </table>
    </div>
</div>
