<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RoleForm $form */
/** @var array{name: string, description: string, isSystem: bool, directPermissions: string[], effectivePermissions: string[], userIds: int[]} $role */

use yii\helpers\Html;

$this->title = 'Edit Role: ' . $role['name'];
?>

<h2><?= Html::encode($this->title) ?></h2>

<?= $this->render('_form', ['form' => $form]) ?>
