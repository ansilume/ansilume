<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RoleForm $form */
/** @var array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $role */

use yii\helpers\Html;

$this->title = 'Edit Role: ' . $role['name'];
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Roles', ['index']) ?></li>
        <li class="breadcrumb-item"><?= Html::a(Html::encode($role['name']), ['view', 'name' => $role['name']]) ?></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?= $this->render('_form', ['form' => $form]) ?>
