<?php

declare(strict_types=1);

namespace app\models;

use app\services\TotpService;
use yii\base\Model;

/**
 * Form model for the TOTP activation step: user enters the 6-digit code
 * from their authenticator app to confirm setup.
 */
class TotpSetupForm extends Model
{
    public string $code = '';

    private string $_secret;
    private User $_user;

    public function __construct(User $user, string $secret, array $config = [])
    {
        $this->_user = $user;
        $this->_secret = $secret;
        parent::__construct($config);
    }

    public function rules(): array
    {
        return [
            [['code'], 'required'],
            [['code'], 'string', 'length' => 6],
            [['code'], 'match', 'pattern' => '/^\d{6}$/'],
            [['code'], 'validateTotpCode'],
        ];
    }

    public function attributeLabels(): array
    {
        return ['code' => 'Verification Code'];
    }

    public function validateTotpCode(string $attribute): void
    {
        if ($this->hasErrors()) {
            return;
        }

        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');
        if (!$totp->verifyCode($this->_secret, $this->code)) {
            $this->addError($attribute, 'Invalid verification code. Make sure the code from your authenticator app is current.');
        }
    }

    /**
     * Activate TOTP for the user.
     *
     * @return string[] Raw recovery codes to display once.
     */
    public function enable(): array
    {
        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');
        return $totp->enable($this->_user, $this->_secret);
    }

    public function getUser(): User
    {
        return $this->_user;
    }

    public function getSecret(): string
    {
        return $this->_secret;
    }
}
