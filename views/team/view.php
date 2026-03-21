<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Team $team */
/** @var app\models\User[] $allUsers      Users not yet in the team */
/** @var app\models\Project[] $allProjects Projects not yet in the team */

use app\models\TeamProject;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = Html::encode($team->name);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= Html::encode($team->name) ?></h2>
    <div>
        <?= Html::a('Edit', ['update', 'id' => $team->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::a('Delete', ['delete', 'id' => $team->id], [
            'class' => 'btn btn-outline-danger ms-1',
            'data-method' => 'post',
            'data-confirm' => 'Delete team "' . Html::encode($team->name) . '"? All member and project assignments will be removed.',
        ]) ?>
        <?= Html::a('Back', ['index'], ['class' => 'btn btn-outline-secondary ms-1']) ?>
    </div>
</div>

<?php if ($team->description): ?>
    <p class="text-muted mb-4"><?= Html::encode($team->description) ?></p>
<?php endif; ?>

<div class="row g-4">
    <!-- Members -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Members (<?= count($team->teamMembers) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($team->teamMembers)): ?>
                    <p class="text-muted p-3 mb-0">No members yet.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($team->teamMembers as $tm): ?>
                            <tr>
                                <td><?= Html::encode($tm->user->username ?? '—') ?></td>
                                <td class="text-end">
                                    <?= Html::a('Remove', ['remove-member', 'id' => $team->id, 'userId' => $tm->user_id], [
                                        'class' => 'btn btn-sm btn-outline-danger',
                                        'data-method' => 'post',
                                        'data-confirm' => 'Remove this member?',
                                    ]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php if (!empty($allUsers)): ?>
            <div class="card-footer">
                <?php $form = ActiveForm::begin(['action' => ['add-member', 'id' => $team->id], 'method' => 'post', 'id' => 'add-member-form']); ?>
                <div class="d-flex gap-2">
                    <select name="user_id" class="form-select form-select-sm" required>
                        <option value="">— Add member —</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u->id ?>"><?= Html::encode($u->username) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= Html::submitButton('Add', ['class' => 'btn btn-sm btn-primary']) ?>
                </div>
                <?php ActiveForm::end(); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Projects -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Project Access (<?= count($team->teamProjects) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($team->teamProjects)): ?>
                    <p class="text-muted p-3 mb-0">No project access assigned yet.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($team->teamProjects as $tp): ?>
                            <tr>
                                <td><?= $tp->project ? Html::a(Html::encode($tp->project->name), ['/project/view', 'id' => $tp->project_id]) : "#{$tp->project_id}" ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $tp->role === TeamProject::ROLE_OPERATOR ? 'primary' : 'secondary' ?>">
                                        <?= Html::encode($tp->role) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?= Html::a('Remove', ['remove-project', 'id' => $team->id, 'projectId' => $tp->project_id], [
                                        'class' => 'btn btn-sm btn-outline-danger',
                                        'data-method' => 'post',
                                        'data-confirm' => 'Remove project access?',
                                    ]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php if (!empty($allProjects)): ?>
            <div class="card-footer">
                <?php $form = ActiveForm::begin(['action' => ['add-project', 'id' => $team->id], 'method' => 'post', 'id' => 'add-project-form']); ?>
                <div class="d-flex gap-2">
                    <select name="project_id" class="form-select form-select-sm" required>
                        <option value="">— Add project —</option>
                        <?php foreach ($allProjects as $p): ?>
                            <option value="<?= $p->id ?>"><?= Html::encode($p->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="role" class="form-select form-select-sm">
                        <option value="viewer">Viewer</option>
                        <option value="operator">Operator</option>
                    </select>
                    <?= Html::submitButton('Add', ['class' => 'btn btn-sm btn-primary']) ?>
                </div>
                <?php ActiveForm::end(); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
