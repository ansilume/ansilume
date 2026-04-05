<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\RoleForm $form */

use yii\helpers\Html;

$this->title = 'New Role';
?>

<h2><?= Html::encode($this->title) ?></h2>

<?= $this->render('_form', ['form' => $form]) ?>
