<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\TotpService;

/**
 * Testable subclass that exposes protected methods for unit testing.
 */
class TestableTotpService extends TotpService
{
    public function encryptSecret(string $plainSecret): string
    {
        return parent::encryptSecret($plainSecret);
    }

    public function decryptSecret(string $ciphertext): string
    {
        return parent::decryptSecret($ciphertext);
    }

    public function generateRecoveryCodes(): array
    {
        return parent::generateRecoveryCodes();
    }
}
