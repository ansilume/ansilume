<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\User $user */
/** @var bool $totpEnabled */
/** @var int $remainingCodes */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Security';
?>
<div class="row justify-content-center">
<div class="col-lg-8">
    <h2>Security</h2>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h5 class="card-title mb-1">Two-Factor Authentication</h5>
                    <p class="text-muted mb-2">
                        Add an extra layer of security to your account using a time-based one-time password (TOTP) app
                        like Google Authenticator, Authy, or 1Password.
                    </p>
                </div>
                <?php if ($totpEnabled) : ?>
                    <span class="badge text-bg-success">Enabled</span>
                <?php else : ?>
                    <span class="badge text-bg-secondary">Disabled</span>
                <?php endif; ?>
            </div>

            <?php if ($totpEnabled) : ?>
                <div class="alert alert-success mb-3" role="alert">
                    Two-factor authentication is <strong>active</strong> on your account.
                    You have <strong><?= Html::encode((string)$remainingCodes) ?></strong> recovery codes remaining.
                </div>

                <?php if ($remainingCodes <= 2) : ?>
                    <div class="alert alert-warning mb-3" role="alert">
                        You are running low on recovery codes. Consider disabling and re-enabling 2FA to generate new ones.
                    </div>
                <?php endif; ?>

                <?= Html::a('Disable Two-Factor Authentication', ['disable-totp'], [
                    'class' => 'btn btn-outline-danger',
                ]) ?>
            <?php else : ?>
                <?= Html::a('Enable Two-Factor Authentication', ['setup-totp'], [
                    'class' => 'btn btn-primary',
                ]) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
