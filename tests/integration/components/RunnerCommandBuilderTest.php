<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\RunnerCommandBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RunnerCommandBuilder covering all branches:
 * - inventory types (static, file, none)
 * - playbook options (verbosity, become, forks, limit, tags, skip_tags, extra_vars)
 * - check mode
 * - edge cases (empty payload, missing keys)
 */
class RunnerCommandBuilderTest extends TestCase
{
    private RunnerCommandBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RunnerCommandBuilder();
    }

    // -------------------------------------------------------------------------
    // Minimal / empty payload
    // -------------------------------------------------------------------------

    public function testBuildMinimalPayloadReturnsBaseCommand(): void
    {
        $cmd = $this->builder->build([]);

        $this->assertSame('ansible-playbook', $cmd[0]);
        // With empty payload, playbook_path defaults to empty string.
        $this->assertContains('', $cmd);
    }

    public function testBuildWithPlaybookPathOnly(): void
    {
        $cmd = $this->builder->build(['playbook_path' => '/opt/project/site.yml']);

        $this->assertSame('ansible-playbook', $cmd[0]);
        $this->assertContains('/opt/project/site.yml', $cmd);
    }

    // -------------------------------------------------------------------------
    // Inventory arguments
    // -------------------------------------------------------------------------

    public function testBuildStaticInventoryUsesPlaceholder(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'static',
            'playbook_path' => 'site.yml',
        ]);

        $idx = array_search('-i', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('__INVENTORY_TMP__', $cmd[(int)$idx + 1]);
    }

    public function testBuildFileInventoryUsesPath(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'file',
            'inventory_path' => '/opt/inventory/hosts.ini',
            'playbook_path' => 'site.yml',
        ]);

        $idx = array_search('-i', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('/opt/inventory/hosts.ini', $cmd[(int)$idx + 1]);
    }

    public function testBuildNoInventoryTypeSkipsInventoryArg(): void
    {
        $cmd = $this->builder->build(['playbook_path' => 'site.yml']);

        $this->assertNotContains('-i', $cmd);
    }

    public function testBuildStaticInventoryTakesPrecedenceOverPath(): void
    {
        $cmd = $this->builder->build([
            'inventory_type' => 'static',
            'inventory_path' => '/opt/hosts.ini',
            'playbook_path' => 'site.yml',
        ]);

        $idx = array_search('-i', $cmd, true);
        $this->assertNotFalse($idx);
        // Static type should use placeholder, not the path.
        $this->assertSame('__INVENTORY_TMP__', $cmd[(int)$idx + 1]);
    }

    // -------------------------------------------------------------------------
    // Verbosity
    // -------------------------------------------------------------------------

    public function testBuildVerbosityZeroNoFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'verbosity' => 0,
        ]);

        foreach ($cmd as $arg) {
            $this->assertDoesNotMatchRegularExpression('/^-v+$/', $arg);
        }
    }

    public function testBuildVerbosityOneAddsV(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'verbosity' => 1,
        ]);

        $this->assertContains('-v', $cmd);
    }

    public function testBuildVerbosityThreeAddsVvv(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'verbosity' => 3,
        ]);

        $this->assertContains('-vvv', $cmd);
    }

    public function testBuildVerbosityFiveAddsVvvvv(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'verbosity' => 5,
        ]);

        $this->assertContains('-vvvvv', $cmd);
    }

    public function testBuildVerbosityCappedAtFive(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'verbosity' => 10,
        ]);

        // Capped at 5 v's.
        $this->assertContains('-vvvvv', $cmd);
        $this->assertNotContains('-vvvvvvvvvv', $cmd);
    }

    // -------------------------------------------------------------------------
    // Become
    // -------------------------------------------------------------------------

    public function testBuildBecomeAddsFlags(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'become' => true,
            'become_method' => 'sudo',
            'become_user' => 'root',
        ]);

        $this->assertContains('--become', $cmd);
        $this->assertContains('--become-method', $cmd);
        $this->assertContains('sudo', $cmd);
        $this->assertContains('--become-user', $cmd);
        $this->assertContains('root', $cmd);
    }

    public function testBuildBecomeDefaultsToSudoAndRoot(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'become' => true,
        ]);

        $this->assertContains('--become', $cmd);
        $idx = array_search('--become-method', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('sudo', $cmd[(int)$idx + 1]);

        $idx = array_search('--become-user', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('root', $cmd[(int)$idx + 1]);
    }

    public function testBuildBecomeCustomMethodAndUser(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'become' => true,
            'become_method' => 'doas',
            'become_user' => 'admin',
        ]);

        $idx = array_search('--become-method', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('doas', $cmd[(int)$idx + 1]);

        $idx = array_search('--become-user', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('admin', $cmd[(int)$idx + 1]);
    }

    public function testBuildNoBecomeOmitsFlags(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'become' => false,
        ]);

        $this->assertNotContains('--become', $cmd);
        $this->assertNotContains('--become-method', $cmd);
        $this->assertNotContains('--become-user', $cmd);
    }

    public function testBuildMissingBecomeKeyOmitsFlags(): void
    {
        $cmd = $this->builder->build(['playbook_path' => 'site.yml']);

        $this->assertNotContains('--become', $cmd);
    }

    // -------------------------------------------------------------------------
    // Forks, limit, tags, skip_tags, extra_vars
    // -------------------------------------------------------------------------

    public function testBuildForksAddsFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'forks' => 10,
        ]);

        $idx = array_search('--forks', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('10', $cmd[(int)$idx + 1]);
    }

    public function testBuildLimitAddsFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'limit' => 'webservers',
        ]);

        $idx = array_search('--limit', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('webservers', $cmd[(int)$idx + 1]);
    }

    public function testBuildTagsAddsFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'tags' => 'deploy,restart',
        ]);

        $idx = array_search('--tags', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('deploy,restart', $cmd[(int)$idx + 1]);
    }

    public function testBuildSkipTagsAddsFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'skip_tags' => 'slow',
        ]);

        $idx = array_search('--skip-tags', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('slow', $cmd[(int)$idx + 1]);
    }

    public function testBuildExtraVarsAddsFlag(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'extra_vars' => '{"env":"staging"}',
        ]);

        $idx = array_search('--extra-vars', $cmd, true);
        $this->assertNotFalse($idx);
        $this->assertSame('{"env":"staging"}', $cmd[(int)$idx + 1]);
    }

    public function testBuildEmptyOptionsOmitFlags(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'forks' => 0,
            'limit' => '',
            'tags' => '',
            'skip_tags' => '',
            'extra_vars' => '',
        ]);

        $this->assertNotContains('--forks', $cmd);
        $this->assertNotContains('--limit', $cmd);
        $this->assertNotContains('--tags', $cmd);
        $this->assertNotContains('--skip-tags', $cmd);
        $this->assertNotContains('--extra-vars', $cmd);
    }

    // -------------------------------------------------------------------------
    // Check mode
    // -------------------------------------------------------------------------

    public function testBuildCheckModeAddsCheckAndDiff(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'check_mode' => true,
        ]);

        $this->assertContains('--check', $cmd);
        $this->assertContains('--diff', $cmd);
    }

    public function testBuildCheckModeFalseOmitsFlags(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'check_mode' => false,
        ]);

        $this->assertNotContains('--check', $cmd);
        $this->assertNotContains('--diff', $cmd);
    }

    public function testBuildMissingCheckModeOmitsFlags(): void
    {
        $cmd = $this->builder->build(['playbook_path' => 'site.yml']);

        $this->assertNotContains('--check', $cmd);
        $this->assertNotContains('--diff', $cmd);
    }

    // -------------------------------------------------------------------------
    // Full payload
    // -------------------------------------------------------------------------

    public function testBuildFullPayloadProducesCompleteCommand(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => '/opt/project/deploy.yml',
            'inventory_type' => 'file',
            'inventory_path' => '/opt/inventory/prod.ini',
            'verbosity' => 2,
            'become' => true,
            'become_method' => 'sudo',
            'become_user' => 'deploy',
            'forks' => 20,
            'limit' => 'webservers:&staging',
            'tags' => 'deploy',
            'skip_tags' => 'debug',
            'extra_vars' => '{"version":"1.2.3"}',
            'check_mode' => true,
        ]);

        $this->assertSame('ansible-playbook', $cmd[0]);
        $this->assertContains('-i', $cmd);
        $this->assertContains('/opt/inventory/prod.ini', $cmd);
        $this->assertContains('/opt/project/deploy.yml', $cmd);
        $this->assertContains('-vv', $cmd);
        $this->assertContains('--become', $cmd);
        $this->assertContains('--forks', $cmd);
        $this->assertContains('--limit', $cmd);
        $this->assertContains('--tags', $cmd);
        $this->assertContains('--skip-tags', $cmd);
        $this->assertContains('--extra-vars', $cmd);
        $this->assertContains('--check', $cmd);
        $this->assertContains('--diff', $cmd);
    }

    public function testBuildCommandIsArrayOfStrings(): void
    {
        $cmd = $this->builder->build([
            'playbook_path' => 'site.yml',
            'forks' => 5,
            'verbosity' => 1,
        ]);

        foreach ($cmd as $element) {
            $this->assertIsString($element);
        }
    }
}
