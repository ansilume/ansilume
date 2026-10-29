<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */
/** @var app\components\ApprovalRuleUsage $usage */
/** @var bool $canUpdate */
/** @var bool $canDelete */

use app\components\ApprovalRuleUsage;
use app\components\ApproverConfigIds;
use app\helpers\ConfirmHelper;
use app\models\ApprovalRule;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->name;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if ($canUpdate) : ?>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm', 'id' => 'approval-rule-edit']) ?>
        <?php endif; ?>
        <?php if ($canDelete && !$usage->isInUse()) : ?>
            <form action="<?= Html::encode(Url::to(['delete', 'id' => $model->id])) ?>" method="post" style="display:inline" id="approval-rule-delete-form"
                  onsubmit="<?= ConfirmHelper::attribute('Delete approval rule "' . $model->name . '"?') ?>">
                <input type="hidden" name="<?= Html::encode(Yii::$app->request->csrfParam) ?>" value="<?= Html::encode(Yii::$app->request->getCsrfToken()) ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
        <?php elseif ($canDelete) : ?>
            <button type="button" class="btn btn-outline-danger btn-sm ms-1" id="approval-rule-delete-blocked" disabled
                    title="<?= Html::encode(ApprovalRuleUsage::DELETE_BLOCKED) ?>">Delete</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($canDelete && $usage->isInUse()) : ?>
    <p class="text-muted small" id="approval-rule-delete-blocked-reason"><?= Html::encode(ApprovalRuleUsage::DELETE_BLOCKED) ?></p>
<?php endif; ?>

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
                        // The team and the users as the integer rule reads an ID; a value that is none names nobody.
                        $teamId = ApproverConfigIds::teamOf($cfg);
                        if ($teamId === null) {
                            echo 'Team: <em class="text-muted">none configured</em>';
                            break;
                        }
                        // The team's name, or the ID of a team that no longer exists.
                        $team = \app\models\Team::findOne($teamId);
                        echo 'Team: <strong>' . ($team !== null ? Html::encode($team->name) : '#' . Html::encode((string)$teamId)) . '</strong>';
                        break;
                    case ApprovalRule::APPROVER_TYPE_USERS:
                        $userIds = ApproverConfigIds::usersOf($cfg);
                        if ($userIds !== []) {
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

        <?= $this->render('_usage', ['usage' => $usage]) ?>
    </div>
</div>
