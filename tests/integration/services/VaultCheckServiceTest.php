<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\JobTemplateWarnings;
use app\components\vault\VaultContentScanner;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\services\VaultCheckService;
use app\services\VaultScanService;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\vault\TemporaryTree;

/**
 * VaultCheckService on copies of the committed fixture repository
 * (tests/fixtures/vault/repo, see its README). The dev password opens the
 * vaults under inventories/dev, group_vars/, playbooks/ and vars/; the prod
 * password opens those under inventories/prod.
 */
class VaultCheckServiceTest extends DbTestCase
{
    use TemporaryTree;

    private const DEV_PASSWORD = 'ansilume-test-dummy-dev';
    private const PROD_PASSWORD = 'ansilume-test-dummy-prod';

    private VaultCheckService $service;
    private int $userId;
    private int $runnerGroupId;
    private string $root;
    private Project $project;

    /** @var array<string, mixed> application components replaced by a test, restored in tearDown() */
    private array $replaced = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('vaultCheckService');
        $this->userId = (int)$this->createUser('vaultcheck')->id;
        $this->runnerGroupId = (int)$this->createRunnerGroup($this->userId)->id;
        $this->root = $this->newTree();
        $this->copyFixture('repo', $this->root);
        $this->project = $this->createProject($this->userId);
        $this->project->local_path = $this->root;
        $this->project->vault_password_source = Project::VAULT_SOURCE_ANSILUME;
        $this->project->save(false);
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

    private function scan(): void
    {
        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $this->assertTrue($scans->scanProject($this->project));
    }

    /**
     * A file inventory of the project; $sourcePath may name a file or a
     * directory of the checkout.
     */
    private function fileInventory(string $sourcePath): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = $this->project->id;
        $inventory->source_path = $sourcePath;
        $inventory->content = null;
        $inventory->save(false);

