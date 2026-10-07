<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\vault\VaultContentScanner;
use app\components\vault\VaultFindings;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\services\ProjectService;
use app\services\VaultCheckService;
use app\services\VaultScanService;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\vault\TemporaryTree;

/**
 * VaultScanService on copies of the committed fixture repository
 * (tests/fixtures/vault/repo, see its README): what a scan stores, what it
 * replaces, and what happens without a checkout.
 */
class VaultScanServiceTest extends DbTestCase
{
    use TemporaryTree;

    private const DEV_PASSWORD = 'ansilume-test-dummy-dev';
    private const NO_LOCAL_PATH = 'The local path is not a directory the server can read: ';
    private const LOCAL_PATH_UNSET = 'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.';
    private const SHA1 = '0123456789abcdef0123456789abcdef01234567';

    private VaultScanService $service;
    private int $userId;

    /** @var array<string, mixed> application components replaced by a test, restored in tearDown() */
    private array $replaced = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('vaultScanService');
        $this->userId = (int)$this->createUser('vaultscan')->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        $this->removeTrees();
        parent::tearDown();
    }

    // -- helpers ---------------------------------------------------------------

    private function replaceComponent(string $id, mixed $definition): void
    {
        if (!array_key_exists($id, $this->replaced)) {
            $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $definition);
    }

    /**
     * Copies a file or directory of tests/fixtures/vault to $target.
     */
    private function copyFixture(string $relative, string $target): void
    {
        $source = self::fixture($relative);
        if (!is_dir($source)) {
            copy($source, $target);
            chmod($target, 0644);

            return;
        }
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $name) {
            $this->copyFixture($relative . '/' . $name, $target . '/' . $name);
        }
    }

    /**
     * A temporary copy of the fixture repository.
     */
    private function fixtureCheckout(): string
    {
        $root = $this->newTree();
        $this->copyFixture('repo', $root);

        return $root;
    }

    private function manualProject(?string $localPath): Project
    {
        $project = $this->createProject($this->userId);
        $project->local_path = $localPath;
        $project->save(false);

        return $project;
    }

    private function vaultCredential(string $password): Credential
    {
        $credential = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $credential->secret_data = \Yii::$app->get('credentialService')->encryptSecrets(['vault_password' => $password]);
        $credential->save(false);

        return $credential;
    }

    /**
     * A template running site.yml against inventories/dev/hosts.yml of the
     * project's checkout.
     */
    private function devTemplate(Project $project, ?Credential $vault): JobTemplate
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = $project->id;
        $inventory->source_path = 'inventories/dev/hosts.yml';
        $inventory->content = null;
        $inventory->save(false);
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $template->credential_id = $vault?->id;
        $template->save(false);

        return $template;
    }

    /**
     * The project's stored entries, ordered by path, then line.
     *
     * @return list<array{path: string, kind: string, line: int|null, key: string|null, vault_id: string|null, format: string|null, fingerprint: string|null, error: string|null}>
     */
    private static function storedEntries(Project $project): array
    {
        $rows = array_map(static fn (ProjectVaultEntry $entry): array => [
            'path' => $entry->path,
            'kind' => $entry->kind,
            'line' => $entry->line,
            'key' => $entry->var_key,
            'vault_id' => $entry->vault_id,
            'format' => $entry->format_version,
            'fingerprint' => $entry->fingerprint,
            'error' => $entry->error,
        ], ProjectVaultEntry::find()->where(['project_id' => $project->id])->all());
        usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']) ?: ($a['line'] ?? 0) <=> ($b['line'] ?? 0));

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function storedPaths(Project $project): array
    {
        return array_column(self::storedEntries($project), 'path');
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Project $project): array
    {
        $project->refresh();
        $summary = json_decode((string)$project->vault_scan_summary, true);
        self::assertIsArray($summary);

        return $summary;
    }

    /**
     * The fingerprint of a vault text, computed without the production
     * parser: sha256 of the hex-decoded salt, HMAC and ciphertext.
     */
    private static function fingerprintOf(string $vault): string
    {
        $lines = array_map('trim', explode("\n", str_replace("\r\n", "\n", trim($vault))));
        $body = (string)hex2bin(implode('', array_slice($lines, 1)));
        $parts = array_map(static fn (string $hex): string => (string)hex2bin($hex), explode("\n", $body, 3));

        return hash('sha256', implode('', $parts));
    }

    /**
     * The text of the inline vault whose tag is on line $line (1-based):
     * the indented lines below it.
     */
    private static function inlineVault(string $yaml, int $line): string
    {
        $vault = [];
        foreach (array_slice(explode("\n", $yaml), $line) as $text) {
            if ($text === '' || $text[0] !== ' ') {
                break;
            }
            $vault[] = trim($text);
        }

        return implode("\n", $vault);
    }

    private static function fileFingerprint(string $root, string $path): string
    {
        return self::fingerprintOf((string)file_get_contents($root . '/' . $path));
    }

    private static function inlineFingerprint(string $root, string $path, int $line): string
    {
        return self::fingerprintOf(self::inlineVault((string)file_get_contents($root . '/' . $path), $line));
    }

    /**
     * The 1.1 fixture vault as an indented block below `<key>: !vault |`.
     */
    private static function inlineValue(string $key): string
    {
        $lines = explode("\n", rtrim(self::fixtureContent('file-1.1.yml')));

        return $key . ": !vault |\n  " . implode("\n  ", $lines) . "\n";
    }

    // -- scanning --------------------------------------------------------------

    public function testAScanOfAManualProjectStoresEveryVaultEntryOfItsCheckout(): void
    {
        $root = $this->fixtureCheckout();
        $project = $this->manualProject($root);

        $this->assertTrue($this->service->scanProject($project));

        $this->assertSame([
            [
                'path' => 'group_vars/all.yml', 'kind' => 'inline', 'line' => 3, 'key' => 'app_secret',
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::inlineFingerprint($root, 'group_vars/all.yml', 3), 'error' => null,
            ],
            [
                'path' => 'inventories/dev/group_vars/all/vault.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::fileFingerprint($root, 'inventories/dev/group_vars/all/vault.yml'), 'error' => null,
            ],
            [
                'path' => 'inventories/prod/group_vars/all/vault.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => 'prod', 'format' => '1.2',
                'fingerprint' => self::fileFingerprint($root, 'inventories/prod/group_vars/all/vault.yml'), 'error' => null,
            ],
            [
                'path' => 'inventories/prod/host_vars/prod-web1.yml', 'kind' => 'inline', 'line' => 2, 'key' => 'host_secret',
                'vault_id' => 'prod', 'format' => '1.2',
                'fingerprint' => self::inlineFingerprint($root, 'inventories/prod/host_vars/prod-web1.yml', 2), 'error' => null,
            ],
            [
                'path' => 'playbooks/deploy-vars.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::fileFingerprint($root, 'playbooks/deploy-vars.yml'), 'error' => null,
            ],
            [
                'path' => 'playbooks/group_vars/web.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::fileFingerprint($root, 'playbooks/group_vars/web.yml'), 'error' => null,
            ],
            [
                'path' => 'vars/secrets.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::fileFingerprint($root, 'vars/secrets.yml'), 'error' => null,
            ],
        ], self::storedEntries($project));
    }

    public function testAScanStoresTheAnsibleCfgSettingsFindingsAndLimitsAsTheSummary(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $before = time();

        $this->service->scanProject($project);

        $summary = self::summary($project);
        $this->assertSame([
            'vault_password_file' => '.vault_pass',
            'vault_identity_list' => null,
            'ask_vault_pass' => true,
            'vault_id_match' => true,
            'vault_encrypt_salt' => true,
        ], $summary['cfg']);
        $this->assertSame([
            ['code' => VaultFindings::PASSWORD_FILE_COMMITTED, 'path' => '.vault_pass'],
            ['code' => VaultFindings::ASK_VAULT_PASS, 'path' => 'ansible.cfg'],
            ['code' => VaultFindings::VAULT_ID_MATCH, 'path' => 'ansible.cfg'],
            ['code' => VaultFindings::ENCRYPT_SALT, 'path' => 'ansible.cfg'],
        ], array_map(static fn (array $finding): array => ['code' => $finding['code'], 'path' => $finding['path']], $summary['findings']));
        $this->assertFalse($summary['truncated']);
        $this->assertSame(16, $summary['files_scanned']);
        $this->assertSame(7, $summary['entries']);
        $this->assertGreaterThanOrEqual($before, (int)$project->vault_scanned_at);
        $this->assertLessThanOrEqual(time(), (int)$project->vault_scanned_at);
        $this->assertNull($project->vault_scan_commit, 'the checkout has no .git directory');
        $this->assertNull($project->vault_scan_error);
        // The committed .vault_pass holds the dev password: the scan reports
        // the file, never its content.
        $this->assertStringNotContainsString(self::DEV_PASSWORD, (string)$project->vault_scan_summary);
    }

    public function testMalformedEntriesAreStoredWithTheReasonAndBecomeFindings(): void
    {
        $root = $this->newTree();
        $this->put($root, 'group_vars/all/good.yml', self::fixtureContent('file-1.1.yml'));
        $this->put($root, 'group_vars/all/broken.yml', self::fixtureContent('malformed-trailing-space.yml'));
        $this->put($root, 'group_vars/all/bom.yml', "\xEF\xBB\xBF" . self::fixtureContent('file-1.1.yml'));
        $this->put($root, 'host_vars/web1.yml', "---\nbroken_inline: !vault |\n  \$ANSIBLE_VAULT;1.1;AES256\n");
        $project = $this->manualProject($root);

        $this->assertTrue($this->service->scanProject($project));

        $bom = 'ansible does not read it as vault data: it starts with a UTF-8 byte order mark';
        $trailing = 'the vault body has spaces or tabs, e.g. trailing whitespace on a line';
        $noBody = 'the vault has a header but no body';
        $this->assertSame([
            [
                'path' => 'group_vars/all/bom.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => null, 'fingerprint' => null, 'error' => $bom,
            ],
            [
                'path' => 'group_vars/all/broken.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => null, 'fingerprint' => null, 'error' => $trailing,
            ],
            [
                'path' => 'group_vars/all/good.yml', 'kind' => 'file', 'line' => null, 'key' => null,
                'vault_id' => null, 'format' => '1.1',
                'fingerprint' => self::fingerprintOf(self::fixtureContent('file-1.1.yml')), 'error' => null,
            ],
            [
                'path' => 'host_vars/web1.yml', 'kind' => 'inline', 'line' => 2, 'key' => 'broken_inline',
                'vault_id' => null, 'format' => null, 'fingerprint' => null, 'error' => $noBody,
            ],
        ], self::storedEntries($project));
        $summary = self::summary($project);
        $this->assertSame([
            ['code' => VaultFindings::MALFORMED, 'path' => 'group_vars/all/bom.yml', 'message' => $bom],
            ['code' => VaultFindings::MALFORMED, 'path' => 'group_vars/all/broken.yml', 'message' => $trailing],
            ['code' => VaultFindings::MALFORMED, 'path' => 'host_vars/web1.yml', 'message' => $noBody],
        ], $summary['findings']);
        $this->assertSame(4, $summary['entries']);
    }

    public function testTheCommitIsReadFromTheGitDirectoryOfTheCheckout(): void
    {
        $root = $this->fixtureCheckout();
        $this->put($root, '.git/HEAD', "ref: refs/heads/main\n");
        $this->put($root, '.git/refs/heads/main', self::SHA1 . "\n");
        $project = $this->manualProject($root);

        $this->service->scanProject($project);

        $summary = self::summary($project);
        $this->assertSame(self::SHA1, $project->vault_scan_commit);
        $this->assertSame(16, $summary['files_scanned'], 'the .git directory is not scanned');
    }

    public function testAGitProjectIsScannedInItsWorkspaceClone(): void
    {
        $workspace = $this->newTree();
        $this->replaceComponent('projectService', ['class' => ProjectService::class, 'workspacePath' => $workspace]);
        $project = $this->createProject($this->userId);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://git.example.com/infra/site.git';
        // A stale local path must not matter: git projects use the workspace.
        $project->local_path = $this->newTree();
        $project->save(false);
        $clone = $workspace . '/' . $project->id;
        $this->copyFixture('repo', $clone);
        $sha256 = str_repeat('ab12', 16);
        $this->put($clone, '.git/HEAD', $sha256 . "\n");

        $this->assertSame($clone, $this->service->checkoutPath($project));
        $this->assertTrue($this->service->scanProject($project));

        $this->assertCount(7, self::storedEntries($project));
        $project->refresh();
        $this->assertSame($sha256, $project->vault_scan_commit);
        $this->assertNull($project->vault_scan_error);
    }

    public function testTheCheckoutOfAManualProjectIsItsLocalPath(): void
    {
        $root = $this->newTree();

        $this->assertSame($root, $this->service->checkoutPath($this->manualProject($root)));
        $this->assertNull($this->service->checkoutPath($this->manualProject(null)));
        $this->assertNull($this->service->checkoutPath($this->manualProject('')));
    }

    public function testASecondScanReplacesTheEntriesOfThatProjectOnly(): void
    {
        $root = $this->fixtureCheckout();
        $project = $this->manualProject($root);
        $other = $this->manualProject($this->fixtureCheckout());
        $this->service->scanProject($project);
        $this->service->scanProject($other);
        $firstIds = ProjectVaultEntry::find()->select('id')->where(['project_id' => $project->id])->column();
        unlink($root . '/vars/secrets.yml');
        $this->put($root, 'vars/prod-secrets.yml', self::fixtureContent('file-1.2-prod.yml'));

        $this->assertTrue($this->service->scanProject($project));

        $paths = self::storedPaths($project);
        $this->assertContains('vars/prod-secrets.yml', $paths);
        $this->assertNotContains('vars/secrets.yml', $paths);
        $this->assertCount(7, $paths);
        $newIds = ProjectVaultEntry::find()->select('id')->where(['project_id' => $project->id])->column();
        $this->assertSame([], array_intersect($firstIds, $newIds), 'every earlier entry was replaced');
        $this->assertSame(7, self::summary($project)['entries']);
        $this->assertContains('vars/secrets.yml', self::storedPaths($other), 'the other project keeps its scan');
        $this->assertCount(7, self::storedPaths($other));
    }

    public function testWithoutACheckoutTheReasonIsStoredAndTheEntriesAndChecksAreRemoved(): void
    {
        $root = $this->fixtureCheckout();
        $project = $this->manualProject($root);
        $template = $this->devTemplate($project, $this->vaultCredential(self::DEV_PASSWORD));
        $this->service->scanProject($project);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, JobTemplateVaultCheck::findOne($template->id)?->status);
        self::removeTree($root);

        $this->assertFalse($this->service->scanProject($project));

        $project->refresh();
        $this->assertSame(self::NO_LOCAL_PATH . $root, $project->vault_scan_error);
        $this->assertNull($project->vault_scanned_at);
        $this->assertNull($project->vault_scan_commit);
        $this->assertNull($project->vault_scan_summary);
        $this->assertSame([], self::storedEntries($project));
        $this->assertNull(JobTemplateVaultCheck::findOne($template->id), 'a check needs a scan');
    }

    /**
     * Like the project page, the scan resolves a local path given as a Yii
     * alias, e.g. @runtime/playbooks.
     */
    public function testAGitProjectWithoutAWorkspaceCloneIsToldToSync(): void
    {
        $project = $this->manualProject(null);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://git.example.com/infra.git';
        $project->save(false);

        $this->assertFalse($this->service->scanProject($project));

        $project->refresh();
        $this->assertSame('There is no checkout to scan yet. Sync the project first.', $project->vault_scan_error);
        $this->assertNull($project->vault_scanned_at);
    }

    public function testALocalPathMayBeAYiiAlias(): void
    {
        $name = 'vault-alias-' . bin2hex(random_bytes(6));
        $root = (string)\Yii::getAlias('@runtime') . '/' . $name;
        $this->copyFixture('repo', $root);
        try {
            $project = $this->manualProject('@runtime/' . $name);

            $this->assertSame($root, $this->service->checkoutPath($project));
            $this->assertTrue($this->service->scanProject($project));
            $this->assertCount(7, self::storedEntries($project));
        } finally {
            self::removeTree($root);
        }
    }

    public function testAManualProjectWithoutALocalPathHasNoCheckout(): void
    {
        $project = $this->manualProject(null);

        $this->assertFalse($this->service->scanProject($project));

        // Regression: the message ended in an empty path ("...can read: ").
        $project->refresh();
        $this->assertSame(self::LOCAL_PATH_UNSET, $project->vault_scan_error);
        $this->assertNull($project->vault_scanned_at);
    }

    /**
     * File names and keys come from the repository and need not be UTF-8,
     * which the database rejects: they are stored with the bytes replaced,
     * and the rest of the scan is stored as well.
     */
    public function testNamesThatAreNotUtf8AreStoredScrubbedAndDoNotAbortTheScan(): void
    {
        $root = $this->newTree();
        $this->put($root, "group_vars/bad-\xFF.yml", self::fixtureContent('file-1.1.yml'));
        $this->put($root, "group_vars/broken-\xFE.yml", self::fixtureContent('malformed-trailing-space.yml'));
        $this->put($root, 'group_vars/keys.yml', self::inlineValue("db_\xFF"));
        $this->put($root, 'group_vars/ok.yml', self::fixtureContent('file-1.1.yml'));
        $project = $this->manualProject($root);

        $this->assertTrue($this->service->scanProject($project));

        $entries = self::storedEntries($project);
        $this->assertSame(
            ['group_vars/bad-?.yml', 'group_vars/broken-?.yml', 'group_vars/keys.yml', 'group_vars/ok.yml'],
            array_column($entries, 'path')
        );
        $this->assertSame('db_?', $entries[2]['key']);
        $this->assertSame('1.1', $entries[3]['format']);
        $summary = self::summary($project);
        $this->assertSame("group_vars/broken-\u{FFFD}.yml", $summary['findings'][0]['path']);
        $this->assertSame(4, $summary['entries']);
    }

    /**
     * Paths, keys and vault IDs are repository content of any length; the
     * database columns are not. Longer values are cut instead of failing
     * the scan.
     */
    public function testValuesLongerThanTheirColumnsAreCut(): void
    {
        $root = $this->newTree();
        $deep = implode('/', array_fill(0, 6, str_repeat('d', 200))) . '/vault.yml';
        $this->put($root, $deep, self::fixtureContent('file-1.1.yml'));
        $this->put($root, 'group_vars/long-key.yml', self::inlineValue(str_repeat('k', 300)));
        $longId = str_repeat('v', 300);
        $this->put($root, 'group_vars/long-id.yml', str_replace(
            '$ANSIBLE_VAULT;1.1;AES256',
            '$ANSIBLE_VAULT;1.2;AES256;' . $longId,
            self::fixtureContent('file-1.1.yml')
        ));
        $project = $this->manualProject($root);

        $this->assertTrue($this->service->scanProject($project));

        $entries = self::storedEntries($project);
        $this->assertCount(3, $entries);
        $this->assertSame(substr($deep, 0, 1024), $entries[0]['path']);
        $this->assertSame(substr($longId, 0, 255), $entries[1]['vault_id']);
        $this->assertSame('1.2', $entries[1]['format']);
        $this->assertSame(str_repeat('k', 255), $entries[2]['key']);
    }

    public function testAScanThatStopsAtALimitSaysSo(): void
    {
        $service = new class extends VaultScanService {
            protected function scanner(): VaultContentScanner
            {
                return new VaultContentScanner(10.0, VaultContentScanner::MAX_FILES, 2);
            }
        };
        $project = $this->manualProject($this->fixtureCheckout());

        $this->assertTrue($service->scanProject($project));

        $summary = self::summary($project);
        $this->assertTrue($summary['truncated']);
        $this->assertSame(2, $summary['entries']);
        $this->assertSame(VaultFindings::TRUNCATED, $summary['findings'][array_key_last($summary['findings'])]['code']);
        $this->assertCount(2, self::storedEntries($project));
    }

    /**
     * Regression: a failure while storing (such as a summary too long for its
     * column) rolled the scan back and left the previous scan in place, as if
     * it were current. Now the failure is recorded and the old entries go.
     */
    public function testAFailureWhileStoringIsRecordedAndDropsThePreviousScan(): void
    {
        $root = $this->fixtureCheckout();
        $project = $this->manualProject($root);
        $template = $this->devTemplate($project, $this->vaultCredential(self::DEV_PASSWORD));
        $this->service->scanProject($project);
        $project->refresh();
        $this->assertNotSame([], self::storedEntries($project));
        $failing = self::projectFailingOnSave(1);
        Project::populateRecord($failing, $project->getAttributes());

        $this->assertFalse($this->service->scanProject($failing));

        $project->refresh();
        $this->assertSame('The scan could not be completed; the application log has the details.', $project->vault_scan_error);
        $this->assertNull($project->vault_scanned_at);
        $this->assertSame([], self::storedEntries($project));
        $this->assertNull(JobTemplateVaultCheck::findOne($template->id), 'no check without a scan');
    }

    public function testWhenEvenTheFailureCannotBeStoredTheCallerLearnsAboutIt(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $failing = self::projectFailingOnSave(PHP_INT_MAX);
        Project::populateRecord($failing, $project->getAttributes());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated failure while storing the scan');
        $this->service->scanProject($failing);
    }

    /**
     * A project whose first $failures saves throw after writing.
     */
    private static function projectFailingOnSave(int $failures): Project
    {
        return new class ($failures) extends Project {
            public function __construct(private int $failuresLeft)
            {
                parent::__construct();
            }

            public function afterSave($insert, $changedAttributes): void
            {
                parent::afterSave($insert, $changedAttributes);
                if ($this->failuresLeft > 0) {
                    $this->failuresLeft--;
                    throw new \RuntimeException('simulated failure while storing the scan');
                }
            }
        };
    }

    // -- vault password source -------------------------------------------------

    public function testSwitchingThePasswordSourceIsAuditedAndRechecksTheTemplates(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $template = $this->devTemplate($project, null);
        $this->service->scanProject($project);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, JobTemplateVaultCheck::findOne($template->id)?->status);
        $project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $project->save(false);

        $this->service->recordPasswordSourceChange($project, Project::VAULT_SOURCE_ANSILUME, ['source' => 'api']);

        $log = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED, 'object_type' => 'project', 'object_id' => $project->id])
            ->one();
        $this->assertNotNull($log);
        $this->assertSame(
            ['name' => $project->name, 'from' => 'ansilume', 'to' => 'repository', 'source' => 'api'],
            json_decode((string)$log->metadata, true)
        );
        // The repository's ansible.cfg names a vault_password_file, which
        // runners now apply: Ansilume cannot check that password.
        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, JobTemplateVaultCheck::findOne($template->id)?->status);
    }

    public function testAnUnchangedPasswordSourceIsNeitherAuditedNorRechecked(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $template = $this->devTemplate($project, null);
        $this->service->scanProject($project);
        JobTemplateVaultCheck::updateAll(['checked_at' => 1], ['job_template_id' => $template->id]);
        $project->vault_password_source = Project::VAULT_SOURCE_ANSILUME;

        $this->service->recordPasswordSourceChange($project, Project::VAULT_SOURCE_ANSILUME);

        $this->assertSame(0, (int)AuditLog::find()->where([
            'action' => AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED,
            'object_id' => $project->id,
        ])->count());
        $this->assertSame(1, JobTemplateVaultCheck::findOne($template->id)?->checked_at);
    }

    // -- after a project save ----------------------------------------------------

    /**
     * A VaultCheckService that counts checkProject() calls.
     */
    private function countingChecks(): VaultCheckService
    {
        $checks = new class extends VaultCheckService {
            public int $projectChecks = 0;

            public function checkProject(Project $project): void
            {
                $this->projectChecks++;
                parent::checkProject($project);
            }
        };
        $this->replaceComponent('vaultCheckService', $checks);

        return $checks;
    }

    /**
     * Regression: switching the password source of a manual project checked
     * every template twice, once for the switch and once for the scan that
     * follows, doubling the time a save could take.
     */
    public function testSavingAManualProjectWithANewPasswordSourceScansAndChecksOnce(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $template = $this->devTemplate($project, null);
        $checks = $this->countingChecks();
        $project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $project->save(false);

        $this->service->afterProjectSave($project, Project::VAULT_SOURCE_ANSILUME, ['source' => 'web']);

        $this->assertSame(1, $checks->projectChecks);
        $this->assertSame(1, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED, 'object_id' => $project->id])->count());
        $project->refresh();
        $this->assertNotNull($project->vault_scanned_at, 'manual projects are scanned when saved');
        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, JobTemplateVaultCheck::findOne($template->id)?->status);
    }

    public function testSavingAGitProjectWithANewPasswordSourceRechecksWithoutScanning(): void
    {
        $project = $this->manualProject(null);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://git.example.com/infra.git';
        $project->save(false);
        $checks = $this->countingChecks();

        $this->service->afterProjectSave($project, Project::VAULT_SOURCE_REPOSITORY);

        $this->assertSame(1, $checks->projectChecks);
        $project->refresh();
        $this->assertNull($project->vault_scanned_at, 'git projects are scanned by their sync');
        $this->assertNull($project->vault_scan_error);
    }

    public function testANewManualProjectIsScannedWithoutAnAudit(): void
    {
        $project = $this->manualProject($this->fixtureCheckout());
        $checks = $this->countingChecks();

        $this->service->afterProjectSave($project, null);

        $this->assertSame(1, $checks->projectChecks);
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED, 'object_id' => $project->id])->count());
        $project->refresh();
        $this->assertNotNull($project->vault_scanned_at);
    }

    public function testAnUnchangedGitProjectIsNeitherCheckedNorScanned(): void
    {
        $project = $this->manualProject(null);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->save(false);
        $project->refresh();
        $checks = $this->countingChecks();

        $this->service->afterProjectSave($project, (string)$project->vault_password_source);

        $this->assertSame(0, $checks->projectChecks);
    }
}
