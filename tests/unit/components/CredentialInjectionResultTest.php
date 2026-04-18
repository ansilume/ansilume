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

    public function testPropertiesActuallyRefuseMutation(): void
    {
        $result = new CredentialInjectionResult(['--user', 'deploy'], ['FOO' => 'bar'], ['/tmp/x']);

        // Readonly is a language feature — assert the runtime behaviour
        // rather than re-verifying the reflection metadata. Any attempt
        // to rewrite a readonly property throws at runtime.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        /** @phpstan-ignore-next-line */
        $result->args = ['mutated'];
    }
}
