<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CredentialSecretPolicy;
use app\models\Credential;
use PHPUnit\Framework\TestCase;

class CredentialSecretPolicyTest extends TestCase
{
    public function testEveryTypeRequiresItsSecret(): void
    {
        $this->assertSame(['private_key'], CredentialSecretPolicy::requiredKeys(Credential::TYPE_SSH_KEY));
        $this->assertSame(['password'], CredentialSecretPolicy::requiredKeys(Credential::TYPE_USERNAME_PASSWORD));
        $this->assertSame(['vault_password'], CredentialSecretPolicy::requiredKeys(Credential::TYPE_VAULT));
        $this->assertSame(['token'], CredentialSecretPolicy::requiredKeys(Credential::TYPE_TOKEN));
        $this->assertSame([], CredentialSecretPolicy::requiredKeys('unknown'));
    }

    public function testOnlyTheTypesOwnNonBlankKeysCount(): void
    {
        $provided = CredentialSecretPolicy::provided(Credential::TYPE_VAULT, [
            'vault_password' => 's3cret',
            'token' => 'belongs-to-another-type',
            'password' => 'also-ignored',
        ]);

        $this->assertSame(['vault_password' => 's3cret'], $provided);
        $this->assertSame([], CredentialSecretPolicy::provided(Credential::TYPE_TOKEN, ['token' => "  \n "]));
        $this->assertSame([], CredentialSecretPolicy::provided(Credential::TYPE_TOKEN, ['token' => ['not', 'scalar']]));
        $this->assertSame(['token' => ' keep spaces '], CredentialSecretPolicy::provided(Credential::TYPE_TOKEN, ['token' => ' keep spaces ']));
    }

    public function testPrivateKeysGetUnixLineEndings(): void
    {
        $provided = CredentialSecretPolicy::provided(Credential::TYPE_SSH_KEY, ['private_key' => "-----BEGIN\r\nabc\rdef\n-----END\r\n"]);

        $this->assertSame("-----BEGIN\nabc\ndef\n-----END\n", $provided['private_key']);
    }

    public function testMissingSecretsAndMessages(): void
    {
        $this->assertSame(['vault_password'], CredentialSecretPolicy::missing(Credential::TYPE_VAULT, []));
        $this->assertSame([], CredentialSecretPolicy::missing(Credential::TYPE_VAULT, ['vault_password' => 'x']));
        $this->assertSame(
            'Vault password is required for Vault Secret credentials.',
            CredentialSecretPolicy::missingMessage(Credential::TYPE_VAULT, ['vault_password'], false)
        );
        $this->assertSame(
            'Changing the type to SSH Key requires a new private key.',
            CredentialSecretPolicy::missingMessage(Credential::TYPE_SSH_KEY, ['private_key'], true)
        );
        $this->assertSame('Token', CredentialSecretPolicy::label('token'));
        $this->assertSame('custom', CredentialSecretPolicy::label('custom'));
    }
}
