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

    // -- tableName --------------------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%api_token}}', ApiToken::tableName());
    }

    // -- validation -------------------------------------------------------------

    public function testValidationRequiresUserIdAndName(): void
    {
        $token = new ApiToken();
        $this->assertFalse($token->validate());
        $this->assertArrayHasKey('user_id', $token->getErrors());
        $this->assertArrayHasKey('name', $token->getErrors());
    }

    public function testValidationPassesWithRequiredFields(): void
    {
        $user = $this->createUser();
        $token = new ApiToken();
        $token->user_id = $user->id;
        $token->name = 'CI Token';
        $this->assertTrue($token->validate());
    }

    public function testValidationRejectsNameOver128Chars(): void
    {
        $token = new ApiToken();
        $token->user_id = 1;
        $token->name = str_repeat('a', 129);
        $this->assertFalse($token->validate(['name']));
    }

    public function testValidationAcceptsOptionalExpiresAt(): void
    {
        $user = $this->createUser();
        $token = new ApiToken();
        $token->user_id = $user->id;
        $token->name = 'test';
        $token->expires_at = time() + 86400;
        $this->assertTrue($token->validate());
    }

    // -- isExpired --------------------------------------------------------------

    public function testIsExpiredReturnsTrueWhenExpired(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'expired', time() - 100);
        $this->assertTrue($result['token']->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNotExpired(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'valid', time() + 86400);
        $this->assertFalse($result['token']->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'forever', null);
        $this->assertFalse($result['token']->isExpired());
    }

    // -- user relation ----------------------------------------------------------

    public function testUserRelationReturnsUser(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'CI');
        $this->assertNotNull($result['token']->user);
        $this->assertSame($user->id, $result['token']->user->id);
    }

    // -- generate details -------------------------------------------------------

    public function testGenerateSetsCreatedAt(): void
    {
        $user   = $this->createUser();
        $before = time();
        $result = ApiToken::generate($user->id, 'CI');
        $this->assertGreaterThanOrEqual($before, (int)$result['token']->created_at);
    }

    public function testGenerateSetsUserIdAndName(): void
    {
        $user   = $this->createUser();
        $result = ApiToken::generate($user->id, 'My Token');
        $this->assertSame($user->id, (int)$result['token']->user_id);
        $this->assertSame('My Token', $result['token']->name);
    }

    public function testGenerateProducesUniqueTokensPerCall(): void
    {
        $user = $this->createUser();
        $r1 = ApiToken::generate($user->id, 'Token 1');
        $r2 = ApiToken::generate($user->id, 'Token 2');
        $this->assertNotSame($r1['raw'], $r2['raw']);
        $this->assertNotSame($r1['token']->token_hash, $r2['token']->token_hash);
    }
}
