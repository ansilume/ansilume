<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * Lists the vault content of one file: the file itself when it is a whole
 * encrypted file, otherwise its inline `!vault` values.
 */
final class VaultFileInspector
{
    /**
     * Never loaded as variables by Ansible: `!vault |` blocks in them are
     * examples or template text, not values.
     */
    private const DOCUMENT_EXTENSIONS = ['md', 'markdown', 'rst', 'adoc', 'j2', 'jinja', 'jinja2'];

    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * A file that starts with $ANSIBLE_VAULT is one KIND_FILE entry, and so is
     * one with a UTF-8 byte order mark or blank lines before it, which Ansible
     * does not read as vault data (the entry then carries the error).
     *
     * @param string $path relative to the checkout root
     * @return list<VaultScanEntry>
     */
    public static function inspect(string $path, string $content): array
    {
        if (self::isWholeFileVault($content)) {
            return [self::entry($path, VaultScanEntry::KIND_FILE, null, null, $content)];
        }
        if (!str_contains($content, '!vault') || self::isDocument($path)) {
            return [];
        }
        $entries = [];
        foreach (InlineVaultExtractor::extract($content) as $value) {
            $entries[] = self::entry($path, VaultScanEntry::KIND_INLINE, $value['line'], $value['key'], $value['envelope']);
        }

        return $entries;
    }

    private static function isWholeFileVault(string $content): bool
    {
        $unmarked = str_starts_with($content, self::BYTE_ORDER_MARK)
            ? substr($content, strlen(self::BYTE_ORDER_MARK))
            : $content;
        $start = strspn($unmarked, " \t\r\n\x0B\x0C");

        return substr($unmarked, $start, strlen(VaultEnvelope::HEADER)) === VaultEnvelope::HEADER;
    }

    private static function isDocument(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::DOCUMENT_EXTENSIONS, true);
    }

    /**
     * Parses the vault and keeps what the scan stores; the encrypted content
     * is released with the envelope.
     */
    private static function entry(string $path, string $kind, ?int $line, ?string $key, string $text): VaultScanEntry
    {
        try {
            $envelope = VaultEnvelope::parse($text);
        } catch (\UnexpectedValueException $e) {
            return new VaultScanEntry($path, $kind, $line, $key, null, null, null, $e->getMessage());
        }

        return new VaultScanEntry($path, $kind, $line, $key, $envelope->version, $envelope->vaultId, $envelope->fingerprint(), null);
    }
}
