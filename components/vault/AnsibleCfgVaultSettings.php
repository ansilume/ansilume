<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * The vault settings of a project's ansible.cfg ([defaults] section), as
 * Ansible applies them when a runner uses the repository's settings
 * ('Ansilume and repository' mode).
 */
final class AnsibleCfgVaultSettings
{
    /**
     * @param string|null $passwordFile vault_password_file as written, null when unset or empty
     * @param string|null $identityList vault_identity_list as written, null when unset or empty
     * @param bool $askVaultPass ask_vault_pass is true (y, yes, on, 1, true, t)
     * @param bool $idMatch vault_id_match has a value; Ansible does not type it, so even False turns it on
     * @param bool $encryptSalt vault_encrypt_salt is set
     */
    public function __construct(
        public readonly ?string $passwordFile,
        public readonly ?string $identityList,
        public readonly bool $askVaultPass,
        public readonly bool $idMatch,
        public readonly bool $encryptSalt,
    ) {
    }

    /**
     * Whether the repository brings vault passwords of its own (a password
     * file or script, or vault IDs), which Ansilume cannot check.
     */
    public function definesPasswordSource(): bool
    {
        return $this->passwordFile !== null || $this->identityList !== null;
    }

    /**
     * @return array{
     *     vault_password_file: string|null,
     *     vault_identity_list: string|null,
     *     ask_vault_pass: bool,
     *     vault_id_match: bool,
     *     vault_encrypt_salt: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'vault_password_file' => $this->passwordFile,
            'vault_identity_list' => $this->identityList,
            'ask_vault_pass' => $this->askVaultPass,
            'vault_id_match' => $this->idMatch,
            'vault_encrypt_salt' => $this->encryptSalt,
        ];
    }

    /**
     * The inverse of toArray(), e.g. for settings stored as JSON. Missing or
     * mistyped values count as unset.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stringOrNull($data['vault_password_file'] ?? null),
            self::stringOrNull($data['vault_identity_list'] ?? null),
            ($data['ask_vault_pass'] ?? null) === true,
            ($data['vault_id_match'] ?? null) === true,
            ($data['vault_encrypt_salt'] ?? null) === true,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
