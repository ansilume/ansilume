<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;

/**
 * Form model for requesting a password reset email.
 */
class PasswordResetRequestForm extends Model
{
    public string $email = '';

    public function rules(): array
    {
        return [
            [['email'], 'required'],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],
        ];
    }

    /**
     * Send the password reset email if the user exists and is active.
     *
     * Always returns true to prevent email enumeration — the caller shows
     * a generic success message regardless of whether the email was found.
     */
    public function sendResetEmail(): bool
    {
        /** @var User|null $user */
        $user = User::findOne(['email' => $this->email, 'status' => User::STATUS_ACTIVE]);
        if ($user === null) {
            return true;
        }
        // LDAP-managed accounts cannot reset their password through Ansilume —
        // the directory owns the credential. We silently skip rather than
        // surface "this user is LDAP" so the request stays enumeration-safe.
        if ($user->isLdap()) {
            return true;
        }

        // Re-use existing token if still valid (prevents token flooding)
        if (!$user->isPasswordResetTokenValid()) {
            $user->generatePasswordResetToken();
            if (!$user->save(false)) {
                return false;
            }
        }

        $expireMinutes = (int)ceil(User::PASSWORD_RESET_TOKEN_EXPIRE / 60);
        $resetUrl = $this->buildResetUrl($user->password_reset_token);

        try {
            /** @var \yii\mail\MailerInterface $mailer */
            $mailer = \Yii::$app->mailer;
            $sent = $mailer->compose(
                ['html' => 'password-reset-html', 'text' => 'password-reset-text'],
                [
                    'user' => $user,
                    'resetUrl' => $resetUrl,
                    'expireMinutes' => $expireMinutes,
                ]
            )
                ->setFrom([\Yii::$app->params['senderEmail'] => \Yii::$app->params['senderName']])
                ->setTo($user->email)
                ->setSubject('[Ansilume] Password Reset')
                ->send();

            if (!$sent) {
                \Yii::error("PasswordResetRequestForm: mailer returned false for {$user->email}", __CLASS__);
            }
        } catch (\Throwable $e) {
            \Yii::error("PasswordResetRequestForm: failed to send reset email: {$e->getMessage()}", __CLASS__);
        }

        return true;
    }

    private function buildResetUrl(?string $token): string
    {
        $baseUrl = '';
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            $baseUrl = \Yii::$app->request->hostInfo;
        } elseif (!empty(\Yii::$app->params['appBaseUrl'])) {
            $baseUrl = rtrim(\Yii::$app->params['appBaseUrl'], '/');
        }
        return $baseUrl . \Yii::$app->urlManager->createUrl(['/site/reset-password', 'token' => $token]);
    }
}
