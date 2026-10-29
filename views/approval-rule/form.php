<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\ApprovalRule $model */
/** @var array<string, string> $roles  name => name */
/** @var array<int, string> $teams     id => name */
/** @var array<int, string> $users     id => username */
/** @var array{roles: array<string, int>, teams: array<string, int>, users: list<int>} $approverCounts of each role and team the approvers who may decide, and the users who may (ApproverCounts::forRuleForm()) */

use app\components\ApproverConfigIds;
use app\models\ApprovalRule;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\ActiveForm;

$this->title = $model->isNewRecord ? 'New Approval Rule' : 'Edit: ' . $model->name;

// Pre-populate selector values from existing approver_config JSON; an ID as the integer rule reads it.
$config = $model->getParsedConfig();
$selectedRole = (string)($config['role'] ?? '');
$selectedTeam = ApproverConfigIds::teamOf($config);
$selectedUsers = ApproverConfigIds::usersOf($config);

// Each user's option says whether the user may decide: the script counts only those.
$eligibleUsers = array_fill_keys($approverCounts['users'], true);
$userOptions = [];
foreach (array_keys($users) as $userId) {
    $userOptions[$userId] = ['data-eligible' => isset($eligibleUsers[$userId]) ? '1' : '0'];
}
?>
<div class="row justify-content-center">
<div class="col-lg-8">
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Approval Rules', ['index']) ?></li>
        <?php if (!$model->isNewRecord) : ?>
            <li class="breadcrumb-item"><?= Html::a(Html::encode($model->name), ['view', 'id' => $model->id]) ?></li>
        <?php endif; ?>
        <li class="breadcrumb-item active"><?= $model->isNewRecord ? 'New' : 'Edit' ?></li>
    </ol>
</nav>
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
        <?php // A role rule counts the users assigned the role directly (ApprovalRule::resolveRoleIds()).?>
        <div class="form-text" id="approver-role-hint"><?= Html::encode(ApprovalRule::ROLE_HINT) ?></div>
    </div>

    <div id="config-team" class="mb-3" style="display:none">
        <label class="form-label">Team</label>
        <?= Html::dropDownList('_approver_team', $selectedTeam, $teams, [
            'id' => 'approver-team',
            'class' => 'form-select',
            'prompt' => '— Select team —',
        ]) ?>
        <?php // A team rule counts every member of the team (ApprovalRule::resolveTeamIds()).?>
        <div class="form-text" id="approver-team-hint"><?= Html::encode(ApprovalRule::TEAM_HINT) ?></div>
    </div>

    <div id="config-users" class="mb-3" style="display:none">
        <label class="form-label">Users</label>
        <?= Html::listBox('_approver_users', $selectedUsers, $users, [
            'id' => 'approver-users',
            'class' => 'form-select',
            'multiple' => true,
            'size' => min(8, max(4, count($users))),
            'options' => $userOptions,
        ]) ?>
        <div class="form-text">Hold Ctrl / Cmd to select multiple users.</div>
    </div>

    <?= $form->field($model, 'required_approvals')->textInput(['type' => 'number', 'min' => 1, 'max' => 50, 'id' => 'required-approvals']) ?>
    <div id="approver-count-warning" class="alert alert-warning" style="display:none"></div>
    <div id="approver-launcher-note" class="alert alert-info" style="display:none"><?= Html::encode(ApprovalRule::LAUNCHER_NOTE) ?></div>
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
        role: document.getElementById('config-role'),
        team: document.getElementById('config-team'),
        users: document.getElementById('config-users')
    };
    var roleSelect = document.getElementById('approver-role');
    var teamSelect = document.getElementById('approver-team');
    var usersSelect = document.getElementById('approver-users');
    var configInput = document.getElementById('approver-config');
    var requiredInput = document.getElementById('required-approvals');
    var warningDiv = document.getElementById('approver-count-warning');
    var launcherNote = document.getElementById('approver-launcher-note');

    // Of the chosen role, team or users, the approvers who may decide: an
    // active account, and a superadmin or holding approval.decide
    // (ApproverCounts). Who launches and what the rule gates are unknown
    // here, so a count is the most that a launch can find.
    var roleCounts = <?= Json::htmlEncode($approverCounts['roles']) ?>;
    var teamCounts = <?= Json::htmlEncode($approverCounts['teams']) ?>;
    var noApprovers = <?= Json::htmlEncode(ApprovalRule::NO_ELIGIBLE_APPROVERS) ?>;

    function checkApproverCount() {
        var type = typeSelect.value;
        var chosen = false;
        var count = 0;
        if (type === 'role') {
            chosen = roleSelect.value !== '';
            count = roleSelect.value ? (roleCounts[roleSelect.value] || 0) : 0;
        } else if (type === 'team') {
            chosen = teamSelect.value !== '';
            count = teamSelect.value ? (teamCounts[teamSelect.value] || 0) : 0;
        } else if (type === 'users') {
            for (var i = 0; i < usersSelect.options.length; i++) {
                if (usersSelect.options[i].selected) {
                    chosen = true;
                    if (usersSelect.options[i].getAttribute('data-eligible') === '1') count++;
                }
            }
        }
        var required = parseInt(requiredInput.value, 10) || 1;
        if (chosen && count === 0) {
            // Nobody chosen may decide: every launch the rule gates would be refused.
            warningDiv.textContent = noApprovers;
            warningDiv.style.display = '';
        } else if (count > 0 && required > count) {
            warningDiv.textContent = 'Warning: Required approvals (' + required + ') exceeds eligible approvers (' + count + ').';
            warningDiv.style.display = '';
        } else {
            warningDiv.style.display = 'none';
        }
        // With as many required approvals as approvers, a launch by one of
        // them is refused: the launcher never approves their own job.
        launcherNote.style.display = count > 0 && required >= count ? '' : 'none';
    }

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

    typeSelect.addEventListener('change', function () { showPanel(); syncConfig(); checkApproverCount(); });
    roleSelect.addEventListener('change', function () { syncConfig(); checkApproverCount(); });
    teamSelect.addEventListener('change', function () { syncConfig(); checkApproverCount(); });
    usersSelect.addEventListener('change', function () { syncConfig(); checkApproverCount(); });
    requiredInput.addEventListener('input', checkApproverCount);

    // Initial state
    showPanel();
    syncConfig();
    checkApproverCount();

    // Sync before submit
    document.getElementById('approval-rule-form').addEventListener('submit', syncConfig);
})();
</script>
