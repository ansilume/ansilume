<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\TotpRateLimiter;
use app\services\TotpService;
use app\tests\integration\DbTestCase;

class TotpServiceTest extends DbTestCase
{
    private function service(): TotpService
    {
        $s = new TotpService();
        $s->rateLimiter = new TotpRateLimiter();
        return $s;
    }

    public function testEnableStoresEncryptedSecretAndRecoveryCodes(): void
    {
        $user = $this->createUser('totp_enable');
        $secret = $this->service()->generateSecret();

        $rawCodes = $this->service()->enable($user, $secret);

        $user->refresh();
        $this->assertTrue((bool)$user->totp_enabled);
        $this->assertNotNull($user->totp_secret);
        $this->assertNotSame($secret, $user->totp_secret);
        $this->assertNotNull($user->recovery_codes);
        $this->assertCount(10, $rawCodes);
    }

    public function testDisableClearsAllTotpFields(): void
    {
        $user = $this->createUser('totp_disable');
        $secret = $this->service()->generateSecret();
        $this->service()->enable($user, $secret);

        $this->service()->disable($user);

        $user->refresh();
        $this->assertFalse((bool)$user->totp_enabled);
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->recovery_codes);
    }

    public function testGetUserSecretDecryptsCorrectly(): void
    {
        $user = $this->createUser('totp_secret');
        $secret = $this->service()->generateSecret();
        $this->service()->enable($user, $secret);

        $user->refresh();
        $decrypted = $this->service()->getUserSecret($user);

        $this->assertSame($secret, $decrypted);
    }

    public function testGetUserSecretReturnsNullWhenNotEnabled(): void
    {
        $user = $this->createUser('totp_none');

        $result = $this->service()->getUserSecret($user);

        $this->assertNull($result);
    }

    public function testRecoveryCodeRoundTrip(): void
    {
        $user = $this->createUser('totp_recovery');
        $secret = $this->service()->generateSecret();
        $rawCodes = $this->service()->enable($user, $secret);

        $user->refresh();
        $this->assertSame(10, $this->service()->remainingRecoveryCodeCount($user));

        // Use the first recovery code
        $used = $this->service()->useRecoveryCode($user, $rawCodes[0]);
        $this->assertTrue($used);

        $user->refresh();
        $this->assertSame(9, $this->service()->remainingRecoveryCodeCount($user));

        // Same code should not work again
        $usedAgain = $this->service()->useRecoveryCode($user, $rawCodes[0]);
        $this->assertFalse($usedAgain);
    }

    public function testInvalidRecoveryCodeReturnsFalse(): void
    {
        $user = $this->createUser('totp_invalid');
        $secret = $this->service()->generateSecret();
        $this->service()->enable($user, $secret);

        $user->refresh();
        $result = $this->service()->useRecoveryCode($user, 'XXXX-XXXX');
        $this->assertFalse($result);
    }
}
