<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\VaultCredentialRule;
use app\models\Credential;
use PHPUnit\Framework\TestCase;

/**
 * The one-vault rule: a job template hands ansible-playbook a single
 * --vault-password-file, so it may hold only one vault credential.
 */
class VaultCredentialRuleTest extends TestCase
{
    /**
     * @return array{id: int, name: string|null, credential_type: string|null}
     */
    private static function entry(int $id, ?string $name, ?string $type): array
    {
        return ['id' => $id, 'name' => $name, 'credential_type' => $type];
    }

    public function testVaultsKeepThePrecedenceOrderAndOnlyVaultEntries(): void
    {
        $ordered = [
            self::entry(1, 'deploy key', Credential::TYPE_SSH_KEY),
            self::entry(9, 'prod vault', Credential::TYPE_VAULT),
            self::entry(2, 'api token', Credential::TYPE_TOKEN),
            self::entry(3, 'old vault', Credential::TYPE_VAULT),
            self::entry(4, 'sudo login', Credential::TYPE_USERNAME_PASSWORD),
            self::entry(5, 'untyped', null),
            self::entry(7, null, Credential::TYPE_VAULT),
        ];

        // assertSame also checks the keys: the result is a list (0, 1, 2),
        // not the positions the vaults had in the input.
        $this->assertSame([
            self::entry(9, 'prod vault', Credential::TYPE_VAULT),
            self::entry(3, 'old vault', Credential::TYPE_VAULT),
            self::entry(7, null, Credential::TYPE_VAULT),
        ], VaultCredentialRule::vaults($ordered));
    }

    public function testASetWithoutVaultHasNoVaults(): void
    {
        $this->assertSame([], VaultCredentialRule::vaults([]));
        $this->assertSame([], VaultCredentialRule::vaults([
            self::entry(1, 'deploy key', Credential::TYPE_SSH_KEY),
            self::entry(2, 'api token', Credential::TYPE_TOKEN),
        ]));
    }

    public function testExcessIsEveryVaultAfterTheFirst(): void
    {
        $ordered = [
            self::entry(1, 'deploy key', Credential::TYPE_SSH_KEY),
            self::entry(6, 'first vault', Credential::TYPE_VAULT),
            self::entry(2, 'api token', Credential::TYPE_TOKEN),
            self::entry(4, 'second vault', Credential::TYPE_VAULT),
            self::entry(5, 'third vault', Credential::TYPE_VAULT),
        ];

        $this->assertSame([
            self::entry(4, 'second vault', Credential::TYPE_VAULT),
            self::entry(5, 'third vault', Credential::TYPE_VAULT),
        ], VaultCredentialRule::excess($ordered));
    }

    public function testASingleVaultOrNoneHasNoExcess(): void
    {
        $this->assertSame([], VaultCredentialRule::excess([
            self::entry(6, 'only vault', Credential::TYPE_VAULT),
            self::entry(1, 'deploy key', Credential::TYPE_SSH_KEY),
        ]));
        $this->assertSame([], VaultCredentialRule::excess([self::entry(1, 'deploy key', Credential::TYPE_SSH_KEY)]));
        $this->assertSame([], VaultCredentialRule::excess([]));
    }

    public function testTheConflictMessageForTwoVaultsSaysBoth(): void
    {
        $this->assertSame(
            'Only one vault password can be attached to a job template. '
            . '"prod vault" and "old vault" are both vault passwords; keep one of them.',
            VaultCredentialRule::conflictMessage([
                self::entry(9, 'prod vault', Credential::TYPE_VAULT),
                self::entry(3, 'old vault', Credential::TYPE_VAULT),
            ])
        );
    }

    public function testTheConflictMessageForThreeOrMoreVaultsSaysAll(): void
    {
        $this->assertSame(
            'Only one vault password can be attached to a job template. '
            . '"prod vault", "old vault" and "Credential #7" are all vault passwords; keep one of them.',
            VaultCredentialRule::conflictMessage([
                self::entry(9, 'prod vault', Credential::TYPE_VAULT),
                self::entry(3, 'old vault', Credential::TYPE_VAULT),
                self::entry(7, null, Credential::TYPE_VAULT),
            ])
        );
        $this->assertSame(
            'Only one vault password can be attached to a job template. '
            . '"a", "b", "c" and "d" are all vault passwords; keep one of them.',
            VaultCredentialRule::conflictMessage([
                self::entry(1, 'a', Credential::TYPE_VAULT),
                self::entry(2, 'b', Credential::TYPE_VAULT),
                self::entry(3, 'c', Credential::TYPE_VAULT),
                self::entry(4, 'd', Credential::TYPE_VAULT),
            ])
        );
    }

    /**
     * @return array<string, array{0: list<array{id: int, name: string|null, credential_type: string|null}>, 1: string}>
     */
    public static function namesProvider(): array
    {
        return [
            'one entry' => [[self::entry(1, 'prod vault', Credential::TYPE_VAULT)], '"prod vault"'],
            'two entries' => [
                [self::entry(1, 'prod vault', Credential::TYPE_VAULT), self::entry(2, 'old vault', Credential::TYPE_VAULT)],
                '"prod vault" and "old vault"',
            ],
            'three entries' => [
                [
                    self::entry(1, 'prod vault', Credential::TYPE_VAULT),
                    self::entry(2, 'old vault', Credential::TYPE_VAULT),
                    self::entry(3, 'test vault', Credential::TYPE_VAULT),
                ],
                '"prod vault", "old vault" and "test vault"',
            ],
            'a null name falls back to the id' => [[self::entry(42, null, Credential::TYPE_VAULT)], '"Credential #42"'],
            'a null name among others' => [
                [
                    self::entry(1, 'prod vault', Credential::TYPE_VAULT),
                    self::entry(42, null, Credential::TYPE_VAULT),
                    self::entry(3, 'test vault', Credential::TYPE_VAULT),
                ],
                '"prod vault", "Credential #42" and "test vault"',
            ],
        ];
    }

    /**
     * @dataProvider namesProvider
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $entries
     */
    public function testNamesAreQuotedAndJoined(array $entries, string $expected): void
    {
        $this->assertSame($expected, VaultCredentialRule::names($entries));
    }
}
