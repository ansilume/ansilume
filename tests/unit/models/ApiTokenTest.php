<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\ApiToken;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class ApiTokenTest extends TestCase
{
    private function makeToken(array $attributes): ApiToken
    {
        $t = $this->getMockBuilder(ApiToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($t, $attributes);
        return $t;
    }

    public function testTableName(): void
    {
        $this->assertSame('{{%api_token}}', ApiToken::tableName());
    }

    public function testIsNotExpiredWhenNoExpiry(): void
    {
        $token = $this->makeToken(['expires_at' => null]);
        $this->assertFalse($token->isExpired());
    }

    public function testIsNotExpiredWhenExpiryInFuture(): void
    {
        $token = $this->makeToken(['expires_at' => time() + 3600]);
        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredWhenExpiryInPast(): void
    {
        $token = $this->makeToken(['expires_at' => time() - 1]);
        $this->assertTrue($token->isExpired());
    }

    public function testIsExpiredExactlyAtExpiryTime(): void
    {
        // expires_at < time() means expired — equal is not yet expired
        $token = $this->makeToken(['expires_at' => time() - 0]);
        // time() - 0 equals time(), so expires_at < time() is false — not expired
        $this->assertFalse($token->isExpired());
    }

    public function testRulesRequireUserIdAndName(): void
    {
        $token = new ApiToken();
        $token->validate();
        $this->assertArrayHasKey('user_id', $token->errors);
        $this->assertArrayHasKey('name', $token->errors);
    }

    public function testNameMaxLength128(): void
    {
        $token = new ApiToken();
        $token->user_id = 1;
        $token->name    = str_repeat('a', 129);
        $token->validate(['name']);
        $this->assertArrayHasKey('name', $token->errors);
    }

    public function testNameExactly128CharactersIsValid(): void
    {
        $token = new ApiToken();
        $token->user_id = 1;
        $token->name    = str_repeat('a', 128);
        $token->validate(['name']);
        $this->assertArrayNotHasKey('name', $token->errors);
    }
}
