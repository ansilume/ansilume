<?php

declare(strict_types=1);

namespace app\tests\unit\components\vault;

use app\components\vault\TemplateVaultFiles;
use PHPUnit\Framework\TestCase;

class TemplateVaultFilesTest extends TestCase
{
    use TemporaryTree;

    /** The entry paths VaultContentScanner finds in the fixture repository. */
    private const REPO_ENTRIES = [
        'group_vars/all.yml',
        'inventories/dev/group_vars/all/vault.yml',
        'inventories/prod/group_vars/all/vault.yml',
        'inventories/prod/host_vars/prod-web1.yml',
        'playbooks/deploy-vars.yml',
        'playbooks/group_vars/web.yml',
        'vars/secrets.yml',
    ];

    protected function tearDown(): void
    {
        $this->removeTrees();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: list<string>}>
     */
    public static function fixtureTemplateProvider(): array
    {
        return [
            'root playbook, prod inventory file' => ['site.yml', 'file', 'inventories/prod/hosts.yml', [
                'group_vars/all.yml',
                'inventories/prod/group_vars/all/vault.yml',
                'inventories/prod/host_vars/prod-web1.yml',
                'vars/secrets.yml',
            ]],
            'root playbook, dev inventory directory' => ['site.yml', 'file', 'inventories/dev', [
                'group_vars/all.yml',
                'inventories/dev/group_vars/all/vault.yml',
                'vars/secrets.yml',
            ]],
            'nested playbook, dynamic dev inventory' => ['playbooks/deploy.yml', 'dynamic', 'inventories/dev/hosts.yml', [
                'inventories/dev/group_vars/all/vault.yml',
                'playbooks/deploy-vars.yml',
                'playbooks/group_vars/web.yml',
                'vars/secrets.yml',
            ]],
            'static inventory: no inventory directory' => ['site.yml', 'static', null, ['group_vars/all.yml', 'vars/secrets.yml']],
            'unknown inventory type' => ['site.yml', 'scm', 'inventories/prod/hosts.yml', ['group_vars/all.yml', 'vars/secrets.yml']],
            'file inventory without a source path' => ['site.yml', 'file', '', ['group_vars/all.yml', 'vars/secrets.yml']],
            'blank source path' => ['site.yml', 'dynamic', '  ', ['group_vars/all.yml', 'vars/secrets.yml']],
            'normalised paths' => ['./site.yml', 'file', '/inventories//prod/./hosts.yml', [
                'group_vars/all.yml',
                'inventories/prod/group_vars/all/vault.yml',
                'inventories/prod/host_vars/prod-web1.yml',
                'vars/secrets.yml',
            ]],
            'backslashes' => ['site.yml', 'file', 'inventories\\dev\\hosts.yml', ['group_vars/all.yml', 'inventories/dev/group_vars/all/vault.yml', 'vars/secrets.yml']],
            'paths leaving the checkout are dropped' => ['../site.yml', 'file', '../repo/inventories/prod/hosts.yml', []],
        ];
    }

    /**
     * @dataProvider fixtureTemplateProvider
     * @param list<string> $expected
     */
    public function testSelectsWhatAJobTemplateLoads(string $playbook, string $type, ?string $source, array $expected): void
    {
        $root = self::fixture('repo');
        $varsFiles = TemplateVaultFiles::playbookVarsFiles($root, $playbook);

        $this->assertSame($expected, TemplateVaultFiles::select($root, self::REPO_ENTRIES, $playbook, $type, $source, $varsFiles));
    }

    public function testAnInventoryFileItselfAndADirectorySourceWithEverythingInIt(): void
    {
        $root = $this->newTree();
        mkdir($root . '/inventories/prod', 0755, true);
        $entries = ['inventories/prod/hosts.yml', 'inventories/prod/extra/more.yml', 'inventories/prod2/hosts.yml', 'inventories/hosts.yml'];

        $this->assertSame(['inventories/prod/hosts.yml'], TemplateVaultFiles::select($root, $entries, 'site.yml', 'file', 'inventories/prod/hosts.yml', []));
        $this->assertSame(
            ['inventories/prod/hosts.yml', 'inventories/prod/extra/more.yml'],
            TemplateVaultFiles::select($root, $entries, 'site.yml', 'file', 'inventories/prod', [])
        );
        $this->assertSame($entries, TemplateVaultFiles::select($root, $entries, 'playbooks/site.yml', 'dynamic', '.', []), "'-i .' makes the checkout the inventory");
    }

