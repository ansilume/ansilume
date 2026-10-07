<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\VaultOverviewService;
use app\services\VaultScanService;
use app\tests\integration\DbTestCase;

/**
 * The vault overview of a project, shown on the project page and by
 * GET /api/v1/projects/{id}/vault: what the last scan found, how each job
 * template fits it and, in 'Ansilume only' mode, which runners are too old
 * to honour that setting. The stored scan summary is JSON the overview never
 * trusts: anything malformed in it reads as "nothing there".
 */
class VaultOverviewServiceTest extends DbTestCase
{
    private const DEV_PASSWORD = 'ansilume-test-dummy-dev';
    /** A manual project without a local path: never a checkout. */
    private const NO_CHECKOUT = 'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // -- Scan state -----------------------------------------------------------

    public function testANeverScannedProjectHasAnEmptyOverview(): void
    {
        $project = $this->project((int)$this->createUser('vault-overview')->id);

        $this->assertSame([
            'password_source' => Project::VAULT_SOURCE_ANSILUME,
            'password_source_label' => 'Ansilume only',
            'scanned_at' => null,
            'commit' => null,
            'error' => null,
            'truncated' => false,
            'files_scanned' => 0,
            'repository_settings' => null,
            'findings' => [],
            'vault_ids' => [],
            'entries' => [],
            'templates' => [],
            'runners_without_support' => [],
            'runners_without_support_count' => 0,
        ], $this->overview($project));
    }