        return $inventory;
    }

    private function vault(string $password): Credential
    {
        return $this->credentialWithSecret(Credential::TYPE_VAULT, ['vault_password' => $password]);
    }

    /**
     * @param array<string, mixed> $secrets
     */
    private function credentialWithSecret(string $type, array $secrets): Credential
    {
        $credential = $this->createCredential($this->userId, $type);
        $credential->secret_data = \Yii::$app->get('credentialService')->encryptSecrets($secrets);
        $credential->save(false);

        return $credential;
    }

    /**
     * @param list<Credential> $additional additional credentials in precedence order
     */
    private function template(Inventory $inventory, ?Credential $primary, string $playbook = 'site.yml', array $additional = []): JobTemplate
    {
        $template = $this->createJobTemplate((int)$this->project->id, (int)$inventory->id, $this->runnerGroupId, $this->userId);
        $template->playbook = $playbook;
        $template->credential_id = $primary?->id;
        $template->save(false);
        foreach ($additional as $order => $credential) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credential->id,
                'sort_order' => $order + 1,
            ])->execute();
        }

        return $template;
    }

    private function devTemplate(?Credential $primary): JobTemplate
    {
        return $this->template($this->fileInventory('inventories/dev/hosts.yml'), $primary);
    }

    private function check(JobTemplate $template): JobTemplateVaultCheck
    {
        $check = JobTemplateVaultCheck::findOne($template->id);
        $this->assertNotNull($check, 'the template has a vault check');

        return $check;
    }

    private function recheck(JobTemplate $template): JobTemplateVaultCheck
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);
        $this->service->checkTemplate($fresh);

        return $this->check($template);
    }

    /**
     * @return list<string>
     */
    private static function unopenedPaths(JobTemplateVaultCheck $check): array
    {
        return array_column($check->unopenedEntries(), 'path');
    }

    private function writeAnsibleCfg(string $content): void
    {
        file_put_contents($this->root . '/ansible.cfg', $content);
    }

    private function useRepositorySettings(): void
    {
        $this->project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $this->project->save(false);
    }

    /**
     * Scans with the given scanner (limits for tests) until tearDown().
     */
    private function useScanner(VaultContentScanner $scanner): void
    {
        $this->replaced['vaultScanService'] ??= \Yii::$app->getComponents(true)['vaultScanService'] ?? null;
        $scans = new class extends VaultScanService {
            public ?VaultContentScanner $scanner = null;

            protected function scanner(): VaultContentScanner
            {
                return $this->scanner ?? parent::scanner();
            }
        };
        $scans->scanner = $scanner;
        \Yii::$app->set('vaultScanService', $scans);
    }

    // -- statuses --------------------------------------------------------------

    public function testAPasswordThatOpensEveryRelevantVaultIsOk(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $template = $this->devTemplate($vault);
        $before = time();

        $this->scan();

        $check = $this->check($template);
        $this->project->refresh();
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertSame((int)$vault->id, $check->credential_id);
        // group_vars/all.yml, inventories/dev/group_vars/all/vault.yml, vars/secrets.yml
        $this->assertSame(3, $check->relevant_count);
        $this->assertNull($check->unopened);
        $this->assertSame((int)$this->project->vault_scanned_at, $check->scanned_at);
        $this->assertGreaterThanOrEqual($before, $check->checked_at);
        $this->assertLessThanOrEqual(time(), $check->checked_at);
    }

    public function testAPasswordThatDoesNotOpenEveryVaultIsAMismatchNamingTheUnopenedOnes(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $template = $this->template($this->fileInventory('inventories/prod/hosts.yml'), $vault);

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame((int)$vault->id, $check->credential_id);
        $this->assertSame(4, $check->relevant_count);
        $this->assertSame([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ], $check->unopenedEntries());
        $this->assertStringNotContainsString(self::DEV_PASSWORD, (string)$check->unopened);
    }

    public function testATemplateWithoutAVaultPasswordIsMissingOne(): void
    {
        $withoutCredentials = $this->devTemplate(null);
        $tokenOnly = $this->devTemplate($this->credentialWithSecret(Credential::TYPE_TOKEN, ['token' => 'tok']));

        $this->scan();

        foreach ([$withoutCredentials, $tokenOnly] as $template) {
            $check = $this->check($template);
            $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $check->status);
            $this->assertNull($check->credential_id);
            $this->assertSame(3, $check->relevant_count);
            $this->assertNull($check->unopened);
        }
    }

    /**
     * @return array<string, array{0: string|array<string, mixed>|null}>
     */
    public static function unusableSecretProvider(): array
    {
        return [
            'no secret stored' => [null],
            'undecryptable secret' => ['not-an-encrypted-blob'],
            'empty password' => [['vault_password' => '']],
            'whitespace-only password' => [['vault_password' => " \t\r\n"]],
            // PHP's trim() keeps the form feed, Ansible strips it.
            'form feed and vertical tab only' => [['vault_password' => "\x0C\x0B"]],
            'password that is no string' => [['vault_password' => 4711]],
        ];
    }

    /**
     * @dataProvider unusableSecretProvider
     * @param string|array<string, mixed>|null $secret raw secret_data, or secrets to encrypt
     */
    public function testAVaultPasswordWithoutAUsableSecretIsUnusable(string|array|null $secret): void
    {
        $vault = $this->createCredential($this->userId, Credential::TYPE_VAULT);
        $vault->secret_data = is_array($secret) ? \Yii::$app->get('credentialService')->encryptSecrets($secret) : $secret;
        $vault->save(false);
        $template = $this->devTemplate($vault);

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD, $check->status);
        $this->assertSame((int)$vault->id, $check->credential_id);
        $this->assertSame(3, $check->relevant_count);
        $this->assertNull($check->unopened);
    }

    public function testThePasswordIsReadLikeAPasswordFile(): void
    {
        $trailingNewline = $this->devTemplate($this->vault(self::DEV_PASSWORD . "\n"));
        $padded = $this->devTemplate($this->vault(" \t" . self::DEV_PASSWORD . "\r\n\x0C"));

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($trailingNewline)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($padded)->status);
    }

    public function testTheVaultPasswordMayBeAnAdditionalCredential(): void
    {
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $token = $this->credentialWithSecret(Credential::TYPE_TOKEN, ['token' => 'tok']);
        $vault = $this->vault(self::DEV_PASSWORD);
        $template = $this->template($this->fileInventory('inventories/dev/hosts.yml'), $ssh, 'site.yml', [$token, $vault]);

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertSame((int)$vault->id, $check->credential_id);
    }

    public function testARecheckUpdatesTheStoredCheck(): void
    {
        $vault = $this->vault(self::PROD_PASSWORD);
        $template = $this->devTemplate($vault);
        $this->scan();
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $this->check($template)->status);
        $vault->secret_data = \Yii::$app->get('credentialService')->encryptSecrets(['vault_password' => self::DEV_PASSWORD]);
        $vault->save(false);

        $check = $this->recheck($template);

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertNull($check->unopened);
        $this->assertSame(1, (int)JobTemplateVaultCheck::find()->where(['job_template_id' => $template->id])->count());
    }

    // -- repository-managed passwords ------------------------------------------

    /**
     * In 'Ansilume and repository' mode, the fixture's ansible.cfg names a
     * vault_password_file: runners get a password Ansilume cannot check, so
     * neither a missing nor a mismatching password is reported as such.
     */
    public function testARepositoryPasswordFileMakesTheCheckRepoManaged(): void
    {
        $this->useRepositorySettings();
        $missing = $this->devTemplate(null);
        $mismatch = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, $this->check($missing)->status);
        $this->assertNull($this->check($missing)->unopened);
        $check = $this->check($mismatch);
        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, $check->status);
        $this->assertSame(
            ['group_vars/all.yml', 'inventories/dev/group_vars/all/vault.yml', 'vars/secrets.yml'],
            self::unopenedPaths($check),
            'the unopened entries are kept for the overview'
        );
    }

    public function testARepositoryVaultIdentityListMakesTheCheckRepoManaged(): void
    {
        $this->writeAnsibleCfg("[defaults]\nvault_identity_list = dev@.vault_pass\n");
        $this->useRepositorySettings();
        $missing = $this->devTemplate(null);
        $mismatch = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, $this->check($missing)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, $this->check($mismatch)->status);
    }

    public function testRepositoryModeWithoutARepositoryPasswordSourceStillReportsProblems(): void
    {
        $this->writeAnsibleCfg("[defaults]\nask_vault_pass = True\nvault_id_match = True\n");
        $this->useRepositorySettings();
        $missing = $this->devTemplate(null);
        $mismatch = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->check($missing)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $this->check($mismatch)->status);
    }

    public function testInAnsilumeOnlyModeTheRepositoryPasswordFileDoesNotCount(): void
    {
        $missing = $this->devTemplate(null);
        $mismatch = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->check($missing)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $this->check($mismatch)->status);
    }

    // -- no files, stale, no scan ----------------------------------------------

    public function testATemplateThatLoadsNoVaultHasNoFiles(): void
    {
        $template = $this->template($this->createInventory($this->userId), $this->vault(self::DEV_PASSWORD), 'other/play.yml');

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_NO_FILES, $check->status);
        $this->assertSame(0, $check->relevant_count);
        $this->assertNull($check->unopened);
    }

    public function testACheckoutWithoutVaultsHasNoFiles(): void
    {
        self::removeTree($this->root);
        mkdir($this->root);
        file_put_contents($this->root . '/site.yml', "---\n- hosts: all\n  tasks: []\n");
        $template = $this->devTemplate(null);

        $this->scan();

        $this->assertSame(JobTemplateVaultCheck::STATUS_NO_FILES, $this->check($template)->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changedCheckoutProvider(): array
    {
        return [
            'file re-encrypted after the scan' => ['changed'],
            'file deleted after the scan' => ['deleted'],
            'directory on the path replaced by a symlink' => ['symlinked'],
        ];
    }

    /**
     * A check never trusts a scan the checkout no longer matches: the
     * template's vaults are read again, and a difference makes it stale.
     *
     * @dataProvider changedCheckoutProvider
     */
    public function testACheckoutThatChangedAfterTheScanMakesTheCheckStale(string $change): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->scan();
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($template)->status);
        $vault = $this->root . '/inventories/dev/group_vars/all/vault.yml';
        if ($change === 'changed') {
            copy(self::fixture('file-1.1.yml'), $vault);
        } elseif ($change === 'deleted') {
            unlink($vault);
        } else {
            rename($this->root . '/inventories/dev/group_vars', $this->root . '/inventories/dev/real_group_vars');
            symlink('real_group_vars', $this->root . '/inventories/dev/group_vars');
        }

        $check = $this->recheck($template);

        $this->assertSame(JobTemplateVaultCheck::STATUS_STALE, $check->status);
        $this->assertSame(3, $check->relevant_count);
        $this->assertNull($check->unopened);
    }

    public function testWithoutAScanTheCheckIsRemoved(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->scan();
        $this->check($template);
        $this->project->vault_scanned_at = null;
        $this->project->save(false);

        $this->service->checkTemplate($template);

        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    public function testATemplateOfAProjectThatWasNeverScannedGetsNoCheck(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));

        $this->service->checkTemplate($template);

        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    public function testWithoutACheckoutPathTheCheckIsRemoved(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->scan();
        $this->check($template);
        $this->project->local_path = null;
        $this->project->save(false);

        $this->service->checkTemplate($template);

        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    /**
     * The foreign keys keep a template's project and inventory, but a
     * template in memory may point elsewhere: without its project there is
     * no check, without its inventory only the playbook's vaults count.
     */
    public function testATemplateWhoseProjectOrInventoryCannotBeFound(): void
    {
        $template = $this->devTemplate($this->vault(self::PROD_PASSWORD));
        $this->scan();
        $withoutInventory = JobTemplate::findOne($template->id);
        $this->assertNotNull($withoutInventory);
        $withoutInventory->inventory_id = 999999999;

        $this->service->checkTemplate($withoutInventory);

        $this->assertSame(['group_vars/all.yml', 'vars/secrets.yml'], self::unopenedPaths($this->check($template)));

        $withoutProject = JobTemplate::findOne($template->id);
        $this->assertNotNull($withoutProject);
        $withoutProject->project_id = 999999999;

        $this->service->checkTemplate($withoutProject);

        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    // -- which vaults a template loads -----------------------------------------

    public function testTheInventoryDecidesWhichInventoryVaultsAreRelevant(): void
    {
        $prod = $this->vault(self::PROD_PASSWORD);
        $dev = $this->template($this->fileInventory('inventories/dev/hosts.yml'), $prod);
        $prodFile = $this->template($this->fileInventory('inventories/prod/hosts.yml'), $prod);

        $this->scan();

        $this->assertSame(
            ['group_vars/all.yml', 'inventories/dev/group_vars/all/vault.yml', 'vars/secrets.yml'],
            self::unopenedPaths($this->check($dev))
        );
        $this->assertSame(4, $this->check($prodFile)->relevant_count);
        $this->assertSame(['group_vars/all.yml', 'vars/secrets.yml'], self::unopenedPaths($this->check($prodFile)));
    }

    public function testAnInventoryDirectoryContributesEverythingBelowIt(): void
    {
        $template = $this->template($this->fileInventory('inventories/prod'), $this->vault(self::DEV_PASSWORD));

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame(4, $check->relevant_count);
        $this->assertSame(
            ['inventories/prod/group_vars/all/vault.yml', 'inventories/prod/host_vars/prod-web1.yml'],
            self::unopenedPaths($check)
        );
    }

    /**
     * playbooks/deploy.yml loads playbooks/group_vars/ and its vars_files
     * ../vars/secrets.yml and deploy-vars.yml; the root group_vars/ belong
     * to site.yml only.
     */
    public function testANestedPlaybookLoadsItsOwnGroupVarsAndVarsFiles(): void
    {
        $inventory = $this->fileInventory('inventories/dev/hosts.yml');
        $wrong = $this->template($inventory, $this->vault(self::PROD_PASSWORD), 'playbooks/deploy.yml');
        $right = $this->template($inventory, $this->vault(self::DEV_PASSWORD), 'playbooks/deploy.yml');

        $this->scan();

        $this->assertSame([
            'inventories/dev/group_vars/all/vault.yml',
            'playbooks/deploy-vars.yml',
            'playbooks/group_vars/web.yml',
            'vars/secrets.yml',
        ], self::unopenedPaths($this->check($wrong)));
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($right)->status);
        $this->assertSame(4, $this->check($right)->relevant_count);
    }

    public function testAStaticInventoryAddsNoInventoryDirectory(): void
    {
        $template = $this->template($this->createInventory($this->userId), $this->vault(self::PROD_PASSWORD));

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(2, $check->relevant_count);
        $this->assertSame([
            ['path' => 'group_vars/all.yml', 'line' => 3, 'key' => 'app_secret', 'reason' => null],
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null, 'reason' => null],
        ], $check->unopenedEntries());
    }

    // -- which templates are checked -------------------------------------------

    public function testAProjectCheckCoversItsActiveTemplatesOnly(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $active = $this->devTemplate($vault);
        $deleted = $this->devTemplate($vault);
        $deleted->softDelete();
        $otherProject = $this->createProject($this->userId);
        $elsewhere = $this->createJobTemplate((int)$otherProject->id, (int)$this->createInventory($this->userId)->id, $this->runnerGroupId, $this->userId);
        $this->scan();
        JobTemplateVaultCheck::deleteAll(['job_template_id' => [$active->id, $deleted->id, $elsewhere->id]]);

        $this->service->checkProject($this->project);

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($active)->status);
        $this->assertNull(JobTemplateVaultCheck::findOne($deleted->id));
        $this->assertNull(JobTemplateVaultCheck::findOne($elsewhere->id));
    }

    public function testTemplatesUsingACredentialAsPrimaryOrAdditional(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $ssh = $this->createCredential($this->userId, Credential::TYPE_SSH_KEY);
        $inventory = $this->createInventory($this->userId);
        $primary = $this->template($inventory, $vault);
        $additional = $this->template($inventory, $ssh, 'site.yml', [$vault]);
        $deleted = $this->template($inventory, $vault);
        $deleted->softDelete();
        $this->template($inventory, $ssh);

        $ids = $this->service->templateIdsUsingCredential($vault);

        sort($ids);
        $this->assertSame([(int)$primary->id, (int)$additional->id], $ids);
        $this->assertSame([], $this->service->templateIdsUsingCredential($this->vault(self::PROD_PASSWORD)));
    }

    public function testTemplatesUsingAnInventory(): void
    {
        $inventory = $this->fileInventory('inventories/dev/hosts.yml');
        $first = $this->template($inventory, null);
        $second = $this->template($inventory, null);
        $deleted = $this->template($inventory, null);
        $deleted->softDelete();
        $this->template($this->createInventory($this->userId), null);

        $ids = $this->service->templateIdsUsingInventory($inventory);

        sort($ids);
        $this->assertSame([(int)$first->id, (int)$second->id], $ids);
        $this->assertSame([], $this->service->templateIdsUsingInventory($this->createInventory($this->userId)));
    }

    public function testCheckingTemplatesByIdSkipsUnknownAndDeletedOnes(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $template = $this->devTemplate($vault);
        $deleted = $this->devTemplate($vault);
        $this->scan();
        JobTemplateVaultCheck::deleteAll(['job_template_id' => [$template->id, $deleted->id]]);
        $deleted->softDelete();

        $this->service->checkTemplateIds([999999999, (int)$template->id, (int)$deleted->id]);

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($template)->status);
        $this->assertNull(JobTemplateVaultCheck::findOne($deleted->id));
    }

    // -- best effort -------------------------------------------------------------

    /**
     * The check runs after template, credential and inventory saves and
     * after scans; a failure is logged and must never undo or block them.
     */
    public function testACheckThatFailsIsLoggedAndNeverReachesTheCaller(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->project->vault_scanned_at = time();
        $this->project->save(false);
        $this->replaced['vaultScanService'] = \Yii::$app->getComponents(true)['vaultScanService'] ?? null;
        \Yii::$app->set('vaultScanService', new class extends VaultScanService {
            public function checkoutPath(Project $project): ?string
            {
                throw new \RuntimeException('simulated filesystem failure');
            }
        });
        $previousLogger = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);

        try {
            $this->service->checkTemplate($template);
        } finally {
            \Yii::setLogger($previousLogger);
        }

        $warnings = array_values(array_filter(
            $logger->messages,
            static fn (array $message): bool => $message[1] === \yii\log\Logger::LEVEL_WARNING && $message[2] === VaultCheckService::class
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame("Vault check for job template #{$template->id} failed: simulated filesystem failure", $warnings[0][0]);
        $this->assertNull(JobTemplateVaultCheck::findOne($template->id));
    }

    /**
     * Even when removing the outdated result fails too (the database went
     * away), both failures are logged and the caller's save stands.
     */
    public function testAFailureToRemoveTheResultIsLoggedAsWell(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->project->vault_scanned_at = time();
        $this->project->save(false);
        $db = \Yii::$app->db;
        $this->replaced['vaultScanService'] = \Yii::$app->getComponents(true)['vaultScanService'] ?? null;
        \Yii::$app->set('vaultScanService', new class extends VaultScanService {
            public function checkoutPath(Project $project): ?string
            {
                // An empty in-memory database: no vault check table to delete from.
                \Yii::$app->set('db', new \yii\db\Connection(['dsn' => 'sqlite::memory:']));
                throw new \RuntimeException('simulated filesystem failure');
            }
        });
        $previousLogger = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);

        try {
            $this->service->checkTemplate($template);
        } finally {
            \Yii::$app->set('db', $db);
            \Yii::setLogger($previousLogger);
        }

        $warnings = array_values(array_filter(
            $logger->messages,
            static fn (array $message): bool => $message[1] === \yii\log\Logger::LEVEL_WARNING && $message[2] === VaultCheckService::class
        ));
        $this->assertCount(2, $warnings);
        $this->assertSame("Vault check for job template #{$template->id} failed: simulated filesystem failure", $warnings[0][0]);
        $this->assertStringStartsWith("Vault check for job template #{$template->id} could not be removed: ", $warnings[1][0]);
    }

    // -- bounded storage, failures, cache ------------------------------------------

    /**
     * Regression: every unopened entry was stored, so a template with many
     * encrypted values hit the column limit, the check failed and an older
     * "ok" stayed in place although jobs would fail.
     */
    public function testManyUnopenedValuesAreCountedAndOnlyTheFirstStored(): void
    {
        $block = $this->inlineBlock();
        $yaml = "---\n";
        for ($i = 1; $i <= 45; $i++) {
            $yaml .= "secret_{$i}: " . $block;
        }
        mkdir($this->root . '/inventories/dev/host_vars', 0755, true);
        file_put_contents($this->root . '/inventories/dev/host_vars/many.yml', $yaml);
        $template = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame(48, (int)$check->relevant_count);
        $this->assertSame(48, (int)$check->unopened_count);
        $this->assertCount(VaultCheckService::STORED_UNOPENED, $check->unopenedEntries());
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);
        $this->assertStringContainsString('does not open 48 of the 48 encrypted files', JobTemplateWarnings::forTemplate($fresh)[0]['message']);
        $this->assertStringContainsString(' and 45 more.', JobTemplateWarnings::forTemplate($fresh)[0]['message']);
    }

    /**
     * Regression: a failed check left the earlier result in place, so an
     * outdated "ok" could hide a password that no longer fits.
     */
    public function testAFailedCheckRemovesAnEarlierResult(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->scan();
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($template)->status);
        $this->replaced['vaultScanService'] = \Yii::$app->getComponents(true)['vaultScanService'] ?? null;
        \Yii::$app->set('vaultScanService', new class extends VaultScanService {
            public function checkoutPath(Project $project): ?string
            {
                throw new \RuntimeException('simulated filesystem failure');
            }
        });

        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);
        $this->service->checkTemplate($fresh);

        $this->assertNull(JobTemplateVaultCheck::findOne($template->id), 'an outdated result must not stay');
    }

    /**
     * Results are cached per process, keyed by the stored secret: a new
     * secret is checked again, not answered from the cache.
     */
    public function testANewSecretIsCheckedAgainInTheSameProcess(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $template = $this->devTemplate($vault);
        $this->scan();
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($template)->status);

        $vault->secret_data = \Yii::$app->get('credentialService')->encryptSecrets(['vault_password' => self::PROD_PASSWORD]);
        $vault->save(false);

        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $this->recheck($template)->status);
    }

    // -- damaged vaults and unreadable names ---------------------------------------

    /**
     * Regression: malformed vaults were left out of a template's entries, so
     * a template that loads one was "ok" (or "no encrypted files") although
     * Ansible fails on it with any password.
     */
    public function testAMalformedVaultTheTemplateLoadsMakesTheCheckDamaged(): void
    {
        copy(self::fixture('malformed-trailing-space.yml'), $this->root . '/inventories/dev/group_vars/all/broken.yml');
        $withPassword = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $withoutPassword = $this->devTemplate(null);
        $wrongPassword = $this->devTemplate($this->vault(self::PROD_PASSWORD));

        $this->scan();

        $check = $this->check($withPassword);
        $this->assertSame(JobTemplateVaultCheck::STATUS_DAMAGED, $check->status);
        $this->assertSame(4, $check->relevant_count);
        $this->assertSame(1, $check->unopened_count);
        $this->assertSame(
            [['path' => 'inventories/dev/group_vars/all/broken.yml', 'line' => null, 'key' => null, 'reason' => null]],
            $check->unopenedEntries()
        );
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->check($withoutPassword)->status);
        $this->assertSame(4, $this->check($withoutPassword)->relevant_count);
        // A password problem comes first; the damaged file stays on the vault card.
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $this->check($wrongPassword)->status);
        $this->assertNotContains('inventories/dev/group_vars/all/broken.yml', self::unopenedPaths($this->check($wrongPassword)));
    }

    /**
     * Regression: a vault whose file name is not UTF-8 could not be checked,
     * yet the template was "ok". It is now incomplete and names the file.
     */
    public function testAFileWithANameThatIsNotUtf8MakesTheCheckIncomplete(): void
    {
        copy($this->root . '/inventories/dev/group_vars/all/vault.yml', $this->root . "/inventories/dev/group_vars/all/bad-\xFF.yml");
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));

        $this->scan();

        /** @var ProjectVaultEntry|null $entry */
        $entry = ProjectVaultEntry::find()->where(['project_id' => $this->project->id])->andWhere(['like', 'path', 'bad-'])->one();
        $this->assertNotNull($entry);
        $this->assertSame(ProjectVaultEntry::ERROR_NAME_NOT_UTF8, $entry->error);
        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_INCOMPLETE, $check->status);
        $this->assertSame(JobTemplateVaultCheck::REASON_FILE_NAME, $check->incomplete_reason);
        $this->assertSame([$entry->path], self::unopenedPaths($check));
    }

    // -- vault_id_match --------------------------------------------------------

    /**
     * Regression: with vault_id_match in the repository's ansible.cfg and
     * "Ansilume and repository" mode, Ansible tries the template's password
     * (labelled 'default') only on vaults without a vault ID or with the ID
     * 'default', yet the check said "ok" for labelled vaults it opens.
     */
    public function testVaultIdMatchInRepositoryModeKeepsThePasswordFromLabelledVaults(): void
    {
        $this->writeAnsibleCfg("[defaults]\nvault_id_match = True\n");
        $this->useRepositorySettings();
        $template = $this->template($this->fileInventory('inventories/prod/hosts.yml'), $this->vault(self::PROD_PASSWORD));

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame([
            ['path' => 'group_vars/all.yml', 'line' => 3, 'key' => 'app_secret', 'reason' => null],
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH],
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null, 'reason' => null],
        ], $check->unopenedEntries());
    }

    /**
     * Only an ID that is absent or 'default' matches the template's password;
     * an empty ID matches nothing.
     */
    public function testVaultIdMatchLetsThePasswordTryVaultsWithoutAnIdOrWithTheIdDefault(): void
    {
        $this->writeAnsibleCfg("[defaults]\nvault_id_match = yes\n");
        $this->useRepositorySettings();
        $vault = (string)file_get_contents($this->root . '/inventories/dev/group_vars/all/vault.yml');
        $body = substr($vault, strpos($vault, "\n") + 1);
        file_put_contents($this->root . '/inventories/dev/group_vars/all/vault.yml', "\$ANSIBLE_VAULT;1.2;AES256;default\n" . $body);
        file_put_contents($this->root . '/inventories/dev/group_vars/all/empty-id.yml', "\$ANSIBLE_VAULT;1.2;AES256;\n" . $body);
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));

        $this->scan();

        $check = $this->check($template);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame(
            [['path' => 'inventories/dev/group_vars/all/empty-id.yml', 'line' => null, 'key' => null, 'reason' => JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH]],
            $check->unopenedEntries()
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function vaultIdMatchIgnoredProvider(): array
    {
        return [
            'Ansilume only: runners turn vault_id_match off' => [Project::VAULT_SOURCE_ANSILUME, "[defaults]\nvault_id_match = True\n", JobTemplateVaultCheck::STATUS_OK],
            'the repository brings its own passwords' => [
                Project::VAULT_SOURCE_REPOSITORY,
                "[defaults]\nvault_id_match = True\nvault_password_file = .vault_pass\n",
                JobTemplateVaultCheck::STATUS_OK,
            ],
        ];
    }

    /**
     * @dataProvider vaultIdMatchIgnoredProvider
     */
    public function testVaultIdMatchCountsOnlyWhenRunnersApplyItWithoutRepositoryPasswords(string $source, string $cfg, string $status): void
    {
        $this->writeAnsibleCfg($cfg);
        $this->project->vault_password_source = $source;
        $this->project->save(false);
        $template = $this->template($this->fileInventory('inventories/prod/hosts.yml'), $this->vault(self::PROD_PASSWORD), 'other/play.yml');

        $this->scan();

        // inventories/prod: a vault labelled prod and an inline value labelled prod.
        $this->assertSame($status, $this->check($template)->status);
    }

    // -- limits ------------------------------------------------------------------

    /**
     * Regression: on a scan that stopped at a limit, templates whose files lie
     * past the cut-off were "no encrypted files", and those whose found files
     * all opened were "ok". Neither is known, so the check is incomplete; a
     * problem the scan did find is still reported.
     */
    public function testATruncatedScanNeverClaimsNoFilesOrOk(): void
    {
        // Sorted order: .vault_pass, ansible.cfg, group_vars/all.yml,
        // inventories/dev/group_vars/all/{vars,vault}.yml, inventories/dev/hosts.yml;
        // the seventh file stops the scan.
        $this->useScanner(new VaultContentScanner(10.0, 6));
        $vault = $this->vault(self::DEV_PASSWORD);
        $found = $this->devTemplate($vault);
        $missing = $this->devTemplate(null);
        $pastTheLimit = $this->template($this->createInventory($this->userId), $vault, 'playbooks/deploy.yml');

        $this->scan();

        $this->project->refresh();
        $this->assertTrue($this->project->vaultScanTruncated());
        $this->assertSame(JobTemplateVaultCheck::STATUS_INCOMPLETE, $this->check($found)->status);
        $this->assertSame(JobTemplateVaultCheck::REASON_SCAN_LIMIT, $this->check($found)->incomplete_reason);
        $this->assertSame(2, $this->check($found)->relevant_count);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->check($missing)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_INCOMPLETE, $this->check($pastTheLimit)->status);
        $this->assertSame(JobTemplateVaultCheck::REASON_SCAN_LIMIT, $this->check($pastTheLimit)->incomplete_reason);
        $this->assertSame(0, $this->check($pastTheLimit)->relevant_count);
    }

    /**
     * Regression: checks had no time limit, so repository content could hold
     * a web request or the queue worker for minutes. A run that uses up its
     * budget stores the templates it cannot verify as incomplete; checks that
     * need no reading still finish, and the next run completes the rest.
     */
    public function testTheTimeBudgetStoresIncompleteChecks(): void
    {
        $vault = $this->vault(self::DEV_PASSWORD);
        $needsReading = $this->devTemplate($vault);
        $noPassword = $this->devTemplate(null);
        $noFiles = $this->template($this->createInventory($this->userId), $vault, 'other/play.yml');
        $this->scan();
        $budget = $this->service->timeBudget;
        $this->service->timeBudget = 0.0;

        try {
            $this->service->checkProject($this->project);
        } finally {
            $this->service->timeBudget = $budget;
        }

        $this->assertSame(JobTemplateVaultCheck::STATUS_INCOMPLETE, $this->check($needsReading)->status);
        $this->assertSame(JobTemplateVaultCheck::REASON_TIME_LIMIT, $this->check($needsReading)->incomplete_reason);
        $this->assertNull($this->check($needsReading)->unopened);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->check($noPassword)->status);
        $this->assertSame(JobTemplateVaultCheck::STATUS_NO_FILES, $this->check($noFiles)->status);

        $this->service->checkProject($this->project);

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($needsReading)->status);
        $this->assertNull($this->check($needsReading)->incomplete_reason);
    }

    /**
     * Regression: every inline value made the check read and parse its whole
     * file again, so 1900 values in one file cost about 1900 full parses per
     * template (minutes on large files). A run reads each file once.
     */
    public function testAFileWithManyInlineValuesIsReadOncePerCheck(): void
    {
        $block = $this->inlineBlock();
        $yaml = "---\n";
        for ($i = 1; $i <= 1900; $i++) {
            $yaml .= "secret_{$i}: " . $block . str_repeat("# padding to make every read count\n", 10);
        }
        mkdir($this->root . '/inventories/dev/host_vars', 0755, true);
        file_put_contents($this->root . '/inventories/dev/host_vars/many.yml', $yaml);
        $vault = $this->vault(self::DEV_PASSWORD);
        $first = $this->devTemplate($vault);
        $second = $this->devTemplate($vault);
        $this->scan();

        $started = hrtime(true);
        $this->service->checkTemplateIds([(int)$first->id, (int)$second->id]);
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($first)->status);
        $this->assertSame(1903, (int)$this->check($second)->relevant_count);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->check($second)->status);
        $this->assertLessThan(5.0, $seconds, 'two templates over a 2.7 MB file with 1900 values');
    }

    public function testABatchIsOneRunAndAFailedBatchLeavesNoRunBehind(): void
    {
        $template = $this->devTemplate($this->vault(self::DEV_PASSWORD));
        $this->scan();
        $budget = $this->service->timeBudget;

        $this->assertSame('result', $this->service->batch(fn (): string => 'result'));
        $this->service->timeBudget = 0.0;
        try {
            $this->service->batch(function (): void {
                throw new \RuntimeException('the work failed');
            });
            $this->fail('the exception reaches the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('the work failed', $e->getMessage());
        } finally {
            $this->service->timeBudget = $budget;
        }

        // A new run with the full budget: the failed batch's run is gone.
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $this->recheck($template)->status);
    }

    /**
     * The inline value of the fixture's group_vars/all.yml, from the tag on.
     */
    private function inlineBlock(): string
    {
        $content = (string)file_get_contents($this->root . '/group_vars/all.yml');
        $this->assertSame(1, preg_match('/!vault \|\n(?: +\S.*\n)+/', $content, $match));

        return $match[0];
    }
}