    public function testPrefixesEndAtADirectoryBoundary(): void
    {
        $entries = [
            'group_vars2/all.yml',
            'host_vars_old/web1.yml',
            'xgroup_vars/all.yml',
            'group_vars',
            'inventories/prod2/group_vars/all.yml',
            'inventories/prod/group_vars_backup/all.yml',
            'inventories/prod/hosts.yml.bak',
            'vars/secrets.yml.bak',
        ];

        $this->assertSame([], TemplateVaultFiles::select($this->newTree(), $entries, 'site.yml', 'file', 'inventories/prod/hosts.yml', ['vars/secrets.yml']));
    }

    public function testVarsFilesAreNormalisedAndCannotLeaveTheCheckout(): void
    {
        $entries = ['vars/secrets.yml', 'secrets.yml'];

        $this->assertSame(['vars/secrets.yml'], TemplateVaultFiles::select($this->newTree(), $entries, 'pb/site.yml', 'static', null, ['./vars//secrets.yml', '../secrets.yml', '']));
    }

    /**
     * Regression: the scan lists files under their real path only, so a
     * template whose group_vars were a symlink inside the checkout (staging ->
     * prod) was checked as "no encrypted files" although Ansible loads them.
     */
    public function testCandidatesBehindASymlinkInsideTheCheckoutMatchTheirRealPath(): void
    {
        $root = $this->newTree();
        $outside = $this->newTree();
        $this->put($root, 'inventories/prod/group_vars/all/vault.yml', 'x');
        $this->put($root, 'inventories/prod/hosts.yml', 'x');
        $this->put($root, 'inventories/staging/hosts.yml', 'x');
        $this->put($root, 'shared/secrets.yml', 'x');
        $this->put($outside, 'group_vars/all.yml', 'x');
        mkdir($root . '/vars');
        mkdir($root . '/inventories/qa');
        symlink('../prod/group_vars', $root . '/inventories/staging/group_vars');
        symlink('prod', $root . '/inventories/mirror');
        symlink('../shared/secrets.yml', $root . '/vars/secrets.yml');
        symlink($outside . '/group_vars', $root . '/inventories/qa/group_vars');
        $entries = ['inventories/prod/group_vars/all/vault.yml', 'shared/secrets.yml', 'group_vars/all.yml'];

        $this->assertSame(
            ['inventories/prod/group_vars/all/vault.yml', 'shared/secrets.yml'],
            TemplateVaultFiles::select($root, $entries, 'pb/site.yml', 'file', 'inventories/staging/hosts.yml', ['vars/secrets.yml']),
            'a symlinked group_vars directory and a symlinked vars file'
        );
        $this->assertSame(
            ['inventories/prod/group_vars/all/vault.yml'],
            TemplateVaultFiles::select($root, $entries, 'pb/site.yml', 'file', 'inventories/mirror/hosts.yml', []),
            'an inventory directory reached through a symlink'
        );
        $this->assertSame(
            [],
            TemplateVaultFiles::select($root, $entries, 'pb/site.yml', 'file', 'inventories/qa/hosts.yml', []),
            'a symlink out of the checkout adds nothing'
        );
    }

    public function testKeepsTheOrderAndRepetitionsOfTheEntries(): void
    {
        $entries = ['vars/secrets.yml', 'group_vars/all.yml', 'other.yml', 'group_vars/all.yml'];

        $this->assertSame(
            ['vars/secrets.yml', 'group_vars/all.yml', 'group_vars/all.yml'],
            TemplateVaultFiles::select($this->newTree(), $entries, 'site.yml', 'static', null, ['vars/secrets.yml'])
        );
    }

