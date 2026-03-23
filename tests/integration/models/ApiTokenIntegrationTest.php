<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\ApiToken;
use app\tests\integration\DbTestCase;

class ApiTokenIntegrationTest extends DbTestCase
{
    public function testGenerateCreatesTokenRecord(): void
    {
        $user   = $this->createUser();
        $before = (int)ApiToken::find()->count();

        ApiToken::generate($user->id, 'My CI token');

        $this->assertSame($before + 1, (int)ApiToken::find()->count());
    }

    public function testGenerateReturnsRaw64HexString(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'CI');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['raw']);
    }

    public function testGenerateHashIsSha256OfRaw(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'CI');

        $this->assertSame(hash('sha256', $result['raw']), $result['token']->token_hash);
    }

    public function testGenerateWithExpiryStoresExpiresAt(): void
    {
        $user    = $this->createUser();
        $expires = time() + 86400;
        $result  = ApiToken::generate($user->id, 'CI', $expires);

        $this->assertSame($expires, (int)$result['token']->expires_at);
    }

    public function testFindByRawTokenReturnsTokenWhenValid(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'CI');

        $found = ApiToken::findByRawToken($result['raw']);

        $this->assertNotNull($found);
        $this->assertSame($result['token']->id, $found->id);
    }

    public function testFindByRawTokenReturnsNullForUnknownToken(): void
    {
        $found = ApiToken::findByRawToken(str_repeat('a', 64));
        $this->assertNull($found);
    }

    public function testFindByRawTokenReturnsNullWhenExpired(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'expired', time() - 1);

        $found = ApiToken::findByRawToken($result['raw']);

        $this->assertNull($found);
    }

    public function testFindByRawTokenReturnsNullForEmptyString(): void
    {
        $this->assertNull(ApiToken::findByRawToken(''));
    }

    public function testFindByRawTokenReturnsTokenWhenNoExpiry(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'no-expiry', null);

        $found = ApiToken::findByRawToken($result['raw']);

        $this->assertNotNull($found);
    }
}
