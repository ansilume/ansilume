<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CredentialInjectionResult;
use PHPUnit\Framework\TestCase;

class CredentialInjectionResultTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $result = new CredentialInjectionResult(
            ['--private-key', '/tmp/key'],
            ['ANSIBLE_SSH_PASS' => 'secret'],
            ['/tmp/key'],
        );

        $this->assertSame(['--private-key', '/tmp/key'], $result->args);
        $this->assertSame(['ANSIBLE_SSH_PASS' => 'secret'], $result->env);
        $this->assertSame(['/tmp/key'], $result->tempFiles);
    }

    public function testEmptyReturnsNoArgsNoEnvNoFiles(): void
    {
        $result = CredentialInjectionResult::empty();

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    public function testPropertiesAreReadonly(): void
    {
        $result = new CredentialInjectionResult(['--user', 'deploy'], [], []);

        // Verify the object is immutable by confirming readonly properties exist
        $ref = new \ReflectionClass($result);
        $this->assertTrue($ref->getProperty('args')->isReadOnly());
        $this->assertTrue($ref->getProperty('env')->isReadOnly());
        $this->assertTrue($ref->getProperty('tempFiles')->isReadOnly());
    }
}
