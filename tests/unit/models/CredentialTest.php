<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Credential;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Credential pure-logic helpers — no DB required.
 */
class CredentialTest extends TestCase
{
    // ── typeLabel ─────────────────────────────────────────────────────────────

    public function testTypeLabelSshKey(): void
    {
        $this->assertSame('SSH Key', Credential::typeLabel(Credential::TYPE_SSH_KEY));
    }

    public function testTypeLabelUsernamePassword(): void
    {
        $this->assertSame('Username / Password', Credential::typeLabel(Credential::TYPE_USERNAME_PASSWORD));
    }

    public function testTypeLabelVault(): void
    {
        $this->assertSame('Vault Secret', Credential::typeLabel(Credential::TYPE_VAULT));
    }

    public function testTypeLabelToken(): void
    {
        $this->assertSame('Token', Credential::typeLabel(Credential::TYPE_TOKEN));
    }

    public function testTypeLabelUnknownReturnsRaw(): void
    {
        $this->assertSame('custom_type', Credential::typeLabel('custom_type'));
    }

    // ── sensitiveFields ───────────────────────────────────────────────────────
    //
    // The exact list is used by CredentialService::redact() to strip secrets
    // from audit/log output — drift here is a real leak risk, so pin the
    // full set rather than only the names we happen to remember.

    public function testSensitiveFieldsHasTheExactKnownSet(): void
    {
        $this->assertSame(
            ['secret_data', 'password', 'private_key', 'token', 'vault_password'],
            Credential::sensitiveFields()
        );
    }

    // ── resolveTokenEnvVarName ────────────────────────────────────────────────

    public function testResolveTokenEnvVarNameUsesConfiguredName(): void
    {
        $c = new Credential();
        $c->env_var_name = 'OP_TOKEN';
        $this->assertSame('OP_TOKEN', $c->resolveTokenEnvVarName());
    }

    public function testResolveTokenEnvVarNameFallsBackWhenBlank(): void
    {
        $c = new Credential();
        $c->env_var_name = '   ';
        $this->assertSame(Credential::DEFAULT_TOKEN_ENV_VAR, $c->resolveTokenEnvVarName());
    }

    public function testResolveTokenEnvVarNameFallsBackWhenNull(): void
    {
        $c = new Credential();
        $this->assertSame(Credential::DEFAULT_TOKEN_ENV_VAR, $c->resolveTokenEnvVarName());
    }
}
