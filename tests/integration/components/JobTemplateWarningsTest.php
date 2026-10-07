<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\JobTemplateWarnings;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\services\CredentialService;
use app\services\VaultScanService;
use app\tests\integration\DbTestCase;
use yii\db\ActiveQuery;
use yii\helpers\FileHelper;

/**
 * Warnings of job templates: more than one vault password (Ansible only ever
 * gets the first) and a file or dynamic inventory of another project (the
 * runner never reads it), both saved before Ansilume rejected them, and a
 * vault password that does not open the encrypted files the template
 * probably loads, or none at all (from the project's last vault scan). The
 * template page, the launch page and the REST API show them per template;
 * the template list filters and counts them in SQL, which must agree with
 * the per-template check.
 */
class JobTemplateWarningsTest extends DbTestCase
{
    /** Situations of templatesOfEveryKind() that are soft-deleted. */
    private const SOFT_DELETED = ['soft-deleted with every warning', 'soft-deleted with a missing vault password'];

    /** When vaultCheck() marks a project as scanned. */
    private const SCANNED_AT = 1760000000;

    private int $userId;
    private int $groupId;
    private int $projectId;
    private int $otherProjectId;
    private int $staticInventoryId;
    /** A copy of the vault fixture repository, removed after the test. */
    private ?string $checkout = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('warnings')->id;
        $this->groupId = (int)$this->createRunnerGroup($this->userId)->id;
        $this->projectId = (int)$this->createProject($this->userId)->id;
        $this->otherProjectId = (int)$this->createProject($this->userId)->id;
        $this->staticInventoryId = (int)$this->inventory(Inventory::TYPE_STATIC, null)->id;
    }

    protected function tearDown(): void
    {
        if ($this->checkout !== null) {
            FileHelper::removeDirectory($this->checkout);
            $this->checkout = null;
        }
        parent::tearDown();
    }

    // -- forTemplate(): no warnings --------------------------------------------

    public function testACleanTemplateHasNoWarnings(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'only-vault');
        $key = $this->credential(Credential::TYPE_SSH_KEY, 'deploy-key');
        $token = $this->credential(Credential::TYPE_TOKEN, 'api-token');
        $template = $this->template((int)$vault->id, [(int)$key->id, (int)$token->id]);

        $this->assertSame([], JobTemplateWarnings::forTemplate($template));
    }

    public function testTheSameVaultAsPrimaryAndAdditionalCredentialIsOneVault(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'repeated-vault');
        $template = $this->template((int)$vault->id, [(int)$vault->id]);

        $this->assertSame([], JobTemplateWarnings::forTemplate($template));
    }

    public function testInventoriesTheRunnerReadsAreNoWarning(): void
    {
        $cases = [
            'file inventory of the own project' => $this->inventory(Inventory::TYPE_FILE, $this->projectId),
            'dynamic inventory of the own project' => $this->inventory(Inventory::TYPE_DYNAMIC, $this->projectId),
            'dynamic inventory without project' => $this->inventory(Inventory::TYPE_DYNAMIC, null),
            'static inventory of another project' => $this->inventory(Inventory::TYPE_STATIC, $this->otherProjectId),
        ];
        foreach ($cases as $case => $inventory) {
            $template = $this->template(null, [], (int)$inventory->id);
            $this->assertSame([], JobTemplateWarnings::forTemplate($template), $case);
        }
    }

    // -- forTemplate(): multiple_vault_credentials ------------------------------

    public function testAPrimaryAndAnAdditionalVaultAreWarnedAbout(): void
    {
        // Created first, so it has the lower id: precedence must not follow ids.
        $additional = $this->credential(Credential::TYPE_VAULT, 'staging-vault');
        $primary = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $template = $this->template((int)$primary->id, [(int)$additional->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 2 vault passwords, but Ansible gets only one: '
                . '"prod-vault" takes precedence and "staging-vault" is ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$additional->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testTwoAdditionalVaultsAreWarnedAboutInSortOrder(): void
    {
        $key = $this->credential(Credential::TYPE_SSH_KEY, 'deploy-key');
        // Lower id, but the higher sort_order: it is the one Ansible ignores.
        $second = $this->credential(Credential::TYPE_VAULT, 'second-vault');
        $first = $this->credential(Credential::TYPE_VAULT, 'first-vault');
        $template = $this->template((int)$key->id, [(int)$first->id, (int)$second->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 2 vault passwords, but Ansible gets only one: '
                . '"first-vault" takes precedence and "second-vault" is ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$second->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testEveryIgnoredVaultIsNamed(): void
    {
        $primary = $this->credential(Credential::TYPE_VAULT, 'vault-a');
        $second = $this->credential(Credential::TYPE_VAULT, 'vault-b');
        $third = $this->credential(Credential::TYPE_VAULT, 'vault-c');
        $template = $this->template((int)$primary->id, [(int)$second->id, (int)$third->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 3 vault passwords, but Ansible gets only one: '
                . '"vault-a" takes precedence and "vault-b" and "vault-c" are ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$second->id, (int)$third->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    // -- forTemplate(): inventory_other_project --------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectBoundTypeProvider(): array
    {
        return [
            'file inventory' => [Inventory::TYPE_FILE],
            'dynamic inventory' => [Inventory::TYPE_DYNAMIC],
        ];
    }

    /**
     * @dataProvider projectBoundTypeProvider
     */
    public function testAnInventoryOfAnotherProjectIsWarnedAbout(string $type): void
    {
        $inventory = $this->inventory($type, $this->otherProjectId);
        $template = $this->template(null, [], (int)$inventory->id);

        $this->assertSame([[
            'code' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT,
            'message' => $this->inventoryWarning($inventory),
            'credential_ids' => [],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testATemplateCanHaveBothWarnings(): void
    {
        $primary = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $additional = $this->credential(Credential::TYPE_VAULT, 'staging-vault');
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->template((int)$primary->id, [(int)$additional->id], (int)$inventory->id);

        $warnings = JobTemplateWarnings::forTemplate($template);

        $this->assertSame(
            [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, JobTemplateWarnings::INVENTORY_OTHER_PROJECT],
            array_column($warnings, 'code')
        );
        $this->assertSame([(int)$additional->id], $warnings[0]['credential_ids']);
        $this->assertSame($this->inventoryWarning($inventory), $warnings[1]['message']);
        $this->assertSame([], $warnings[1]['credential_ids']);
    }

    // -- forTemplate(): vault_password_mismatch ----------------------------------

    public function testAMismatchNamesThePasswordTheCountsAndTheValuesItDoesNotOpen(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $template = $this->template((int)$vault->id, []);
        // An encrypted file has no line; an inline value is named by its line, not by its key.
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 4, [
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret'],
        ]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => 'The vault password "prod-vault" does not open 2 of the 4 encrypted files or values this '
                . 'template probably loads: inventories/prod/group_vars/all/vault.yml, '
                . 'inventories/prod/host_vars/prod-web1.yml:2. Jobs fail when Ansible needs one of them. '
                . 'Check the password and the inventory; if the files changed, rescan the project.',
            'credential_ids' => [(int)$vault->id],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function unopenedCountProvider(): array
    {
        return [
            'one value' => [1, 'vars/v1.yml:11'],
            'three values' => [3, 'vars/v1.yml:11, vars/v2.yml:12, vars/v3.yml:13'],
            'four values' => [4, 'vars/v1.yml:11, vars/v2.yml:12, vars/v3.yml:13 and 1 more'],
            'seven values' => [7, 'vars/v1.yml:11, vars/v2.yml:12, vars/v3.yml:13 and 4 more'],
        ];
    }

    /**
     * @dataProvider unopenedCountProvider
     */
    public function testAMismatchNamesTheFirstThreeUnopenedValuesAndCountsTheRest(int $count, string $named): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'dev-vault');
        $template = $this->template((int)$vault->id, []);
        $unopened = array_map(
            static fn (int $i): array => ['path' => "vars/v{$i}.yml", 'line' => 10 + $i, 'key' => "secret_{$i}"],
            range(1, $count)
        );
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 9, $unopened);

        $warnings = JobTemplateWarnings::forTemplate($this->reload((int)$template->id));

        $this->assertCount(1, $warnings);
        $this->assertSame(
            "The vault password \"dev-vault\" does not open {$count} of the 9 encrypted files or values this template "
            . "probably loads: {$named}. Jobs fail when Ansible needs one of them. "
            . 'Check the password and the inventory; if the files changed, rescan the project.',
            $warnings[0]['message']
        );
    }

    public function testAPasswordWithoutAUsableSecretIsAMismatchNamingThePassword(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'empty-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD, (int)$vault->id, 3);

        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => 'The vault password "empty-vault" has no usable secret, so jobs fail. '
                . 'Enter its secret on the credential page.',
            'credential_ids' => [(int)$vault->id],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deletedPasswordProvider(): array
    {
        return [
            'mismatch' => [
                JobTemplateVaultCheck::STATUS_MISMATCH,
                'The vault password of this template does not open 1 of the 2 encrypted files or values this template '
                . 'probably loads: vars/secrets.yml. Jobs fail when Ansible needs one of them. '
                . 'Check the password and the inventory; if the files changed, rescan the project.',
            ],
            'unusable password' => [
                JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD,
                'The vault password of this template has no usable secret, so jobs fail. '
                . 'Enter its secret on the credential page.',
            ],
        ];
    }

    /**
     * Deleting the checked credential keeps the check (ON DELETE SET NULL):
     * the message still reads, without a name and without a credential id.
     *
     * @dataProvider deletedPasswordProvider
     */
    public function testAPasswordDeletedAfterTheCheckIsCalledThePasswordOfThisTemplate(string $status, string $message): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'gone-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, $status, (int)$vault->id, 2, [
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);

        $this->assertSame(1, $vault->delete());

        $check = JobTemplateVaultCheck::findOne((int)$template->id);
        $this->assertNotNull($check, 'the check outlives the credential');
        $this->assertNull($check->credential_id);
        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => $message,
            'credential_ids' => [],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    // -- forTemplate(): vault_password_missing -----------------------------------

    public function testATemplateWithoutVaultPasswordIsWarnedAboutTheEncryptedFilesItLoads(): void
    {
        $template = $this->template(null, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 3);

        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISSING,
            'message' => 'This template has no vault password, but it probably loads 3 encrypted files or values. '
                . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
            'credential_ids' => [],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    public function testAGitProjectIsToldToSyncAfterRepositoryChanges(): void
    {
        $project = Project::findOne($this->projectId);
        $this->assertNotNull($project);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->save(false);
        $vault = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 1, [
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);

        // Regression: the warning said "rescan", which never updates a git
        // project's checkout; only a sync does (and it rescans).
        $this->assertStringEndsWith(
            'Jobs fail when Ansible needs one of them. Check the password and the inventory; '
            . 'if the repository changed since the last sync, sync the project.',
            JobTemplateWarnings::forTemplate($this->reload((int)$template->id))[0]['message']
        );
    }

    public function testAMismatchCausedByVaultIdMatchSaysSo(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 2, [
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => JobTemplateVaultCheck::ENTRY_VAULT_ID_MATCH],
        ]);

        $this->assertSame(
            'The vault password "prod-vault" does not open 1 of the 2 encrypted files or values this template probably loads: '
            . 'inventories/prod/group_vars/all/vault.yml. Jobs fail when Ansible needs one of them. '
            . 'The repository\'s ansible.cfg sets vault_id_match, so Ansible tries this password only on vaults without a vault ID '
            . 'or with the ID "default". Check the password and the inventory; if the files changed, rescan the project.',
            JobTemplateWarnings::forTemplate($this->reload((int)$template->id))[0]['message']
        );
    }

    // -- forTemplate(): vault_file_damaged ---------------------------------------

    public function testDamagedVaultFilesAreNamedWhateverThePassword(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'dev-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_DAMAGED, (int)$vault->id, 5, [
            ['path' => 'group_vars/all/vault.yml', 'line' => null, 'key' => null],
            ['path' => 'host_vars/web1.yml', 'line' => 4, 'key' => 'token'],
        ]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_FILE_DAMAGED,
            'message' => 'Ansible cannot read 2 of the encrypted files or values this template probably loads, whatever the password: '
                . 'group_vars/all/vault.yml, host_vars/web1.yml:4. Jobs fail when Ansible loads one of them. '
                . 'The vault card of the project says what is wrong; encrypt them again.',
            'credential_ids' => [(int)$vault->id],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    // -- forTemplate(): vault_check_incomplete -----------------------------------

    /**
     * @return array<string, array{0: string, 1: list<array{path: string, line: int|null, key: string|null}>, 2: string}>
     */
    public static function incompleteProvider(): array
    {
        return [
            'the scan stopped at a limit' => [JobTemplateVaultCheck::REASON_SCAN_LIMIT, [], 'the scan of the project stopped at a limit '
                . '(too many files, encrypted values or symlinks, or out of time), so encrypted files this template loads may be missing from it.'],
            'the check ran out of time' => [JobTemplateVaultCheck::REASON_TIME_LIMIT, [], 'the check ran out of time '
                . '(the repository has many or large encrypted files). The next sync or rescan checks it again.'],
            'file names that are not UTF-8' => [JobTemplateVaultCheck::REASON_FILE_NAME, [['path' => "vars/\u{FFFD}.yml", 'line' => null, 'key' => null]],
                "the names of 1 encrypted files it probably loads are not valid UTF-8, so Ansilume cannot check them: vars/\u{FFFD}.yml."],
        ];
    }

    /**
     * @dataProvider incompleteProvider
     * @param list<array{path: string, line: int|null, key: string|null}> $entries
     */
    public function testAnIncompleteCheckSaysWhy(string $reason, array $entries, string $why): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'dev-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_INCOMPLETE, (int)$vault->id, 3, $entries, $reason);

        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_CHECK_INCOMPLETE,
            'message' => 'The vault check of this template is incomplete: ' . $why,
            'credential_ids' => [(int)$vault->id],
        ]], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    // -- forTemplate(): checks that are not current ------------------------------

    /**
     * Regression: a check stored against an older scan (a run cut short, or a
     * check racing a rescan) was shown as current. Only checks against the
     * project's last scan warn.
     */
    public function testACheckFromAnOlderScanGivesNoWarning(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'dev-vault');
        $template = $this->template((int)$vault->id, []);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 1, [
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);
        $this->assertCount(1, JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));

        $project = Project::findOne($this->projectId);
        $this->assertNotNull($project);
        $project->vault_scanned_at = (int)$project->vault_scanned_at + 60;
        $project->save(false);
        $this->assertSame([], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)), 'a newer scan');

        $project->vault_scanned_at = null;
        $project->save(false);
        $this->assertSame([], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)), 'no scan at all');
    }

    // -- forTemplate(): vault checks without a warning ---------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function quietVaultStatusProvider(): array
    {
        return [
            'the password opens every value' => [JobTemplateVaultCheck::STATUS_OK],
            'the repository supplies the passwords' => [JobTemplateVaultCheck::STATUS_REPO_MANAGED],
            'no encrypted files' => [JobTemplateVaultCheck::STATUS_NO_FILES],
            'the checkout changed since the scan' => [JobTemplateVaultCheck::STATUS_STALE],
        ];
    }

    /**
     * @dataProvider quietVaultStatusProvider
     */
    public function testVaultChecksWithoutAProblemAreNoWarning(string $status): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'quiet-vault');
        $template = $this->template((int)$vault->id, []);
        // Even with unopened values stored, as a repository-managed check keeps
        // them, the status alone decides.
        $this->vaultCheck((int)$template->id, $status, (int)$vault->id, 2, [
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);

        $this->assertSame([], JobTemplateWarnings::forTemplate($this->reload((int)$template->id)));
    }

    public function testVaultWarningsComeAfterTheOlderWarnings(): void
    {
        $primary = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $additional = $this->credential(Credential::TYPE_VAULT, 'staging-vault');
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->template((int)$primary->id, [(int)$additional->id], (int)$inventory->id);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$primary->id, 1, [
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);

        $warnings = JobTemplateWarnings::forTemplate($this->reload((int)$template->id));

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
        ], array_column($warnings, 'code'));
        $this->assertSame([(int)$additional->id], $warnings[0]['credential_ids'], 'the vault password Ansible ignores');
        $this->assertSame($this->inventoryWarning($inventory), $warnings[1]['message']);
        $this->assertSame([(int)$primary->id], $warnings[2]['credential_ids'], 'the vault password that was checked');
        $this->assertStringStartsWith('The vault password "prod-vault" does not open 1 of the 1 ', $warnings[2]['message']);
    }

    public function testAMissingVaultPasswordComesAfterTheInventoryWarning(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_DYNAMIC, $this->otherProjectId);
        $template = $this->template(null, [], (int)$inventory->id);
        $this->vaultCheck((int)$template->id, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 2);

        $this->assertSame(
            [JobTemplateWarnings::INVENTORY_OTHER_PROJECT, JobTemplateWarnings::VAULT_PASSWORD_MISSING],
            array_column(JobTemplateWarnings::forTemplate($this->reload((int)$template->id)), 'code')
        );
    }

    // -- forTemplate(): after a real scan ----------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string, 3: list<array{code: string, message: string}>}>
     */
    public static function realScanProvider(): array
    {
        $dev = 'ansilume-test-dummy-dev';
        $prod = 'ansilume-test-dummy-prod';
        $devInventory = 'inventories/dev/hosts.yml';
        $prodInventory = 'inventories/prod/hosts.yml';
        $ansilume = Project::VAULT_SOURCE_ANSILUME;
        $mismatch = static fn (string $named): array => [[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => 'The vault password "env-vault" does not open 2 of the 4 encrypted files or values this '
                . "template probably loads: {$named}. Jobs fail when Ansible needs one of them. "
                . 'Check the password and the inventory; if the files changed, rescan the project.',
        ]];

        return [
            'dev password, prod inventory' => [$dev, $prodInventory, $ansilume, $mismatch(
                'inventories/prod/group_vars/all/vault.yml, inventories/prod/host_vars/prod-web1.yml:2'
            )],
            'prod password, prod inventory' => [$prod, $prodInventory, $ansilume, $mismatch(
                'group_vars/all.yml:3, vars/secrets.yml'
            )],
            'dev password, dev inventory' => [$dev, $devInventory, $ansilume, []],
            'no password, dev inventory' => [null, $devInventory, $ansilume, [[
                'code' => JobTemplateWarnings::VAULT_PASSWORD_MISSING,
                'message' => 'This template has no vault password, but it probably loads 3 encrypted files or values. '
                    . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
            ]]],
            // The repository's ansible.cfg names .vault_pass: Ansilume cannot know what that opens.
            'dev password, prod inventory, repository passwords' => [$dev, $prodInventory, Project::VAULT_SOURCE_REPOSITORY, []],
        ];
    }

    /**
     * The whole way from a scan of the vault fixture repository (site.yml,
     * group_vars/ next to the playbook and the inventory, an inline value in
     * host_vars/) to the warning an operator reads.
     *
     * @dataProvider realScanProvider
     * @param list<array{code: string, message: string}> $expected
     */
    public function testAfterARealScanTheWarningNamesWhatThePasswordDoesNotOpen(
        ?string $password,
        string $inventoryPath,
        string $passwordSource,
        array $expected
    ): void {
        $project = Project::findOne($this->projectId);
        $this->assertNotNull($project);
        $project->local_path = $this->copyVaultRepository();
        $project->vault_password_source = $passwordSource;
        $project->save(false);
        $vaultId = null;
        if ($password !== null) {
            $vault = $this->credential(Credential::TYPE_VAULT, 'env-vault');
            /** @var CredentialService $credentials */
            $credentials = \Yii::$app->get('credentialService');
            $vault->secret_data = $credentials->encryptSecrets(['vault_password' => $password]);
            $vault->save(false);
            $vaultId = (int)$vault->id;
        }
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->projectId);
        $inventory->source_path = $inventoryPath;
        $inventory->save(false);
        $template = $this->template($vaultId, [], (int)$inventory->id);

        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $this->assertTrue($scans->scanProject($project));

        $warnings = JobTemplateWarnings::forTemplate($this->reload((int)$template->id));
        $this->assertSame($expected, array_map(
            static fn (array $warning): array => ['code' => $warning['code'], 'message' => $warning['message']],
            $warnings
        ));
    }

    // -- filter() ----------------------------------------------------------------

    public function testTheVaultFilterFindsExactlyTheTemplatesWithMoreThanOneVault(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS));

        // "repeated vault" (primary plus a pivot row of the same vault) is one
        // vault, not two; the soft-deleted template stays hidden.
        $expected = $this->idsOf($templates, [
            'primary and additional vault',
            'two additional vaults',
            'both warnings',
        ]);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    public function testTheInventoryFilterFindsExactlyTheTemplatesWithAnInventoryOfAnotherProject(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::INVENTORY_OTHER_PROJECT));

        $expected = $this->idsOf($templates, [
            'file inventory of another project',
            'dynamic inventory of another project',
            'both warnings',
            'other project with a file inventory of the first project',
            'mismatch and an inventory of another project',
        ]);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    public function testTheMismatchFilterFindsExactlyTheTemplatesWhosePasswordDoesNotOpenOrIsUnusable(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::VAULT_PASSWORD_MISMATCH));

        // Checks that found no problem (ok, repo_managed, no_files, stale) and
        // checks from an older scan are no warning; the soft-deleted template
        // with a mismatch stays hidden.
        $expected = $this->idsOf($templates, [
            'vault password mismatch',
            'unusable vault password',
            'mismatch and an inventory of another project',
            'other project with a vault password mismatch',
        ]);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    public function testTheMissingPasswordFilterFindsExactlyTheTemplatesWithoutAVaultPassword(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::VAULT_PASSWORD_MISSING));

        // A template without a vault password whose repository supplies the
        // passwords is no warning; the soft-deleted one stays hidden.
        $expected = $this->idsOf($templates, ['vault password missing']);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    public function testTheDamagedAndIncompleteFiltersFindExactlyTheirTemplates(): void
    {
        $templates = $this->templatesOfEveryKind();
        $damaged = $this->queryFor($templates);
        $incomplete = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($damaged, JobTemplateWarnings::VAULT_FILE_DAMAGED));
        $this->assertTrue(JobTemplateWarnings::filter($incomplete, JobTemplateWarnings::VAULT_CHECK_INCOMPLETE));

        $this->assertSame($this->idsOf($templates, ['damaged vault files']), $this->resultIds($damaged));
        $this->assertSame($this->idsOf($templates, ['incomplete vault check']), $this->resultIds($incomplete));
    }

    /**
     * The list filters in SQL, the template page checks in PHP: both must
     * flag the same templates.
     */
    public function testTheFiltersAgreeWithTheWarningsOfEachTemplate(): void
    {
        $templates = $this->visible($this->templatesOfEveryKind());

        foreach (JobTemplateWarnings::CODES as $code) {
            $flagged = [];
            foreach ($templates as $id) {
                $codes = array_column(JobTemplateWarnings::forTemplate($this->reload($id)), 'code');
                if (in_array($code, $codes, true)) {
                    $flagged[] = $id;
                }
            }
            sort($flagged);
            $query = $this->queryFor($templates);
            JobTemplateWarnings::filter($query, $code);

            $this->assertNotSame([], $flagged, "the templates of every kind include some with {$code}");
            $this->assertSame($flagged, $this->resultIds($query), $code);
        }
    }

    public function testAnUnknownCodeIsRejectedAndLeavesTheQueryUnchanged(): void
    {
        $templates = $this->templatesOfEveryKind();
        $visible = $this->visible($templates);

        foreach (['no_such_warning', ''] as $code) {
            $query = $this->queryFor($templates);
            $sql = $query->createCommand()->getRawSql();

            $this->assertFalse(JobTemplateWarnings::filter($query, $code), "code '{$code}'");

            $this->assertSame($sql, $query->createCommand()->getRawSql(), "code '{$code}'");
            $this->assertSame($this->idsOf($visible, array_keys($visible)), $this->resultIds($query), "code '{$code}'");
        }
    }

    // -- counts() ----------------------------------------------------------------

    public function testCountsReportHowManyTemplatesOfTheQueryHaveEachWarning(): void
    {
        $query = $this->queryFor($this->templatesOfEveryKind());
        $sql = $query->createCommand()->getRawSql();

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 3,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 5,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => 4,
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => 1,
            JobTemplateWarnings::VAULT_FILE_DAMAGED => 1,
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => 1,
        ], JobTemplateWarnings::counts($query));
        // counts() filters copies: the query itself still lists every template.
        $this->assertSame($sql, $query->createCommand()->getRawSql());
    }

    public function testCountsFollowTheConditionsOfTheQuery(): void
    {
        $templates = $this->templatesOfEveryKind();
        $clean = $this->queryFor([$templates['clean'], $templates['repeated vault']]);
        // Scoped by project the way the team filter of the controllers does it.
        $otherProject = $this->queryFor($templates)->andWhere(['job_template.project_id' => $this->otherProjectId]);
        $vaultChecked = $this->queryFor($this->idsOf($templates, [
            'vault password mismatch',
            'unusable vault password',
            'vault password missing',
            'vault password opens everything',
            'vault passwords from the repository',
            'no encrypted files',
            'stale vault check',
            'mismatch from an older scan',
        ]));

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 0,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 0,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => 0,
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => 0,
            JobTemplateWarnings::VAULT_FILE_DAMAGED => 0,
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => 0,
        ], JobTemplateWarnings::counts($clean));
        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 0,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 1,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => 1,
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => 0,
            JobTemplateWarnings::VAULT_FILE_DAMAGED => 0,
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => 0,
        ], JobTemplateWarnings::counts($otherProject));
        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 0,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 0,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => 2,
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => 1,
            JobTemplateWarnings::VAULT_FILE_DAMAGED => 0,
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => 0,
        ], JobTemplateWarnings::counts($vaultChecked));
    }

    // -- label() -----------------------------------------------------------------

    public function testLabelDescribesEachWarningAndFallsBackToTheCode(): void
    {
        $this->assertSame(
            'more than one vault password',
            JobTemplateWarnings::label(JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS)
        );
        $this->assertSame(
            'an inventory of another project',
            JobTemplateWarnings::label(JobTemplateWarnings::INVENTORY_OTHER_PROJECT)
        );
        $this->assertSame(
            'a vault password that does not open their encrypted files',
            JobTemplateWarnings::label(JobTemplateWarnings::VAULT_PASSWORD_MISMATCH)
        );
        $this->assertSame(
            'encrypted files but no vault password',
            JobTemplateWarnings::label(JobTemplateWarnings::VAULT_PASSWORD_MISSING)
        );
        $this->assertSame('encrypted files Ansible cannot read', JobTemplateWarnings::label(JobTemplateWarnings::VAULT_FILE_DAMAGED));
        $this->assertSame(
            'a vault check that could not cover all encrypted files',
            JobTemplateWarnings::label(JobTemplateWarnings::VAULT_CHECK_INCOMPLETE)
        );
        $this->assertSame('no_such_warning', JobTemplateWarnings::label('no_such_warning'));
    }

    public function testTheSummaryOfTheListSaysWhetherTheTemplatesNeedFixing(): void
    {
        $this->assertSame(
            '2 job template(s) have encrypted files Ansible cannot read. They keep running, but need fixing.',
            JobTemplateWarnings::summary(JobTemplateWarnings::VAULT_FILE_DAMAGED, 2)
        );
        $this->assertSame(
            '1 job template(s) have a vault check that could not cover all encrypted files. '
            . 'Their jobs are not affected; the check is repeated at the next sync or rescan.',
            JobTemplateWarnings::summary(JobTemplateWarnings::VAULT_CHECK_INCOMPLETE, 1)
        );
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * One template per situation, keyed by what makes it (not) a warning case.
     *
     * @return array<string, int> situation => template id
     */
    private function templatesOfEveryKind(): array
    {
        $vaultA = (int)$this->credential(Credential::TYPE_VAULT, 'vault-a')->id;
        $vaultB = (int)$this->credential(Credential::TYPE_VAULT, 'vault-b')->id;
        $key = (int)$this->credential(Credential::TYPE_SSH_KEY, 'deploy-key')->id;
        $token = (int)$this->credential(Credential::TYPE_TOKEN, 'api-token')->id;
        $otherFile = (int)$this->inventory(Inventory::TYPE_FILE, $this->otherProjectId)->id;
        $otherDynamic = (int)$this->inventory(Inventory::TYPE_DYNAMIC, $this->otherProjectId)->id;

        $templates = [
            'clean' => $this->template($vaultA, [$key, $token]),
            'repeated vault' => $this->template($vaultA, [$vaultA, $key]),
            'primary and additional vault' => $this->template($vaultA, [$vaultB]),
            'two additional vaults' => $this->template($key, [$vaultB, $vaultA]),
            'file inventory of another project' => $this->template(null, [], $otherFile),
            'dynamic inventory of another project' => $this->template(null, [], $otherDynamic),
            'file inventory of the own project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_FILE, $this->projectId)->id
            ),
            'dynamic inventory without project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_DYNAMIC, null)->id
            ),
            'static inventory of another project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_STATIC, $this->otherProjectId)->id
            ),
            'both warnings' => $this->template($vaultA, [$vaultB], $otherFile),
            'soft-deleted with every warning' => $this->template($vaultA, [$vaultB], $otherFile),
            // The project comparison is per template, not against one project.
            'other project with a file inventory of that project' => $this->template(
                null,
                [],
                $otherFile,
                $this->otherProjectId
            ),
            'other project with a file inventory of the first project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_FILE, $this->projectId)->id,
                $this->otherProjectId
            ),
            'vault password mismatch' => $this->template($vaultA, [$key]),
            'unusable vault password' => $this->template($vaultB, []),
            'vault password missing' => $this->template(null, [$key]),
            'vault password opens everything' => $this->template($vaultA, []),
            'vault passwords from the repository' => $this->template(null, []),
            'no encrypted files' => $this->template($vaultA, []),
            'stale vault check' => $this->template($vaultA, []),
            'damaged vault files' => $this->template($vaultA, []),
            'incomplete vault check' => $this->template($vaultA, []),
            'mismatch from an older scan' => $this->template($vaultA, []),
            'mismatch and an inventory of another project' => $this->template($vaultA, [], $otherFile),
            'other project with a vault password mismatch' => $this->template($vaultB, [], null, $this->otherProjectId),
            'soft-deleted with a missing vault password' => $this->template(null, []),
        ];
        $checks = [
            'vault password mismatch' => [JobTemplateVaultCheck::STATUS_MISMATCH, $vaultA],
            'unusable vault password' => [JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD, $vaultB],
            'vault password missing' => [JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null],
            'vault password opens everything' => [JobTemplateVaultCheck::STATUS_OK, $vaultA],
            'vault passwords from the repository' => [JobTemplateVaultCheck::STATUS_REPO_MANAGED, null],
            'no encrypted files' => [JobTemplateVaultCheck::STATUS_NO_FILES, $vaultA],
            'stale vault check' => [JobTemplateVaultCheck::STATUS_STALE, $vaultA],
            'damaged vault files' => [JobTemplateVaultCheck::STATUS_DAMAGED, $vaultA],
            'incomplete vault check' => [JobTemplateVaultCheck::STATUS_INCOMPLETE, $vaultA],
            'mismatch from an older scan' => [JobTemplateVaultCheck::STATUS_MISMATCH, $vaultA],
            'mismatch and an inventory of another project' => [JobTemplateVaultCheck::STATUS_MISMATCH, $vaultA],
            'other project with a vault password mismatch' => [JobTemplateVaultCheck::STATUS_MISMATCH, $vaultB],
            'soft-deleted with every warning' => [JobTemplateVaultCheck::STATUS_MISMATCH, $vaultA],
            'soft-deleted with a missing vault password' => [JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null],
        ];
        foreach ($checks as $situation => [$status, $credentialId]) {
            $this->vaultCheck((int)$templates[$situation]->id, $status, $credentialId, 2, [
                ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
            ], $status === JobTemplateVaultCheck::STATUS_INCOMPLETE ? JobTemplateVaultCheck::REASON_TIME_LIMIT : null);
        }
        // Checked against the scan before the project's last one: stale, no warning.
        JobTemplateVaultCheck::updateAll(['scanned_at' => self::SCANNED_AT - 60], ['job_template_id' => $templates['mismatch from an older scan']->id]);
        foreach (self::SOFT_DELETED as $situation) {
            $templates[$situation]->softDelete();
        }

        return array_map(static fn (JobTemplate $template): int => (int)$template->id, $templates);
    }

    /**
     * The templates of templatesOfEveryKind() a query can find.
     *
     * @param array<string, int> $templates
     * @return array<string, int>
     */
    private function visible(array $templates): array
    {
        return array_diff_key($templates, array_flip(self::SOFT_DELETED));
    }

    /**
     * A query over the given templates only, so rows of other tests or
     * seeders in the test database never count. Qualified, because the
     * inventory filter joins a second table with an id column.
     *
     * @param array<string, int>|list<int> $templateIds
     */
    private function queryFor(array $templateIds): ActiveQuery
    {
        return JobTemplate::find()->andWhere(['{{%job_template}}.id' => array_values($templateIds)]);
    }

    /**
     * @return list<int>
     */
    private function resultIds(ActiveQuery $query): array
    {
        $ids = array_map(static fn (JobTemplate $template): int => (int)$template->id, $query->all());
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, int> $templates
     * @param list<string> $situations
     * @return list<int>
     */
    private function idsOf(array $templates, array $situations): array
    {
        $ids = array_map(static fn (string $situation): int => $templates[$situation], $situations);
        sort($ids);

        return $ids;
    }

    private function credential(string $type, string $name): Credential
    {
        $credential = $this->createCredential($this->userId, $type);
        $credential->name = $name;
        $credential->save(false);

        return $credential;
    }

    private function inventory(string $type, ?int $projectId): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->inventory_type = $type;
        $inventory->project_id = $projectId;
        $inventory->content = $type === Inventory::TYPE_STATIC ? "all:\n  hosts:\n    web1.example.com:\n" : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : 'inventories/hosts.yml';
        $inventory->save(false);

        return $inventory;
    }

    /**
     * A template stored without validation, as before vault release 1, with
     * its additional credentials in the given precedence order.
     *
     * @param list<int> $additionalIds
     */
    private function template(
        ?int $primaryId,
        array $additionalIds,
        ?int $inventoryId = null,
        ?int $projectId = null
    ): JobTemplate {
        $template = $this->createJobTemplate(
            $projectId ?? $this->projectId,
            $inventoryId ?? $this->staticInventoryId,
            $this->groupId,
            $this->userId
        );
        $template->credential_id = $primaryId;
        $template->save(false);
        foreach ($additionalIds as $index => $credentialId) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credentialId,
                'sort_order' => $index + 1,
            ])->execute();
        }

        return $this->reload((int)$template->id);
    }

    /**
     * Stores a template's vault check the way VaultCheckService does, against
     * the last scan of the template's project (marked as scanned at
     * SCANNED_AT if it has none).
     *
     * @param list<array{path: string, line: int|null, key: string|null, reason?: string|null}> $unopened
     */
    private function vaultCheck(
        int $templateId,
        string $status,
        ?int $credentialId,
        int $relevantCount,
        array $unopened = [],
        ?string $incompleteReason = null
    ): JobTemplateVaultCheck {
        $project = Project::findOne($this->reload($templateId)->project_id);
        $this->assertNotNull($project);
        if ($project->vault_scanned_at === null) {
            $project->vault_scanned_at = self::SCANNED_AT;
            $project->save(false);
        }
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = $templateId;
        $check->status = $status;
        $check->incomplete_reason = $incompleteReason;
        $check->credential_id = $credentialId;
        $check->relevant_count = $relevantCount;
        $check->unopened_count = count($unopened);
        $check->unopened = $unopened === [] ? null : (string)json_encode($unopened);
        $check->checked_at = time();
        $check->scanned_at = (int)$project->vault_scanned_at;
        $this->assertTrue($check->save(false));

        return $check;
    }

    /**
     * A copy of tests/fixtures/vault/repo in the temp directory, removed in
     * tearDown(); the fixtures themselves stay untouched.
     */
    private function copyVaultRepository(): string
    {
        $this->checkout = sys_get_temp_dir() . '/ansilume-warnings-' . bin2hex(random_bytes(6));
        FileHelper::copyDirectory(dirname(__DIR__, 2) . '/fixtures/vault/repo', $this->checkout);

        return $this->checkout;
    }

    private function reload(int $id): JobTemplate
    {
        $template = JobTemplate::findOne($id);
        $this->assertNotNull($template);

        return $template;
    }

    private function inventoryWarning(Inventory $inventory): string
    {
        return 'The inventory "' . $inventory->name . '" is a file or dynamic inventory of another project. The runner '
            . 'checks out only this template\'s project and looks for the inventory there, so jobs do not use it. '
            . 'Switch to an inventory of this project or a static inventory.';
    }
}
