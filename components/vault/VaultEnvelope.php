<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * One Ansible vault, a whole encrypted file or an inline `!vault` value,
 * parsed the way ansible-core reads it, with a password check that never
 * decrypts.
 *
 * The text is a header line `$ANSIBLE_VAULT;<version>;AES256[;<vault id>]`
 * and a body of hex lines (80 columns when Ansible writes it). The body
 * decodes to three lines: hex(salt), hex(HMAC), hex(ciphertext).
 *
 * PBKDF2-HMAC-SHA256 with 10000 rounds turns password and salt into the AES
 * key (bytes 0-32), the HMAC key (32-64) and the counter block (64-80).
 * Before it decrypts, Ansible compares HMAC-SHA256(HMAC key, ciphertext) with
 * the stored HMAC. opens() runs only that comparison: a match means Ansible
 * can decrypt with the password, and no plaintext is ever produced here.
 */
final class VaultEnvelope
{
    public const HEADER = '$ANSIBLE_VAULT';

    /** The only cipher ansible-core reads and writes. */
    public const CIPHER = 'AES256';

    /**
     * Python's bytes.strip() set, which Ansible applies to header fields and
     * to the content of a password file. PHP's default trim() set differs: it
     * also strips NUL and keeps the form feed.
     */
    private const PYTHON_WHITESPACE = " \t\n\r\x0B\x0C";

    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const PBKDF2_ROUNDS = 10000;

    private const HMAC_BYTES = 32;

    /**
     * @param string $version format version from the header, e.g. '1.1' or '1.2'
     * @param string|null $vaultId the 4th header field (format 1.2); '' when present but empty
     * @param string $salt raw bytes, 32 unless vault_encrypt_salt was set
     * @param string $hmac raw bytes, 32
     * @param string $ciphertext raw bytes
     */
    public function __construct(
        public readonly string $version,
        public readonly ?string $vaultId,
        public readonly string $salt,
        public readonly string $hmac,
        public readonly string $ciphertext,
    ) {
    }

    /**
     * Accepts what Ansible accepts: CRLF line endings, re-wrapped or
     * upper-case hex, any salt length. Rejects what Ansible rejects, and a
     * HMAC that is not 32 bytes long, which no password can match.
     *
     * @throws \UnexpectedValueException with an operator-readable reason
     */
    public static function parse(string $text): self
    {
        if (!str_starts_with($text, self::HEADER)) {
            throw new \UnexpectedValueException(self::notVaultDataReason($text));
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $header = self::parseHeader($lines[0]);
        // Ansible joins the body lines without stripping them.
        $body = implode('', array_slice($lines, 1));
        if ($body === '') {
            throw new \UnexpectedValueException('the vault has a header but no body');
        }
        [$salt, $hmac, $ciphertext] = self::parseBody($body);

        return new self($header['version'], $header['vaultId'], $salt, $hmac, $ciphertext);
    }

    /**
     * Whether Ansible would decrypt this vault with the password. The
     * password is normalised like a password file first; '' never opens.
     */
    public function opens(string $password): bool
    {
        $password = self::normalizePassword($password);
        if ($password === '') {
            return false;
        }
        // 64 bytes cover the AES key and the HMAC key, the counter block is
        // not needed. Nothing derived from the password outlives this call.
        $keys = (string)openssl_pbkdf2($password, $this->salt, 64, self::PBKDF2_ROUNDS, 'sha256');

        return hash_equals($this->hmac, hash_hmac('sha256', $this->ciphertext, substr($keys, 32), true));
    }

    /**
     * Identifies the encrypted content (sha256 hex of salt, HMAC and
     * ciphertext); the header is not part of it.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->salt . $this->hmac . $this->ciphertext);
    }

    /**
     * The password as Ansible reads it from a password file: Python
     * whitespace stripped at both ends.
     */
    public static function normalizePassword(string $password): string
    {
        return trim($password, self::PYTHON_WHITESPACE);
    }

    /**
     * @return array{version: string, vaultId: string|null}
     */
    private static function parseHeader(string $line): array
    {
        // Ansible only reads ASCII text as vault data.
        if (preg_match('/[\x80-\xFF]/', $line) === 1) {
            throw new \UnexpectedValueException(
                'ansible does not read it as vault data: the header has non-ASCII characters'
            );
        }
        // A folded (>) or plain YAML value turns the line breaks into spaces.
        if (preg_match('/\s[0-9a-fA-F]{32}/', $line) === 1) {
            throw new \UnexpectedValueException(
                'the header and the body are on one line: a folded (>) YAML value loses the line breaks, use |'
            );
        }
        $fields = array_map(
            static fn (string $field): string => trim($field, self::PYTHON_WHITESPACE),
            explode(';', $line)
        );
        if (count($fields) < 3) {
            throw new \UnexpectedValueException('the vault header has no cipher field');
        }
        // Error messages never quote file content: it could be anything.
        if ($fields[2] !== self::CIPHER) {
            throw new \UnexpectedValueException('unsupported cipher: ansible reads only AES256');
        }

        // Ansible takes a 4th field as the vault ID whatever the version says.
        return ['version' => $fields[1], 'vaultId' => $fields[3] ?? null];
    }

    /**
     * @return array{0: string, 1: string, 2: string} salt, HMAC and ciphertext
     */
    private static function parseBody(string $body): array
    {
        $lines = explode("\n", self::decodeHex($body, 'the vault body'), 3);
        if (count($lines) !== 3) {
            throw new \UnexpectedValueException('the decoded vault body is not salt, HMAC and ciphertext');
        }
        $hmac = self::decodeHex($lines[1], 'the HMAC');
        if (strlen($hmac) !== self::HMAC_BYTES) {
            throw new \UnexpectedValueException(
                sprintf('the HMAC has %d bytes instead of 32, no password opens it', strlen($hmac))
            );
        }

        return [self::decodeHex($lines[0], 'the salt'), $hmac, self::decodeHex($lines[2], 'the ciphertext')];
    }

    /**
     * Strict hex like Python's unhexlify. ctype_xdigit() instead of a regular
     * expression: PCRE gives up on bodies of a few hundred kilobytes.
     */
    private static function decodeHex(string $hex, string $what): string
    {
        if ($hex !== '' && !ctype_xdigit($hex)) {
            throw new \UnexpectedValueException(strpbrk($hex, " \t") === false
                ? $what . ' has characters that are not hex digits'
                : $what . ' has spaces or tabs, e.g. trailing whitespace on a line');
        }
        if (strlen($hex) % 2 !== 0) {
            throw new \UnexpectedValueException($what . ' has an odd number of hex digits');
        }

        return (string)hex2bin($hex);
    }

    private static function notVaultDataReason(string $text): string
    {
        $unmarked = str_starts_with($text, self::BYTE_ORDER_MARK)
            ? substr($text, strlen(self::BYTE_ORDER_MARK))
            : $text;
        $trimmed = ltrim($unmarked, self::PYTHON_WHITESPACE);
        if ($trimmed === '') {
            $reason = 'it is empty';
        } elseif (!str_starts_with($trimmed, self::HEADER)) {
            $reason = 'it does not start with $ANSIBLE_VAULT';
        } elseif ($unmarked !== $text) {
            $reason = 'it starts with a UTF-8 byte order mark';
        } else {
            $reason = 'there are blank lines or spaces before $ANSIBLE_VAULT';
        }

        return 'ansible does not read it as vault data: ' . $reason;
    }
}
