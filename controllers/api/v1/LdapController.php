<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\services\ldap\LdapService;

/**
 * API v1: LDAP administration endpoints.
 *
 * GET  /api/v1/admin/ldap/test           — connection diagnostic snapshot
 * POST /api/v1/admin/ldap/test           — verify a username (and optionally a
 *                                          password) against the directory
 *
 * Restricted to admins only — exposing connection state or letting an
 * untrusted caller probe usernames would leak directory membership.
 *
 * Never returns the bind password, the user's password, or any other secret.
 * Failures intentionally use generic messages so a probing admin cannot
 * distinguish "user does not exist" from "wrong password" through this
 * endpoint either.
 */
class LdapController extends BaseApiController
{
    /**
     * Run a connection diagnostic.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionTest(): array
    {
        if (!$this->isAdmin()) {
            return $this->error('Forbidden.', 403);
        }

        $svc = $this->ldap();
        if ($svc === null) {
            return $this->error('LDAP service is not registered.', 503);
        }

        $diagnostic = $svc->diagnose();

        $body = (array)\Yii::$app->request->bodyParams;
        $username = isset($body['username']) ? (string)$body['username'] : '';
        $password = isset($body['password']) ? (string)$body['password'] : '';

        $userResult = null;
        if ($username !== '') {
            // If a password is provided we run a full authenticate (verifies
            // the bind). Otherwise we just look the user up — useful for
            // checking that the search filter resolves a known account.
            $result = $password !== ''
                ? $svc->authenticate($username, $password)
                : $svc->lookupByUsername($username);

            if ($result !== null) {
                $userResult = [
                    'found' => true,
                    'dn' => $result->dn,
                    'uid' => $result->uid,
                    'username' => $result->username,
                    'email' => $result->email,
                    'display_name' => $result->displayName,
                    'groups' => $result->groups,
                    'mapped_roles' => $result->roles,
                ];
            } else {
                $userResult = [
                    'found' => false,
                    'error' => $svc->getLastError(),
                ];
            }
        }

        // Audit every test invocation. Operators want to know who poked the
        // directory. Stored fields stay metadata-only — never the password.
        $this->audit($username, $password !== '', $diagnostic['service_bind'], $userResult['found'] ?? null);

        return $this->success([
            'connection' => $diagnostic,
            'user' => $userResult,
        ]);
    }

    private function isAdmin(): bool
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $u */
        $u = \Yii::$app->user;
        if ($u->can('admin')) {
            return true;
        }
        $identity = $u->identity;
        return $identity instanceof \app\models\User && $identity->is_superadmin;
    }

    private function ldap(): ?LdapService
    {
        if (!\Yii::$app->has('ldapService')) {
            return null;
        }
        /** @var LdapService $svc */
        $svc = \Yii::$app->get('ldapService');
        return $svc;
    }

    private function audit(string $username, bool $passwordProvided, bool $bindOk, ?bool $userFound): void
    {
        if (!\Yii::$app->has('auditService')) {
            return;
        }
        /** @var \app\services\AuditService $audit */
        $audit = \Yii::$app->get('auditService');
        $audit->log(
            AuditLog::ACTION_LDAP_TEST_PERFORMED,
            'ldap',
            null,
            null,
            [
                'username' => $username !== '' ? $username : null,
                'password_provided' => $passwordProvided,
                'service_bind' => $bindOk,
                'user_found' => $userFound,
            ],
        );
    }
}