    public function testReadsTheLiteralVarsFilesOfEveryPlay(): void
    {
        $root = $this->newTree();
        $this->put($root, 'playbooks/site.yml', <<<'YAML'
            ---
            - name: First
              hosts: all
              vars:
                token: !vault |
                  $ANSIBLE_VAULT;1.1;AES256
                  6162
              vars_files:
                - vars/a.yml
                - ../shared/b.yml
                - "{{ env }}.yml"
                - "vars/{% if prod %}prod{% endif %}.yml"
                - /etc/ansible/vars.yml
                - ~/vars.yml
                - ""
                - 42
                - null
                - [vars/first-choice.yml, "{{ fallback }}", vars/second-choice.yml]
                - ../../escapes.yml
                - vars/a.yml
            - name: Second
              hosts: all
              vars_files: vars/single.yml
            - name: Third
              hosts: all
            - import_playbook: other.yml
            - just a string
            YAML);

        $this->assertSame([
            'playbooks/vars/a.yml',
            'shared/b.yml',
            'playbooks/vars/first-choice.yml',
            'playbooks/vars/second-choice.yml',
            'playbooks/vars/single.yml',
        ], TemplateVaultFiles::playbookVarsFiles($root, 'playbooks/site.yml'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noPlaysProvider(): array
    {
        return [
            'a mapping, not plays' => ["hosts: all\nvars_files: [a.yml]\n"],
            'a scalar' => ["vars_files\n"],
            'empty file' => [''],
        ];
    }

    /**
     * @dataProvider noPlaysProvider
     */
    public function testAFileThatIsNoListOfPlaysHasNoVarsFiles(string $content): void
    {
        $root = $this->newTree();
        $this->put($root, 'site.yml', $content);

        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, 'site.yml'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidYamlProvider(): array
    {
        return [
            'unquoted Jinja' => ["- hosts: {{ target }}\n  vars_files: [a.yml]\n"],
            'duplicate keys' => ["- hosts: all\n  hosts: web\n  vars_files: [a.yml]\n"],
            'several documents' => ["- hosts: all\n  vars_files: [a.yml]\n---\n- hosts: web\n"],
        ];
    }

    /**
     * The playbook is read line by line: YAML errors elsewhere in the file
     * do not hide its vars_files (Ansible itself loads duplicate keys).
     *
     * @dataProvider invalidYamlProvider
     */
    public function testYamlErrorsElsewhereDoNotHideTheVarsFiles(string $content): void
    {
        $root = $this->newTree();
        $this->put($root, 'site.yml', $content);

        $this->assertSame(['a.yml'], TemplateVaultFiles::playbookVarsFiles($root, 'site.yml'));
    }

    /**
     * Regression: symfony/yaml parsed repository playbooks recursively, and
     * 20000 nested brackets made PHP crash with a segfault in the vault check
     * (the queue worker died on every sync of such a project). Runs in its own
     * process, so a crash fails this test only.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testADeeplyNestedPlaybookDoesNotCrashTheCheck(): void
    {
        $root = $this->newTree();
        $nested = str_repeat('[', 20000) . str_repeat(']', 20000);
        $this->put($root, 'site.yml', "- hosts: all\n  vars_files: [a.yml, {$nested}]\n  vars: {x: {$nested}}\n");

        $this->assertSame(['a.yml'], TemplateVaultFiles::playbookVarsFiles($root, 'site.yml'));
    }

    public function testOnlyAPlaybookFileInsideTheCheckoutIsRead(): void
    {
        $playbook = "- hosts: all\n  vars_files: [a.yml]\n";
        $outside = $this->newTree();
        $this->put($outside, 'site.yml', $playbook);
        $root = $this->newTree();
        $this->put($root, 'real/site.yml', $playbook);
        $this->put($root, 'large.yml', $playbook . '#' . str_repeat('-', 1000000) . "\n");
        symlink($outside . '/site.yml', $root . '/escape.yml');
        symlink($root . '/real/site.yml', $root . '/link.yml');

        $this->assertSame(['real/a.yml'], TemplateVaultFiles::playbookVarsFiles($root, 'real/site.yml'));
        $this->assertSame(['a.yml'], TemplateVaultFiles::playbookVarsFiles($root, 'link.yml'), 'resolved relative to the link, as Ansible does');
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, 'escape.yml'));
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, '../' . basename($outside) . '/site.yml'));
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, 'missing.yml'));
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, 'real'));
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, ''));
        $this->assertSame([], TemplateVaultFiles::playbookVarsFiles($root, 'large.yml'));
    }
}
