<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerCommandBuilder;
use PHPUnit\Framework\TestCase;

class RunnerCommandBuilderTest extends TestCase
{
    private RunnerCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RunnerCommandBuilder();
    }

    public function testMinimalCommand(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
        ]);

        $this->assertSame(['ansible-playbook', 'site.yml'], $cmd);
    }

    public function testStaticInventoryUsesPlaceholder(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'static',
            'playbook_path' => 'site.yml',
        ]);

        $this->assertContains('-i', $cmd);
        $this->assertContains('__INVENTORY_TMP__', $cmd);
    }

    public function testFileInventoryPath(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'inventory_path' => '/etc/ansible/hosts',
            'playbook_path' => 'site.yml',
        ]);

        $this->assertContains('-i', $cmd);
        $this->assertContains('/etc/ansible/hosts', $cmd);
    }

    public function testVerbosityFlag(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'verbosity' => 3,
        ]);

        $this->assertContains('-vvv', $cmd);
    }

    public function testVerbosityCappedAtFive(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'verbosity' => 10,
        ]);

        $this->assertContains('-vvvvv', $cmd);
    }

    public function testZeroVerbosityOmitsFlag(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'verbosity' => 0,
        ]);

        foreach ($cmd as $part) {
            $this->assertDoesNotMatchRegularExpression('/^-v+$/', $part);
        }
    }

    public function testBecomeFlags(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'become' => true,
            'become_method' => 'su',
            'become_user' => 'admin',
        ]);

        $this->assertContains('--become', $cmd);
        $this->assertContains('--become-method', $cmd);
        $this->assertContains('su', $cmd);
        $this->assertContains('--become-user', $cmd);
        $this->assertContains('admin', $cmd);
    }

    public function testBecomeDefaultsToSudoRoot(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'become' => true,
        ]);

        $this->assertContains('--become', $cmd);
        $this->assertContains('sudo', $cmd);
        $this->assertContains('root', $cmd);
    }

    public function testNoBecomeWhenEmpty(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'become' => false,
        ]);

        $this->assertNotContains('--become', $cmd);
    }

    public function testForksOption(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'forks' => 10,
        ]);

        $idx = array_search('--forks', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('10', $cmd[$idx + 1]);
    }

    public function testLimitOption(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'limit' => 'webservers',
        ]);

        $this->assertContains('--limit', $cmd);
        $this->assertContains('webservers', $cmd);
    }

    public function testTagsOption(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'tags' => 'deploy,restart',
        ]);

        $this->assertContains('--tags', $cmd);
        $this->assertContains('deploy,restart', $cmd);
    }

    public function testSkipTagsOption(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'skip_tags' => 'slow',
        ]);

        $this->assertContains('--skip-tags', $cmd);
        $this->assertContains('slow', $cmd);
    }

    public function testExtraVarsOption(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'extra_vars' => '{"foo":"bar"}',
        ]);

        $this->assertContains('--extra-vars', $cmd);
        $this->assertContains('{"foo":"bar"}', $cmd);
    }

    public function testCheckModeAddsCheckAndDiff(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'check_mode' => true,
        ]);

        $this->assertContains('--check', $cmd);
        $this->assertContains('--diff', $cmd);
    }

    public function testCheckModeFalseOmitsFlags(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
            'check_mode' => false,
        ]);

        $this->assertNotContains('--check', $cmd);
        $this->assertNotContains('--diff', $cmd);
    }

    public function testCheckModeAbsentOmitsFlags(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'playbook_path' => 'site.yml',
        ]);

        $this->assertNotContains('--check', $cmd);
        $this->assertNotContains('--diff', $cmd);
    }

    public function testFullPayload(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'static',
            'playbook_path' => 'deploy.yml',
            'verbosity' => 2,
            'become' => true,
            'become_method' => 'sudo',
            'become_user' => 'root',
            'forks' => 5,
            'limit' => 'prod',
            'tags' => 'deploy',
            'extra_vars' => '{"version":"1.0"}',
            'check_mode' => true,
        ]);

        $this->assertSame('ansible-playbook', $cmd[0]);
        $this->assertContains('-i', $cmd);
        $this->assertContains('deploy.yml', $cmd);
        $this->assertContains('-vv', $cmd);
        $this->assertContains('--become', $cmd);
        $this->assertContains('--forks', $cmd);
        $this->assertContains('--limit', $cmd);
        $this->assertContains('--tags', $cmd);
        $this->assertContains('--extra-vars', $cmd);
        $this->assertContains('--check', $cmd);
        $this->assertContains('--diff', $cmd);
    }
}
