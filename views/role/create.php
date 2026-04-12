<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RoleForm $form */

use yii\helpers\Html;

$this->title = 'New Role';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><?= Html::a('Roles', ['index']) ?></li>
        <li class="breadcrumb-item active">New</li>
    </ol>
</nav>
<h2><?= Html::encode($this->title) ?></h2>

<?= $this->render('_form', ['form' => $form]) ?>
