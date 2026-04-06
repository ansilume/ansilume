<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */
/** @var array<string, string> $roles  name => name */
/** @var array<int, string> $teams     id => name */
/** @var array<int, string> $users     id => username */

use app\models\ApprovalRule;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Approval Rule' : 'Edit: ' . $model->name;

// Pre-populate selector values from existing approver_config JSON
$config = $model->getParsedConfig();
$selectedRole = (string)($config['role'] ?? '');
$selectedTeam = isset($config['team_id']) ? (int)$config['team_id'] : null;
$selectedUsers = isset($config['user_ids']) && is_array($config['user_ids'])
    ? array_map('intval', $config['user_ids'])
    : [];
?>
<div class="row justify-content-center">
<div class="col-lg-8">

<h2><?= Html::encode($this->title) ?></h2>

<?php $form = ActiveForm::begin(['id' => 'approval-rule-form']); ?>

    <?= $form->field($model, 'name')->textInput(['maxlength' => 128, 'autofocus' => true]) ?>
    <?= $form->field($model, 'description')->textarea(['rows' => 2]) ?>
    <?= $form->field($model, 'approver_type')->dropDownList(ApprovalRule::approverTypes(), [
        'id' => 'approver-type',
    ]) ?>

    <?php // Hidden field carries the final JSON value?>
    <?= $form->field($model, 'approver_config')->hiddenInput(['id' => 'approver-config'])->label(false) ?>

    <div id="config-role" class="mb-3" style="display:none">
        <label class="form-label">Role</label>
        <?= Html::dropDownList('_approver_role', $selectedRole, $roles, [
            'id' => 'approver-role',
            'class' => 'form-select',
            'prompt' => '— Select role —',
        ]) ?>
        <div class="form-text">All users with this role can approve.</div>
    </div>

    <div id="config-team" class="mb-3" style="display:none">
        <label class="form-label">Team</label>
        <?= Html::dropDownList('_approver_team', $selectedTeam, $teams, [
            'id' => 'approver-team',
            'class' => 'form-select',
            'prompt' => '— Select team —',
        ]) ?>
        <div class="form-text">All members of this team can approve.</div>
    </div>

    <div id="config-users" class="mb-3" style="display:none">
        <label class="form-label">Users</label>
        <?= Html::listBox('_approver_users', $selectedUsers, $users, [
            'id' => 'approver-users',
            'class' => 'form-select',
            'multiple' => true,
            'size' => min(8, max(4, count($users))),
        ]) ?>
        <div class="form-text">Hold Ctrl / Cmd to select multiple users.</div>
    </div>

    <?= $form->field($model, 'required_approvals')->textInput(['type' => 'number', 'min' => 1, 'max' => 50]) ?>
    <?= $form->field($model, 'timeout_minutes')->textInput(['type' => 'number', 'min' => 1, 'max' => 10080])->hint('Leave empty for no timeout.') ?>
    <?= $form->field($model, 'timeout_action')->dropDownList(ApprovalRule::timeoutActions()) ?>

    <div class="mt-4">
        <?= Html::submitButton($model->isNewRecord ? 'Create' : 'Save Changes', ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Cancel', $model->isNewRecord ? ['index'] : ['view', 'id' => $model->id], ['class' => 'btn btn-outline-secondary ms-2']) ?>
    </div>

<?php ActiveForm::end(); ?>

</div>
</div>

<script>
(function () {
    var typeSelect = document.getElementById('approver-type');
    var panels = {
        role:  document.getElementById('config-role'),
        team:  document.getElementById('config-team'),
        users: document.getElementById('config-users')
    };
    var roleSelect  = document.getElementById('approver-role');
    var teamSelect  = document.getElementById('approver-team');
    var usersSelect = document.getElementById('approver-users');
    var configInput = document.getElementById('approver-config');

    function showPanel() {
        var type = typeSelect.value;
        panels.role.style.display  = type === 'role'  ? '' : 'none';
        panels.team.style.display  = type === 'team'  ? '' : 'none';
        panels.users.style.display = type === 'users' ? '' : 'none';
    }

    function syncConfig() {
        var type = typeSelect.value;
        var json = '{}';
        if (type === 'role' && roleSelect.value) {
            json = JSON.stringify({role: roleSelect.value});
        } else if (type === 'team' && teamSelect.value) {
            json = JSON.stringify({team_id: parseInt(teamSelect.value, 10)});
        } else if (type === 'users') {
            var ids = [];
            for (var i = 0; i < usersSelect.options.length; i++) {
                if (usersSelect.options[i].selected) {
                    ids.push(parseInt(usersSelect.options[i].value, 10));
                }
            }
            json = JSON.stringify({user_ids: ids});
        }
        configInput.value = json;
    }

    typeSelect.addEventListener('change', function () { showPanel(); syncConfig(); });
    roleSelect.addEventListener('change', syncConfig);
    teamSelect.addEventListener('change', syncConfig);
    usersSelect.addEventListener('change', syncConfig);

    // Initial state
    showPanel();
    syncConfig();

    // Sync before submit
    document.getElementById('approval-rule-form').addEventListener('submit', syncConfig);
})();
</script>
