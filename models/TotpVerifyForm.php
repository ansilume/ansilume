<?php

declare(strict_types=1);

namespace app\models;

use app\services\TotpService;
use yii\base\Model;

/**
 * Form model for the TOTP login verification step.
 * Accepts either a 6-digit TOTP code or a recovery code (XXXX-XXXX format).
 */
class TotpVerifyForm extends Model
{
    public string $code = '';

    private ?User $_user;
    private bool $_usedRecoveryCode = false;

    public function __construct(?User $user, array $config = [])
    {
        $this->_user = $user;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['code'], 'required'],
            [['code'], 'string', 'max' => 12],
            [['code'], 'validateCode'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['code' => 'Authentication Code'];
    }

    public function validateCode(string $attribute): void
    {
        if ($this->hasErrors() || $this->_user === null) {
            return;
        }

        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');

        if ($totp->rateLimiter->isLockedOut($this->_user->id)) {
            $this->addError($attribute, 'Too many failed attempts. Please wait a few minutes before trying again.');
            return;
        }

        $secret = $totp->getUserSecret($this->_user);
        if ($secret === null) {
            $this->addError($attribute, 'Two-factor authentication is not configured for this account.');
            return;
        }

        $code = trim($this->code);

        // Try TOTP code first (6 digits)
        if (preg_match('/^\d{6}$/', $code) && $totp->verifyCode($secret, $code)) {
            $totp->rateLimiter->clearRateLimit($this->_user->id);
            return;
        }

        // Try recovery code (XXXX-XXXX or XXXXXXXX)
        if ($totp->useRecoveryCode($this->_user, $code)) {
            $this->_usedRecoveryCode = true;
            $totp->rateLimiter->clearRateLimit($this->_user->id);
            return;
        }

        $remaining = $totp->rateLimiter->recordFailedAttempt($this->_user->id);
        if ($remaining > 0) {
            $this->addError($attribute, "Invalid code. {$remaining} attempts remaining.");
        } else {
            $this->addError($attribute, 'Too many failed attempts. Please wait a few minutes before trying again.');
        }
    }

    public function usedRecoveryCode(): bool
    {
        return $this->_usedRecoveryCode;
    }

    public function getUser(): ?User
    {
        return $this->_user;
    }
}
