<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApiToken;
use app\models\AuditLog;
use app\models\TotpDisableForm;
use app\models\TotpSetupForm;
use app\models\User;
use app\services\TotpService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Lets the logged-in user manage their API tokens and security settings (TOTP 2FA).
 */
class ProfileController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => [
                'tokens', 'create-token', 'delete-token',
                'security', 'setup-totp', 'enable-totp', 'disable-totp',
            ], 'allow' => true, 'roles' => ['@']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'create-token' => ['POST'],
            'delete-token' => ['POST'],
            'enable-totp' => ['POST'],
        ];
    }

    // ── API Tokens ───────────────────────────────────────────────────────────

    public function actionTokens(): string
    {
        $userId = (int)\Yii::$app->user->id;
        $tokens = ApiToken::find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $newToken = $session->getFlash('new_token');
        return $this->render('tokens', ['tokens' => $tokens, 'newToken' => $newToken]);
    }

    public function actionCreateToken(): Response
    {
        $name = trim((string)\Yii::$app->request->post('name', ''));
        if ($name === '') {
            $this->session()->setFlash('danger', 'Token name is required.');
            return $this->redirect(['tokens']);
        }

        ['raw' => $raw, 'token' => $tokenModel] = ApiToken::generate((int)\Yii::$app->user->id, $name);
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_API_TOKEN_CREATED, 'api_token', $tokenModel->id, null, ['name' => $name]);

        $this->session()->setFlash('new_token', $raw);
        $this->session()->setFlash('success', 'Token created. Copy it now — it will not be shown again.');
        return $this->redirect(['tokens']);
    }

    public function actionDeleteToken(int $id): Response
    {
        $token = ApiToken::findOne(['id' => $id, 'user_id' => (int)\Yii::$app->user->id]);
        if ($token === null) {
            throw new NotFoundHttpException('Token not found.');
        }
        $tokenName = $token->name;
        $token->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_API_TOKEN_DELETED, 'api_token', $id, null, ['name' => $tokenName]);
        $this->session()->setFlash('success', "Token \"{$tokenName}\" revoked.");
        return $this->redirect(['tokens']);
    }

    // ── Security / TOTP 2FA ──────────────────────────────────────────────────

    public function actionSecurity(): string
    {
        /** @var User $user */
        $user = \Yii::$app->user->identity;

        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');

        $remainingCodes = $totp->remainingRecoveryCodeCount($user);

        return $this->render('security', [
            'user' => $user,
            'totpEnabled' => (bool)$user->totp_enabled,
            'remainingCodes' => $remainingCodes,
        ]);
    }

    /**
     * Step 1: Generate a TOTP secret and show QR code + confirmation form.
     */
    public function actionSetupTotp(): Response|string
    {
        /** @var User $user */
        $user = \Yii::$app->user->identity;

        if ($user->totp_enabled) {
            $this->session()->setFlash('info', 'Two-factor authentication is already enabled.');
            return $this->redirect(['security']);
        }

        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');

        // Store secret in session so it persists across the setup flow
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $secret = $session->get('totp_setup_secret');
        if (empty($secret)) {
            $secret = $totp->generateSecret();
            $session->set('totp_setup_secret', $secret);
        }

        $provisioningUri = $totp->buildProvisioningUri($secret, $user);
        $qrDataUri = $totp->generateQrDataUri($provisioningUri);

        $model = new TotpSetupForm($user, $secret);

        return $this->render('setup-totp', [
            'model' => $model,
            'secret' => $secret,
            'qrDataUri' => $qrDataUri,
        ]);
    }

    /**
     * Step 2: Verify the TOTP code and activate 2FA.
     */
    public function actionEnableTotp(): Response|string
    {
        /** @var User $user */
        $user = \Yii::$app->user->identity;

        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $secret = $session->get('totp_setup_secret');
        if (empty($secret)) {
            $this->session()->setFlash('danger', 'TOTP setup session expired. Please start again.');
            return $this->redirect(['setup-totp']);
        }

        $model = new TotpSetupForm($user, $secret);
        if ($model->load(\Yii::$app->request->post()) && $model->validate()) {
            $recoveryCodes = $model->enable();

            // Clear the setup session
            $session->remove('totp_setup_secret');

            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_MFA_ENABLED,
                'user',
                $user->id
            );

            // Show recovery codes once
            return $this->render('recovery-codes', [
                'recoveryCodes' => $recoveryCodes,
            ]);
        }

        // Re-render setup form with errors
        /** @var TotpService $totp */
        $totp = \Yii::$app->get('totpService');
        $provisioningUri = $totp->buildProvisioningUri($secret, $user);
        $qrDataUri = $totp->generateQrDataUri($provisioningUri);

        return $this->render('setup-totp', [
            'model' => $model,
            'secret' => $secret,
            'qrDataUri' => $qrDataUri,
        ]);
    }

    /**
     * Disable 2FA — requires a current TOTP code or recovery code.
     */
    public function actionDisableTotp(): Response|string
    {
        /** @var User $user */
        $user = \Yii::$app->user->identity;

        if (!$user->totp_enabled) {
            $this->session()->setFlash('info', 'Two-factor authentication is not enabled.');
            return $this->redirect(['security']);
        }

        $model = new TotpDisableForm($user);
        if ($model->load(\Yii::$app->request->post()) && $model->validate()) {
            $model->disable();

            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_MFA_DISABLED,
                'user',
                $user->id
            );

            $this->session()->setFlash('success', 'Two-factor authentication has been disabled.');
            return $this->redirect(['security']);
        }

        return $this->render('disable-totp', ['model' => $model]);
    }
}
