<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Inventory;
use PHPUnit\Framework\TestCase;

class InventoryTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%inventory}}', Inventory::tableName());
    }

    public function testTypeConstants(): void
    {
        $this->assertSame('static', Inventory::TYPE_STATIC);
        $this->assertSame('dynamic', Inventory::TYPE_DYNAMIC);
        $this->assertSame('file', Inventory::TYPE_FILE);
    }

    public function testRulesRequireNameAndType(): void
    {
        $inv = new Inventory();
        $inv->validate();
        $this->assertArrayHasKey('name', $inv->errors);
        $this->assertArrayHasKey('inventory_type', $inv->errors);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = 'nonexistent_type';
        $inv->validate(['inventory_type']);
        $this->assertArrayHasKey('inventory_type', $inv->errors);
    }

    public function testStaticTypeIsValid(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = "all:\n  hosts:\n    localhost:";
        $inv->validate(['inventory_type', 'content']);
        $this->assertArrayNotHasKey('inventory_type', $inv->errors);
        $this->assertArrayNotHasKey('content', $inv->errors);
    }

    public function testDynamicTypeIsValid(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_DYNAMIC;
        $inv->validate(['inventory_type']);
        $this->assertArrayNotHasKey('inventory_type', $inv->errors);
    }

    public function testStaticTypeRequiresContent(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = null;
        $inv->validate(['content']);
        $this->assertArrayHasKey('content', $inv->errors);
    }

    public function testFileTypeRequiresSourcePathAndProjectId(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path    = null;
        $inv->project_id     = null;
        $inv->validate(['source_path', 'project_id']);
        $this->assertArrayHasKey('source_path', $inv->errors);
        $this->assertArrayHasKey('project_id', $inv->errors);
    }

    public function testSourcePathMaxLength512(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path    = str_repeat('a', 513);
        $inv->project_id     = 1;
        $inv->validate(['source_path']);
        $this->assertArrayHasKey('source_path', $inv->errors);
    }

    public function testValidYamlContentPasses(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = "all:\n  hosts:\n    web1:\n    web2:\n      ansible_user: ubuntu";
        $inv->validate(['content']);
        $this->assertArrayNotHasKey('content', $inv->errors);
    }

    public function testInvalidYamlContentFails(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = "all:\n  hosts:\n    - invalid: [unclosed";
        $inv->validate(['content']);
        $this->assertArrayHasKey('content', $inv->errors);
        $this->assertStringContainsString('Invalid YAML', $inv->errors['content'][0]);
    }

    public function testScalarYamlContentFails(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = 'just a plain string';
        $inv->validate(['content']);
        $this->assertArrayHasKey('content', $inv->errors);
        $this->assertStringContainsString('YAML mapping', $inv->errors['content'][0]);
    }

    public function testYamlValidationSkippedForNonStaticType(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->content        = 'not: valid: yaml: {{{{';
        $inv->source_path    = 'hosts.yml';
        $inv->project_id     = 1;
        $inv->validate(['content']);
        $this->assertArrayNotHasKey('content', $inv->errors);
    }

    public function testYamlValidationSkippedForEmptyContent(): void
    {
        $inv = new Inventory();
        $inv->name           = 'Test';
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content        = '';
        $inv->validate(['content']);
        // Empty content triggers the 'required' rule, but NOT a YAML parse error
        $errors = $inv->errors['content'] ?? [];
        foreach ($errors as $msg) {
            $this->assertStringNotContainsString('Invalid YAML', $msg);
            $this->assertStringNotContainsString('YAML mapping', $msg);
        }
    }

    // -------------------------------------------------------------------------
    // Path traversal validation (regression)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider pathTraversalProvider
     */
    public function testSourcePathTraversalValidation(string $path, bool $shouldFail): void
    {
        $inv = new Inventory();
        $inv->name = 'Test';
        $inv->inventory_type = Inventory::TYPE_FILE;
        $inv->source_path = $path;
        $inv->project_id = 1;
        $inv->validate(['source_path']);

        if ($shouldFail) {
            $this->assertArrayHasKey('source_path', $inv->errors, "Path '{$path}' should be rejected");
        } else {
            $this->assertArrayNotHasKey('source_path', $inv->errors, "Path '{$path}' should be accepted");
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pathTraversalProvider(): array
    {
        return [
            'simple traversal' => ['../../etc/passwd', true],
            'mid-path traversal' => ['inventories/../../../etc/shadow', true],
            'trailing traversal' => ['inventories/..', true],
            'leading traversal' => ['../hosts.yml', true],
            'normal relative path' => ['inventories/prod/hosts.yml', false],
            'simple filename' => ['hosts.yml', false],
            'double dots in name' => ['my..file.yml', false],
            'nested valid path' => ['group_vars/all/vault.yml', false],
        ];
    }

    /**
     * @dataProvider localhostDetectionProvider
     */
    public function testTargetsLocalhost(string $type, ?string $content, bool $expected, string $reason): void
    {
        $inv = new Inventory();
        $inv->inventory_type = $type;
        $inv->content = $content;
        $this->assertSame($expected, $inv->targetsLocalhost(), $reason);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool, 3: string}>
     */
    public static function localhostDetectionProvider(): array
    {
        return [
            'bare localhost in INI group' => [
                Inventory::TYPE_STATIC,
                "[local]\nlocalhost\n",
                true,
                'plain "localhost" as a hostname must match',
            ],
            'localhost with connection=local' => [
                Inventory::TYPE_STATIC,
                'localhost ansible_connection=local',
                true,
                'localhost with explicit connection must match',
            ],
            'remote host marked connection=local' => [
                Inventory::TYPE_STATIC,
                'deploybox ansible_connection=local',
                true,
                'any ansible_connection=local targets the runner regardless of host name',
            ],
            'loopback ipv4' => [
                Inventory::TYPE_STATIC,
                "[web]\n127.0.0.1\n",
                true,
                '127.0.0.1 is the runner loopback',
            ],
            'loopback ipv6' => [
                Inventory::TYPE_STATIC,
                "[web]\n::1\n",
                true,
                '::1 is the ipv6 runner loopback',
            ],
            'case insensitive localhost' => [
                Inventory::TYPE_STATIC,
                'LocalHost ansible_user=root',
                true,
                'matcher must be case-insensitive',
            ],
            'YAML format with localhost host' => [
                Inventory::TYPE_STATIC,
                "all:\n  hosts:\n    localhost: {}\n",
                true,
                'YAML inventories with localhost must match',
            ],
            'false positive guard: hyphenated name' => [
                Inventory::TYPE_STATIC,
                "[web]\nmy-localhost-dev.example.com\n",
                false,
                'word boundaries must prevent a match inside a hyphenated remote hostname',
            ],
            'pure remote hosts' => [
                Inventory::TYPE_STATIC,
                "[web]\n10.0.0.1\n192.168.1.5 ansible_user=root\n",
                false,
                'no localhost references means no warning',
            ],
            'empty string content' => [
                Inventory::TYPE_STATIC,
                '',
                false,
                'empty content must not match',
            ],
            'null content' => [
                Inventory::TYPE_STATIC,
                null,
                false,
                'null content must not match',
            ],
            'file inventory with localhost in content' => [
                Inventory::TYPE_FILE,
                'localhost',
                false,
                'file inventories are excluded — content is a path, not hosts',
            ],
            'dynamic inventory with localhost in content' => [
                Inventory::TYPE_DYNAMIC,
                'localhost',
                false,
                'dynamic inventories are excluded — content is a script hint, not hosts',
            ],
        ];
    }
}
