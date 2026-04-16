<?php

declare(strict_types=1);

namespace app\models;

use app\services\ldap\LdapAuthResult;
use app\services\ldap\LdapService;
use app\services\ldap\LdapUserProvisioner;
use yii\base\Model;

class LoginForm extends Model
{
    public string $username = '';
    public string $password = '';
    public bool $rememberMe = true;

    private ?User $_user = null;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            [['rememberMe'], 'boolean'],
            [['password'], 'validatePassword'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'password' => 'Password',
            'rememberMe' => 'Remember me',
        ];
    }

    /**
     * Verify the submitted credentials.
     *
     * Routing rules:
     *  - If a local active user with this username exists and is marked
     *    auth_source=local, use the existing bcrypt path.
     *  - If the local user exists and is marked auth_source=ldap, route
     *    to LDAP — the local password column carries the sentinel and
     *    can never match.
     *  - If no local user exists but LDAP is enabled, try LDAP and
     *    auto-provision on success (when the directory configuration
     *    allows it).
     *
     * The same generic error ("Incorrect username or password.") is used
     * for every failure mode so the form does not leak whether the
     * username exists locally, exists in LDAP, or is rejected by the
     * directory.
     */
    public function validatePassword(string $attribute): void
    {
        if ($this->hasErrors()) {
            return;
        }

        $user = $this->getUser();

        // Local user: direct bcrypt validation. validatePassword() on the
        // model already returns false for LDAP users (defense-in-depth),
        // so the next branch handles LDAP-marked accounts.
        if ($user !== null && $user->isLocal()) {
            if (!$user->validatePassword($this->password)) {
                $this->addError($attribute, 'Incorrect username or password.');
            }
            return;
        }

        // LDAP path — either the local row says auth_source=ldap, OR there
        // is no local row at all and we may auto-provision one.
        if (!$this->validateViaLdap()) {
            $this->addError($attribute, 'Incorrect username or password.');
        }
    }

    /**
     * Try to validate the credentials against the LDAP service and
     * provision/update a local user record on success. Returns false on any
     * failure mode — the caller is responsible for adding the generic error.
     */
    private function validateViaLdap(): bool
    {
        $ldap = $this->ldapService();
        if ($ldap === null || !$ldap->isEnabled()) {
            return false;
        }
        $result = $ldap->authenticate($this->username, $this->password);
        if ($result === null) {
            $this->logLdapFailure($ldap->getLastError());
            return false;
        }
        $provisioner = $this->ldapProvisioner();
        if ($provisioner === null) {
            return false;
        }
        $persisted = $provisioner->provisionOrUpdate($result, $ldap->getConfig());
        if ($persisted === null) {
            // Directory accepts them but no local user exists and
            // auto-provisioning is off — refuse with the generic message.
            $this->logLdapFailure('Auto-provisioning disabled and no pre-created local user.');
            return false;
        }

        $this->_user = $persisted;
        return true;
    }

    /**
     * Check if credentials are valid without actually logging in.
     */
    public function validateCredentials(): bool
    {
        return $this->validate();
    }

    /**
     * Whether the validated user has TOTP 2FA enabled.
     */
    public function requiresTotp(): bool
    {
        $user = $this->getUser();
        return $user !== null && $user->totp_enabled;
    }

    public function login(): bool
    {
        if ($this->validate()) {
            /** @var User $user Validated above — user exists */
            $user = $this->getUser();
            $duration = $this->rememberMe ? 3600 * 24 * 30 : 0;
            /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
            $userComponent = \Yii::$app->user;
            return $userComponent->login($user, $duration);
        }
        return false;
    }

    public function getUserModel(): ?User
    {
        return $this->getUser();
    }

    /**
     * Lookup the local User row for the submitted username.
     *
     * Includes inactive users when LDAP is enabled — a disabled LDAP
     * account must still resolve so the LDAP path can re-enable it
     * after a successful bind. For pure-local installs the active-only
     * filter from {@see User::findByUsername()} is preserved.
     */
    protected function getUser(): ?User
    {
        if ($this->_user !== null) {
            return $this->_user;
        }
        $ldap = $this->ldapService();
        if ($ldap !== null && $ldap->isEnabled()) {
            /** @var User|null $any */
            $any = User::find()->where(['username' => $this->username])->one();
            $this->_user = $any;
        } else {
            $this->_user = User::findByUsername($this->username);
        }
        return $this->_user;
    }

    private function ldapService(): ?LdapService
    {
        if (!\Yii::$app->has('ldapService')) {
            return null;
        }
        /** @var LdapService $svc */
        $svc = \Yii::$app->get('ldapService');
        return $svc;
    }

    private function ldapProvisioner(): ?LdapUserProvisioner
    {
        if (!\Yii::$app->has('ldapUserProvisioner')) {
            return null;
        }
        /** @var LdapUserProvisioner $svc */
        $svc = \Yii::$app->get('ldapUserProvisioner');
        return $svc;
    }

    /**
     * Audit a failed LDAP login attempt. Stays separate from the generic
     * USER_LOGIN_FAILED so operators can distinguish directory-side
     * failures from local-password failures in their SIEM.
     */
    private function logLdapFailure(?string $reason): void
    {
        if (!\Yii::$app->has('auditService')) {
            return;
        }
        /** @var \app\services\AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_LDAP_LOGIN_FAILED,
            'user',
            null,
            null,
            [
                'username' => $this->username,
                'reason' => $reason ?? 'unknown',
            ],
        );
    }
}