    public function testAScanWithoutACheckoutShowsWhyAndNoEntries(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->project($userId, ['vault_password_source' => Project::VAULT_SOURCE_REPOSITORY]);
        $this->entry($project, 'vars/old.yml', ProjectVaultEntry::KIND_FILE);

        $this->assertFalse($this->scans()->scanProject($project));
        $overview = $this->overview($this->reload($project));

        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $overview['password_source']);
        $this->assertSame('Ansilume and repository', $overview['password_source_label']);
        $this->assertSame(self::NO_CHECKOUT, $overview['error']);
        $this->assertNull($overview['scanned_at']);
        $this->assertNull($overview['commit']);
        $this->assertNull($overview['repository_settings']);
        $this->assertSame([], $overview['entries'], 'the entries of the previous scan are gone');
        $this->assertSame([], $overview['vault_ids']);
    }

    public function testTheStoredScanIsPassedThrough(): void
    {
        $sha = str_repeat('ab12', 10);
        $project = $this->project((int)$this->createUser('vault-overview')->id, [
            'vault_scanned_at' => 1700000000,
            'vault_scan_commit' => $sha,
            'vault_scan_summary' => (string)json_encode([
                'cfg' => [
                    'vault_password_file' => 'scripts/vault-pass.sh',
                    'vault_identity_list' => 'prod@prod.pw',
                    'ask_vault_pass' => true,
                    'vault_id_match' => false,
                    'vault_encrypt_salt' => true,
                ],
                'findings' => [
                    ['code' => 'password_script', 'path' => 'scripts/vault-pass.sh', 'message' => 'Runs on runners.'],
                    ['code' => 'truncated', 'path' => null, 'message' => 'The scan stopped at a limit.'],
                ],
                'truncated' => true,
                'files_scanned' => 20000,
                'entries' => 0,
            ]),
        ]);

        $overview = $this->overview($project);

        $this->assertSame(1700000000, $overview['scanned_at']);
        $this->assertSame($sha, $overview['commit']);
        $this->assertNull($overview['error']);
        $this->assertTrue($overview['truncated']);
        $this->assertSame(20000, $overview['files_scanned']);
        $this->assertSame([
            'vault_password_file' => 'scripts/vault-pass.sh',
            'vault_identity_list' => 'prod@prod.pw',
            'ask_vault_pass' => true,
            'vault_id_match' => false,
            'vault_encrypt_salt' => true,
        ], $overview['repository_settings']);
        $this->assertSame([
            ['code' => 'password_script', 'path' => 'scripts/vault-pass.sh', 'message' => 'Runs on runners.'],
            ['code' => 'truncated', 'path' => null, 'message' => 'The scan stopped at a limit.'],
        ], $overview['findings']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableSummaryProvider(): array
    {
        return [
            'not JSON' => ['{"cfg": {"vault_password_file": ".vault_pass"'],
            'a JSON number' => ['42'],
            'a JSON string' => ['"summary"'],
            'a JSON list' => ['[1, 2, 3]'],
            'mistyped values' => ['{"cfg": "ansible.cfg", "findings": "none", "truncated": "yes", "files_scanned": "12"}'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider unusableSummaryProvider
     */
    public function testAnUnusableSummaryReadsAsNothingFound(string $summary): void
    {
        $project = $this->project((int)$this->createUser('vault-overview')->id, [
            'vault_scanned_at' => 1700000000,
            'vault_scan_summary' => $summary,
        ]);

        $overview = $this->overview($project);

        $this->assertSame(1700000000, $overview['scanned_at'], 'the scan itself still shows');
        $this->assertFalse($overview['truncated']);
        $this->assertSame(0, $overview['files_scanned']);
        $this->assertNull($overview['repository_settings']);
        $this->assertSame([], $overview['findings']);
    }

    public function testOnlyWellFormedFindingsAreShown(): void
    {
        $project = $this->scannedProject([
            'findings' => [
                ['code' => 'password_file_committed', 'path' => '.vault_pass', 'message' => 'Committed.', 'secret' => 'x'],
                'not a finding',
                7,
                null,
                ['path' => 'a.yml', 'message' => 'no code'],
                ['code' => 'malformed', 'path' => 'b.yml'],
                ['code' => ['malformed'], 'path' => 'c.yml', 'message' => 'code is no string'],
                ['code' => 'malformed', 'path' => 'd.yml', 'message' => ['not', 'a', 'string']],
                ['code' => 'ask_vault_pass', 'path' => 5, 'message' => 'Asks.'],
                ['code' => 'truncated', 'message' => 'Stopped.'],
            ],
        ]);

        $this->assertSame([
            ['code' => 'password_file_committed', 'path' => '.vault_pass', 'message' => 'Committed.'],
            ['code' => 'ask_vault_pass', 'path' => null, 'message' => 'Asks.'],
            ['code' => 'truncated', 'path' => null, 'message' => 'Stopped.'],
        ], $this->overview($project)['findings']);
    }

    public function testRepositorySettingsAreNormalised(): void
    {
        $project = $this->scannedProject([
            'cfg' => [
                'vault_password_file' => '',
                'vault_identity_list' => 'dev@dev.pw',
                'ask_vault_pass' => 'yes',
                'vault_id_match' => true,
                'vault_encrypt_salt' => 1,
                'unrelated' => 'dropped',
            ],
        ]);

        $this->assertSame([
            'vault_password_file' => null,
            'vault_identity_list' => 'dev@dev.pw',
            'ask_vault_pass' => false,
            'vault_id_match' => true,
            'vault_encrypt_salt' => false,
        ], $this->overview($project)['repository_settings']);
    }

    /**
     * An ansible.cfg without vault settings is something the scan saw, not a
     * missing scan: the settings show, all off.
     */
    public function testAnEmptyAnsibleCfgShowsEverySettingOff(): void
    {
        $project = $this->scannedProject(['cfg' => []]);

        $this->assertSame([
            'vault_password_file' => null,
            'vault_identity_list' => null,
            'ask_vault_pass' => false,
            'vault_id_match' => false,
            'vault_encrypt_salt' => false,
        ], $this->overview($project)['repository_settings']);
    }

    // -- Entries and vault IDs ------------------------------------------------

    public function testEntriesAreListedByPathAndLine(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->scannedProject([]);
        $other = $this->project($userId);
        $this->entry($project, 'vars/secrets.yml', ProjectVaultEntry::KIND_FILE, null, null, null, '1.1');
        $this->entry($project, 'group_vars/all.yml', ProjectVaultEntry::KIND_INLINE, 12, 'db_password', null, '1.1');
        $this->entry($project, 'broken.yml', ProjectVaultEntry::KIND_FILE, null, null, null, null, 'Vault format unhexlify error: Odd-length string');
        $this->entry($project, 'group_vars/all.yml', ProjectVaultEntry::KIND_INLINE, 3, 'api_token', 'prod', '1.2');
        $this->entry($other, 'aaa-other-project.yml', ProjectVaultEntry::KIND_FILE);

        $this->assertSame([
            ['path' => 'broken.yml', 'kind' => 'file', 'line' => null, 'key' => null, 'vault_id' => null, 'format_version' => null, 'error' => 'Vault format unhexlify error: Odd-length string'],
            ['path' => 'group_vars/all.yml', 'kind' => 'inline', 'line' => 3, 'key' => 'api_token', 'vault_id' => 'prod', 'format_version' => '1.2', 'error' => null],
            ['path' => 'group_vars/all.yml', 'kind' => 'inline', 'line' => 12, 'key' => 'db_password', 'vault_id' => null, 'format_version' => '1.1', 'error' => null],
            ['path' => 'vars/secrets.yml', 'kind' => 'file', 'line' => null, 'key' => null, 'vault_id' => null, 'format_version' => '1.1', 'error' => null],
        ], $this->overview($this->reload($project))['entries']);
    }

    /**
     * Format 1.1 entries have no vault ID (Ansible's 'default'): only real
     * IDs are listed, each once, sorted.
     */
    public function testVaultIdsAreDistinctAndSorted(): void
    {
        $project = $this->scannedProject([]);
        $this->entry($project, 'a.yml', ProjectVaultEntry::KIND_FILE, null, null, 'stage', '1.2');
        $this->entry($project, 'b.yml', ProjectVaultEntry::KIND_FILE, null, null, null, '1.1');
        $this->entry($project, 'c.yml', ProjectVaultEntry::KIND_FILE, null, null, 'dev', '1.2');
        $this->entry($project, 'd.yml', ProjectVaultEntry::KIND_FILE, null, null, 'stage', '1.2');
        $this->entry($project, 'e.yml', ProjectVaultEntry::KIND_FILE, null, null, '', '1.2');
        $this->entry($project, 'f.yml', ProjectVaultEntry::KIND_INLINE, 4, 'k', 'prod', '1.2');

        $this->assertSame(['dev', 'prod', 'stage'], $this->overview($this->reload($project))['vault_ids']);
    }

    // -- Templates ------------------------------------------------------------

    public function testTemplatesAreListedByNameWithTheirCheck(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->scannedProject([]);
        $prefix = 'ovw-' . uniqid() . '-';
        $vault = $this->vaultCredential($userId, self::DEV_PASSWORD);
        $charlie = $this->template($userId, $project, $prefix . 'charlie');
        $alpha = $this->template($userId, $project, $prefix . 'alpha');
        $bravo = $this->template($userId, $project, $prefix . 'bravo');
        $deleted = $this->template($userId, $project, $prefix . 'aaa-deleted');
        $foreign = $this->template($userId, $this->project($userId), $prefix . 'aaa-other-project');
        $unopened = [
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null],
            ['path' => 'inventories/prod/host_vars/web1.yml', 'line' => 2, 'key' => 'host_secret'],
        ];
        // 45 unopened, of which the first are stored.
        $this->check($bravo, JobTemplateVaultCheck::STATUS_MISMATCH, $vault, 50, (string)json_encode($unopened), 1700000100, 45);
        // Only {path, line, key} items with a string path survive decoding.
        $this->check($charlie, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 2, '[{"path": 5}, "x", {"path": "a.yml", "line": "3", "key": 7}]', 1700000200);
        $this->check($deleted, JobTemplateVaultCheck::STATUS_OK, $vault, 1, null, 1700000300);
        $this->check($foreign, JobTemplateVaultCheck::STATUS_OK, $vault, 1, null, 1700000400);
        $this->assertTrue($deleted->softDelete());

        $this->assertSame([
            [
                'id' => (int)$alpha->id,
                'name' => $prefix . 'alpha',
                'status' => null,
                'incomplete_reason' => null,
                'credential' => null,
                'relevant_count' => 0,
                'unopened_count' => 0,
                'unopened' => [],
                'checked_at' => null,
            ],
            [
                'id' => (int)$bravo->id,
                'name' => $prefix . 'bravo',
                'status' => JobTemplateVaultCheck::STATUS_MISMATCH,
                'incomplete_reason' => null,
                'credential' => ['id' => (int)$vault->id, 'name' => $vault->name],
                'relevant_count' => 50,
                'unopened_count' => 45,
                'unopened' => array_map(static fn (array $entry): array => $entry + ['reason' => null], $unopened),
                'checked_at' => 1700000100,
            ],
            [
                'id' => (int)$charlie->id,
                'name' => $prefix . 'charlie',
                'status' => JobTemplateVaultCheck::STATUS_MISSING_PASSWORD,
                'incomplete_reason' => null,
                'credential' => null,
                'relevant_count' => 2,
                'unopened_count' => 0,
                'unopened' => [['path' => 'a.yml', 'line' => null, 'key' => null, 'reason' => null]],
                'checked_at' => 1700000200,
            ],
        ], $this->overview($project)['templates']);
    }

    // -- Runners without support ----------------------------------------------

    /**
     * In 'Ansilume only' mode the overview names the runners that would
     * apply the repository's vault settings anyway: those of the runner
     * groups the project's templates use that do not report the capability.
     */
    public function testRunnersWithoutTheCapabilityAreListedInAnsilumeMode(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->project($userId);
        $prefix = 'ovw-' . uniqid() . '-';
        $first = $this->createRunnerGroup($userId);
        $second = $this->createRunnerGroup($userId);
        $foreignGroup = $this->createRunnerGroup($userId);
        $deletedGroup = $this->createRunnerGroup($userId);
        $this->template($userId, $project, $prefix . 't1', $first);
        $this->template($userId, $project, $prefix . 't2', $second);
        $this->template($userId, $project, $prefix . 't3', $first);
        $ungrouped = $this->template($userId, $project, $prefix . 't4', $first);
        $ungrouped->runner_group_id = null;
        $ungrouped->save(false);
        $this->template($userId, $this->project($userId), $prefix . 'foreign', $foreignGroup);
        $this->assertTrue($this->template($userId, $project, $prefix . 'deleted', $deletedGroup)->softDelete());

        $old = $this->runner($first, $userId, $prefix . 'b-old', null, '2.7.0');
        $this->runner($first, $userId, $prefix . 'a-new', Runner::CAPABILITY_VAULT_PASSWORD_SOURCE, '2.8.0');
        $unknownOnly = $this->runner($first, $userId, $prefix . 'c-unknown-capability', 'something_else', null);
        $this->runner($second, $userId, $prefix . 'd-listed-among-others', 'other, vault_password_source', '2.8.1');
        $empty = $this->runner($second, $userId, $prefix . 'a-empty', '', '2.6.3');
        $this->runner($foreignGroup, $userId, $prefix . 'a-foreign-group', null, '2.0.0');
        $this->runner($deletedGroup, $userId, $prefix . 'a-deleted-template-group', null, '2.0.0');

        $this->assertSame([
            ['id' => (int)$empty->id, 'name' => $prefix . 'a-empty', 'group' => $second->name, 'software_version' => '2.6.3'],
            ['id' => (int)$old->id, 'name' => $prefix . 'b-old', 'group' => $first->name, 'software_version' => '2.7.0'],
            ['id' => (int)$unknownOnly->id, 'name' => $prefix . 'c-unknown-capability', 'group' => $first->name, 'software_version' => null],
        ], $this->overview($this->reload($project))['runners_without_support']);
    }

    /**
     * In 'Ansilume and repository' mode every runner applies the repository's
     * settings by design, so none is out of line.
     */
    public function testRepositoryModeListsNoRunners(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->project($userId, ['vault_password_source' => Project::VAULT_SOURCE_REPOSITORY]);
        $group = $this->createRunnerGroup($userId);
        $this->template($userId, $project, 'ovw-' . uniqid(), $group);
        $this->runner($group, $userId, 'ovw-old-' . uniqid(), null, '2.7.0');

        $this->assertSame([], $this->overview($project)['runners_without_support']);
    }

    public function testAProjectWithoutTemplatesListsNoRunners(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->project($userId);
        $this->runner($this->createRunnerGroup($userId), $userId, 'ovw-old-' . uniqid(), null, '2.7.0');

        $this->assertSame([], $this->overview($project)['runners_without_support']);
    }

    // -- What the viewer may see ----------------------------------------------

    /**
     * Regression: the overview showed runner names, groups and versions and
     * the names of vault passwords to anyone with project.view. Those details
     * now follow runner-group.view and credential.view or job-template.view.
     */
    public function testDetailsFollowThePermissionsOfTheViewer(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->scannedProject([]);
        $group = $this->createRunnerGroup($userId);
        $vault = $this->vaultCredential($userId, self::DEV_PASSWORD);
        $template = $this->template($userId, $project, 'ovw-' . uniqid(), $group);
        $this->check($template, JobTemplateVaultCheck::STATUS_OK, $vault, 1, null, 1700000100);
        $this->runner($group, $userId, 'ovw-old-' . uniqid(), null, '2.7.0');
        $this->runner($group, $userId, 'ovw-older-' . uniqid(), null, '2.6.0');

        $everything = $this->overview($project);
        $projectOnly = $this->overview($project, ['runner-group.view', 'credential.view', 'job-template.view']);
        $templatesOnly = $this->overview($project, ['credential.view']);

        $this->assertCount(2, $everything['runners_without_support']);
        $this->assertSame(2, $everything['runners_without_support_count']);
        $this->assertSame(['id' => (int)$vault->id, 'name' => $vault->name], $everything['templates'][0]['credential']);
        $this->assertSame([], $projectOnly['runners_without_support'], 'no runner names, groups or versions');
        $this->assertSame(2, $projectOnly['runners_without_support_count'], 'but how many there are');
        $this->assertSame(['id' => (int)$vault->id, 'name' => null], $projectOnly['templates'][0]['credential']);
        $this->assertSame(['id' => (int)$vault->id, 'name' => $vault->name], $templatesOnly['templates'][0]['credential'], 'job-template.view shows the names, as the template page does');
        $this->assertStringNotContainsString($vault->name, (string)json_encode($projectOnly));
        $this->assertStringNotContainsString('2.7.0', (string)json_encode($projectOnly));
    }

    // -- Checks that are not current or not complete ----------------------------

    /**
     * Regression: a check from an older scan (a run cut short, or racing a
     * rescan) was shown as current. It reads as stale, without its details.
     */
    public function testACheckFromAnOlderScanIsStaleWithoutDetails(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->scannedProject([]);
        $template = $this->template($userId, $project, 'ovw-' . uniqid());
        $this->check($template, JobTemplateVaultCheck::STATUS_MISMATCH, null, 3, (string)json_encode([['path' => 'a.yml', 'line' => null, 'key' => null]]), 1700000100, 1);
        JobTemplateVaultCheck::updateAll(['scanned_at' => 1699999000], ['job_template_id' => $template->id]);

        $row = $this->overview($project)['templates'][0];

        $this->assertSame(JobTemplateVaultCheck::STATUS_STALE, $row['status']);
        $this->assertSame(0, $row['unopened_count']);
        $this->assertSame([], $row['unopened']);
        $this->assertSame(1700000100, $row['checked_at']);
    }

    public function testAnIncompleteCheckCarriesItsReason(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $project = $this->scannedProject([]);
        $template = $this->template($userId, $project, 'ovw-' . uniqid());
        $this->check($template, JobTemplateVaultCheck::STATUS_INCOMPLETE, null, 2, null, 1700000100);
        JobTemplateVaultCheck::updateAll(['incomplete_reason' => JobTemplateVaultCheck::REASON_TIME_LIMIT], ['job_template_id' => $template->id]);

        $row = $this->overview($project)['templates'][0];

        $this->assertSame(JobTemplateVaultCheck::STATUS_INCOMPLETE, $row['status']);
        $this->assertSame(JobTemplateVaultCheck::REASON_TIME_LIMIT, $row['incomplete_reason']);
    }

    // -- End to end -----------------------------------------------------------

    /**
     * A real scan of the fixture repository (tests/fixtures/vault/repo, see
     * its README) followed by the overview, as after a sync: the dev
     * password opens what the dev inventory loads, not the prod vaults.
     */
    public function testAScannedCheckoutShowsWhatTheScanFoundAndHowTemplatesFit(): void
    {
        $userId = (int)$this->createUser('vault-overview')->id;
        $root = $this->copyFixtureRepo();
        $project = $this->project($userId, ['local_path' => $root]);
        $group = $this->createRunnerGroup($userId);
        $dev = $this->fileInventory($userId, $project, 'inventories/dev/hosts.yml');
        $prod = $this->fileInventory($userId, $project, 'inventories/prod/hosts.yml');
        $vault = $this->vaultCredential($userId, self::DEV_PASSWORD);
        $prefix = 'ovw-' . uniqid() . '-';
        $alpha = $this->template($userId, $project, $prefix . 'alpha-dev', $group, $dev, $vault);
        $bravo = $this->template($userId, $project, $prefix . 'bravo-prod', $group, $prod, $vault);
        $charlie = $this->template($userId, $project, $prefix . 'charlie-no-password', $group, $dev);
        $outdated = $this->runner($group, $userId, $prefix . 'runner', null, '2.7.0');
        $before = time();

        $this->assertTrue($this->scans()->scanProject($project));
        $overview = $this->overview($this->reload($project));

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $overview['password_source']);
        $this->assertNotNull($overview['scanned_at']);
        $this->assertGreaterThanOrEqual($before, $overview['scanned_at']);
        $this->assertNull($overview['commit'], 'the copy has no .git directory');
        $this->assertNull($overview['error']);
        $this->assertFalse($overview['truncated']);
        $this->assertSame($this->countFiles($root), $overview['files_scanned']);
        $this->assertSame([
            'vault_password_file' => '.vault_pass',
            'vault_identity_list' => null,
            'ask_vault_pass' => true,
            'vault_id_match' => true,
            'vault_encrypt_salt' => true,
        ], $overview['repository_settings']);
        $this->assertSame(
            ['password_file_committed', 'ask_vault_pass', 'vault_id_match', 'encrypt_salt'],
            array_column($overview['findings'], 'code')
        );
        $this->assertSame('.vault_pass', $overview['findings'][0]['path']);
        $this->assertSame(['prod'], $overview['vault_ids']);
        $this->assertSame([
            ['group_vars/all.yml', 'inline', 3, 'app_secret', null],
            ['inventories/dev/group_vars/all/vault.yml', 'file', null, null, null],
            ['inventories/prod/group_vars/all/vault.yml', 'file', null, null, 'prod'],
            ['inventories/prod/host_vars/prod-web1.yml', 'inline', 2, 'host_secret', 'prod'],
            ['playbooks/deploy-vars.yml', 'file', null, null, null],
            ['playbooks/group_vars/web.yml', 'file', null, null, null],
            ['vars/secrets.yml', 'file', null, null, null],
        ], array_map(static fn (array $entry): array => [$entry['path'], $entry['kind'], $entry['line'], $entry['key'], $entry['vault_id']], $overview['entries']));

        $templates = $overview['templates'];
        $this->assertSame([(int)$alpha->id, (int)$bravo->id, (int)$charlie->id], array_column($templates, 'id'));
        $this->assertSame(
            [JobTemplateVaultCheck::STATUS_OK, JobTemplateVaultCheck::STATUS_MISMATCH, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD],
            array_column($templates, 'status')
        );
        $this->assertSame([3, 4, 3], array_column($templates, 'relevant_count'));
        $this->assertSame(['id' => (int)$vault->id, 'name' => $vault->name], $templates[0]['credential']);
        $this->assertSame(['id' => (int)$vault->id, 'name' => $vault->name], $templates[1]['credential']);
        $this->assertNull($templates[2]['credential']);
        $this->assertSame([], $templates[0]['unopened']);
        $this->assertSame([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ], $templates[1]['unopened']);
        foreach ($templates as $row) {
            $this->assertIsInt($row['checked_at']);
            $this->assertGreaterThanOrEqual($before, $row['checked_at']);
        }
        $this->assertSame(
            [['id' => (int)$outdated->id, 'name' => $prefix . 'runner', 'group' => $group->name, 'software_version' => '2.7.0']],
            $overview['runners_without_support']
        );
        $this->assertSame(1, $overview['runners_without_support_count']);
        $this->assertStringNotContainsString(self::DEV_PASSWORD, (string)json_encode($overview), 'nothing secret in the overview');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The overview for a viewer who holds every permission but $denied.
     *
     * @param list<string> $denied
     * @return array<string, mixed>
     */
    private function overview(Project $project, array $denied = []): array
    {
        /** @var VaultOverviewService $service */
        $service = \Yii::$app->get('vaultOverviewService');

        return $service->forProject($project, static fn (string $permission): bool => !in_array($permission, $denied, true));
    }

    private function scans(): VaultScanService
    {
        /** @var VaultScanService $service */
        $service = \Yii::$app->get('vaultScanService');

        return $service;
    }

    /**
     * A manual project as the controllers load it: from the database, with
     * its column defaults.
     *
     * @param array<string, mixed> $attributes
     */
    private function project(int $userId, array $attributes = []): Project
    {
        $project = $this->createProject($userId);
        if ($attributes !== []) {
            $project->setAttributes($attributes, false);
            $project->save(false);
        }

        return $this->reload($project);
    }

    /**
     * A project with a stored scan summary.
     *
     * @param array<string, mixed> $summary
     */
    private function scannedProject(array $summary): Project
    {
        return $this->project((int)$this->createUser('vault-overview')->id, [
            'vault_scanned_at' => 1700000000,
            'vault_scan_summary' => (string)json_encode($summary),
        ]);
    }

    private function reload(Project $project): Project
    {
        $fresh = Project::findOne($project->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function entry(
        Project $project,
        string $path,
        string $kind,
        ?int $line = null,
        ?string $key = null,
        ?string $vaultId = null,
        ?string $formatVersion = '1.1',
        ?string $error = null
    ): ProjectVaultEntry {
        $entry = new ProjectVaultEntry();
        $entry->project_id = (int)$project->id;
        $entry->path = $path;
        $entry->kind = $kind;
        $entry->line = $line;
        $entry->var_key = $key;
        $entry->vault_id = $vaultId;
        $entry->format_version = $formatVersion;
        $entry->fingerprint = $error === null ? hash('sha256', $path . ':' . $line) : null;
        $entry->error = $error;
        $entry->save(false);

        return $entry;
    }

    private function template(
        int $userId,
        Project $project,
        string $name,
        ?RunnerGroup $group = null,
        ?Inventory $inventory = null,
        ?Credential $credential = null
    ): JobTemplate {
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)($inventory ?? $this->createInventory($userId))->id,
            (int)($group ?? $this->createRunnerGroup($userId))->id,
            $userId
        );
        $template->name = $name;
        $template->credential_id = $credential === null ? null : (int)$credential->id;
        $template->save(false);

        return $template;
    }

    private function check(JobTemplate $template, string $status, ?Credential $credential, int $relevant, ?string $unopened, int $checkedAt, int $unopenedCount = 0): void
    {
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = (int)$template->id;
        $check->status = $status;
        $check->credential_id = $credential === null ? null : (int)$credential->id;
        $check->relevant_count = $relevant;
        $check->unopened_count = $unopenedCount;
        $check->unopened = $unopened;
        $check->checked_at = $checkedAt;
        $check->scanned_at = 1700000000;
        $check->save(false);
    }

    private function runner(RunnerGroup $group, int $userId, string $name, ?string $capabilities, ?string $version): Runner
    {
        $runner = $this->createRunner((int)$group->id, $userId);
        $runner->name = $name;
        $runner->capabilities = $capabilities;
        $runner->software_version = $version;
        $runner->save(false);

        return $runner;
    }

    private function vaultCredential(int $userId, string $password): Credential
    {
        $credential = $this->createCredential($userId, Credential::TYPE_VAULT);
        /** @var \app\services\CredentialService $service */
        $service = \Yii::$app->get('credentialService');
        $credential->secret_data = $service->encryptSecrets(['vault_password' => $password]);
        $credential->save(false);

        return $credential;
    }

    private function fileInventory(int $userId, Project $project, string $sourcePath): Inventory
    {
        $inventory = $this->createInventory($userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->content = null;
        $inventory->source_path = $sourcePath;
        $inventory->project_id = (int)$project->id;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * A private copy of the fixture repository; tests never write into
     * tests/fixtures.
     */
    private function copyFixtureRepo(): string
    {
        $target = sys_get_temp_dir() . '/ansilume-vault-overview-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $target;
        $this->copyTree(dirname(__DIR__, 2) . '/fixtures/vault/repo', $target);

        return $target;
    }

    private function copyTree(string $source, string $target): void
    {
        mkdir($target, 0755, true);
        foreach ((array)scandir($source) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $source . '/' . $name;
            if (is_dir($from)) {
                $this->copyTree($from, $target . '/' . $name);
            } else {
                copy($from, $target . '/' . $name);
            }
        }
    }

    private function countFiles(string $dir): int
    {
        $count = 0;
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $count += is_dir($dir . '/' . $name) ? $this->countFiles($dir . '/' . $name) : 1;
        }

        return $count;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
