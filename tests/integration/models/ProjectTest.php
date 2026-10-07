<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Credential;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\tests\integration\DbTestCase;

class ProjectTest extends DbTestCase
{
    // -- tableName / behaviors ---------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%project}}', Project::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $p = new Project();
        $behaviors = $p->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }

    // -- validation: required fields --------------------------------------------

    public function testValidationRequiresNameAndScmType(): void
    {
        $p = new Project();
        $this->assertFalse($p->validate());
        $this->assertArrayHasKey('name', $p->getErrors());
        $this->assertArrayHasKey('scm_type', $p->getErrors());
    }

    public function testValidationPassesForManualProject(): void
    {
        $user = $this->createUser();
        $p = new Project();
        $p->name = 'test';
        $p->scm_type = Project::SCM_TYPE_MANUAL;
        $p->scm_branch = 'main';
        $p->status = Project::STATUS_NEW;
        $p->created_by = $user->id;
        $this->assertTrue($p->validate());
    }

    // -- validation: scm_type ---------------------------------------------------

    public function testValidationRejectsInvalidScmType(): void
    {
        $p = new Project();
        $p->name = 'test';
        $p->scm_type = 'svn';
        $this->assertFalse($p->validate(['scm_type']));
    }

    public function testValidationAcceptsGitScmType(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $this->assertTrue($p->validate(['scm_type']));
    }

    public function testValidationAcceptsManualScmType(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_MANUAL;
        $this->assertTrue($p->validate(['scm_type']));
    }

    // -- validation: status -----------------------------------------------------

    public function testValidationAcceptsAllStatusValues(): void
    {
        foreach ([Project::STATUS_NEW, Project::STATUS_SYNCING, Project::STATUS_SYNCED, Project::STATUS_ERROR] as $status) {
            $p = new Project();
            $p->status = $status;
            $this->assertTrue($p->validate(['status']), "Status '{$status}' should be valid");
        }
    }

    public function testValidationRejectsInvalidStatus(): void
    {
        $p = new Project();
        $p->status = 'broken';
        $this->assertFalse($p->validate(['status']));
    }

    // -- validateScmUrl ---------------------------------------------------------

    public function testValidateScmUrlAcceptsHttpsUrl(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = 'https://github.com/example/repo.git';
        $this->assertTrue($p->validate(['scm_url']));
    }

    public function testValidateScmUrlAcceptsHttpUrl(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = 'http://github.com/example/repo.git';
        $this->assertTrue($p->validate(['scm_url']));
    }

    public function testValidateScmUrlAcceptsSshGitAtUrl(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = 'git@github.com:example/repo.git';
        $this->assertTrue($p->validate(['scm_url']));
    }

    public function testValidateScmUrlAcceptsSshProtocolUrl(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = 'ssh://git@github.com/example/repo.git';
        $this->assertTrue($p->validate(['scm_url']));
    }

    public function testValidateScmUrlRejectsInvalidUrl(): void
    {
        $p = new Project();
        $p->name = 'test';
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = 'not-a-valid-url';
        $p->scm_branch = 'main';
        $p->status = Project::STATUS_NEW;
        $this->assertFalse($p->validate());
        $this->assertArrayHasKey('scm_url', $p->getErrors());
        $this->assertStringContainsString('valid HTTPS URL', $p->getErrors()['scm_url'][0]);
    }

    public function testValidateScmUrlSkipsWhenEmpty(): void
    {
        $p = new Project();
        $p->scm_type = Project::SCM_TYPE_GIT;
        $p->scm_url = '';
        // Empty URL should pass scm_url validation (required is separate rule)
        $p->validateScmUrl();
        $this->assertEmpty($p->getErrors('scm_url'));
    }

    public function testValidateScmUrlNotTriggeredForManualProjects(): void
    {
        $user = $this->createUser();
        $p = new Project();
        $p->name = 'test';
        $p->scm_type = Project::SCM_TYPE_MANUAL;
        $p->scm_url = 'not-a-url';
        $p->scm_branch = 'main';
        $p->status = Project::STATUS_NEW;
        $p->created_by = $user->id;
        // The 'when' condition on scm_url only triggers for git projects
        $this->assertTrue($p->validate());
    }

    // -- isHttpsScmUrl / isSshScmUrl --------------------------------------------

    public function testIsHttpsScmUrlReturnsTrueForHttps(): void
    {
        $p = new Project();
        $p->scm_url = 'https://github.com/org/repo.git';
        $this->assertTrue($p->isHttpsScmUrl());
    }

    public function testIsHttpsScmUrlReturnsTrueForHttp(): void
    {
        $p = new Project();
        $p->scm_url = 'http://github.com/org/repo.git';
        $this->assertTrue($p->isHttpsScmUrl());
    }

    public function testIsHttpsScmUrlReturnsFalseForSsh(): void
    {
        $p = new Project();
        $p->scm_url = 'git@github.com:org/repo.git';
        $this->assertFalse($p->isHttpsScmUrl());
    }

    public function testIsHttpsScmUrlReturnsFalseForNull(): void
    {
        $p = new Project();
        $p->scm_url = null;
        $this->assertFalse($p->isHttpsScmUrl());
    }

    public function testIsSshScmUrlReturnsTrueForGitAt(): void
    {
        $p = new Project();
        $p->scm_url = 'git@github.com:org/repo.git';
        $this->assertTrue($p->isSshScmUrl());
    }

    public function testIsSshScmUrlReturnsTrueForSshProtocol(): void
    {
        $p = new Project();
        $p->scm_url = 'ssh://git@github.com/org/repo.git';
        $this->assertTrue($p->isSshScmUrl());
    }

    public function testIsSshScmUrlReturnsFalseForHttps(): void
    {
        $p = new Project();
        $p->scm_url = 'https://github.com/org/repo.git';
        $this->assertFalse($p->isSshScmUrl());
    }

    public function testIsSshScmUrlReturnsFalseForNull(): void
    {
        $p = new Project();
        $p->scm_url = null;
        $this->assertFalse($p->isSshScmUrl());
    }

    // -- validateScmCredentialType ----------------------------------------------

    public function testScmCredentialTypeValidationSkipsWhenNoCredential(): void
    {
        $p = new Project();
        $p->scm_credential_id = null;
        $p->scm_url = 'https://github.com/org/repo.git';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    public function testScmCredentialTypeValidationSkipsWhenNoUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = '';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    public function testScmCredentialTypeValidationRejectsSshKeyForHttpsUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = 'https://github.com/org/repo.git';
        $p->validateScmCredentialType();
        $this->assertNotEmpty($p->getErrors('scm_credential_id'));
        $this->assertStringContainsString('Token or Username/Password', $p->getErrors()['scm_credential_id'][0]);
    }

    public function testScmCredentialTypeValidationRejectsTokenForSshUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = 'git@github.com:org/repo.git';
        $p->validateScmCredentialType();
        $this->assertNotEmpty($p->getErrors('scm_credential_id'));
        $this->assertStringContainsString('SSH Key', $p->getErrors()['scm_credential_id'][0]);
    }

    public function testScmCredentialTypeValidationAcceptsTokenForHttpsUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = 'https://github.com/org/repo.git';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    public function testScmCredentialTypeValidationAcceptsSshKeyForSshUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = 'git@github.com:org/repo.git';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    public function testScmCredentialTypeValidationAcceptsUsernamePasswordForHttpsUrl(): void
    {
        $user = $this->createUser();
        $cred = $this->createCredential($user->id, Credential::TYPE_USERNAME_PASSWORD);
        $p = new Project();
        $p->scm_credential_id = $cred->id;
        $p->scm_url = 'https://github.com/org/repo.git';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    public function testScmCredentialTypeValidationSkipsWhenCredentialNotFound(): void
    {
        $p = new Project();
        $p->scm_credential_id = 999999;
        $p->scm_url = 'https://github.com/org/repo.git';
        $p->validateScmCredentialType();
        $this->assertEmpty($p->getErrors('scm_credential_id'));
    }

    // -- statusLabel ------------------------------------------------------------

    public function testStatusLabelReturnsHumanLabels(): void
    {
        $this->assertSame('New', Project::statusLabel(Project::STATUS_NEW));
        $this->assertSame('Syncing', Project::statusLabel(Project::STATUS_SYNCING));
        $this->assertSame('Synced', Project::statusLabel(Project::STATUS_SYNCED));
        $this->assertSame('Error', Project::statusLabel(Project::STATUS_ERROR));
    }

    public function testStatusLabelReturnsFallbackForUnknownStatus(): void
    {
        $this->assertSame('unknown', Project::statusLabel('unknown'));
    }

    // -- relations --------------------------------------------------------------

    public function testCreatorRelationReturnsUser(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $this->assertNotNull($project->creator);
        $this->assertSame($user->id, $project->creator->id);
    }

    public function testScmCredentialRelationReturnsNullWhenNotSet(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $this->assertNull($project->scmCredential);
    }

    public function testJobTemplatesRelationReturnsArray(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $this->assertIsArray($project->jobTemplates);
        $this->assertEmpty($project->jobTemplates);
    }

    public function testInventoriesRelationReturnsArray(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $this->assertIsArray($project->inventories);
    }

    // -- vault_password_source --------------------------------------------------

    public function testValidationDefaultsTheVaultSourceOfANewProjectToAnsilume(): void
    {
        $user = $this->createUser();
        $p = new Project();
        $p->name = 'vault-default-' . uniqid();
        $p->scm_type = Project::SCM_TYPE_MANUAL;
        $p->scm_branch = 'main';
        $p->status = Project::STATUS_NEW;
        $p->created_by = $user->id;

        $this->assertTrue($p->save());

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $p->vault_password_source);
        $p->refresh();
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $p->vault_password_source);
    }

    /**
     * Rows written without the column (save(false), raw inserts) get the
     * column default: new projects are 'Ansilume only'.
     */
    public function testTheDatabaseDefaultsTheVaultSourceToAnsilume(): void
    {
        $project = $this->createProject($this->createUser()->id);
        $project->refresh();

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $project->vault_password_source);
    }

    /**
     * Regression: an existing project with an emptied vault source was
     * quietly given 'ansilume' by the default rule.
     */
    public function testAnExistingProjectWithAnEmptyVaultSourceDoesNotValidate(): void
    {
        $project = $this->createProject((int)$this->createUser('vault-src')->id);
        $project->vault_password_source = '';

        $this->assertFalse($project->validate(['vault_password_source']));
        $this->assertSame('', $project->vault_password_source);
        $this->assertSame(['Vault Password Source cannot be blank.'], $project->getErrors('vault_password_source'));
    }

    public function testAnExplicitVaultSourceSurvivesValidation(): void
    {
        $p = new Project();
        $p->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;

        $this->assertTrue($p->validate(['vault_password_source']));
        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $p->vault_password_source);
    }

    public function testValidationAcceptsBothVaultSources(): void
    {
        foreach (['ansilume', 'repository'] as $source) {
            $p = new Project();
            $p->vault_password_source = $source;
            $this->assertTrue($p->validate(['vault_password_source']), "'{$source}' should be valid");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidVaultSourceProvider(): array
    {
        return [
            'unknown name' => ['ansible-cfg-only'],
            'wrong case' => ['Ansilume'],
            'padded' => [' repository'],
            'a label' => ['Ansilume only'],
        ];
    }

    /**
     * @dataProvider invalidVaultSourceProvider
     */
    public function testValidationRejectsAnUnknownVaultSource(string $source): void
    {
        $p = new Project();
        $p->vault_password_source = $source;

        $this->assertFalse($p->validate(['vault_password_source']));
        $this->assertSame(['Vault Password Source is invalid.'], $p->getErrors('vault_password_source'));
    }

    public function testVaultSourceLabel(): void
    {
        $this->assertSame('Ansilume only', Project::vaultSourceLabel(Project::VAULT_SOURCE_ANSILUME));
        $this->assertSame('Ansilume and repository', Project::vaultSourceLabel(Project::VAULT_SOURCE_REPOSITORY));
        $this->assertSame('something-else', Project::vaultSourceLabel('something-else'), 'unknown values are shown as they are');
        $this->assertSame('', Project::vaultSourceLabel(''));
    }

    // -- repositorySuppliesVaultPasswords -----------------------------------------

    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function repositoryPasswordsProvider(): array
    {
        $passwordFile = (string)json_encode(['cfg' => ['vault_password_file' => '.vault_pass']]);

        return [
            'Ansilume only ignores a repository password file' => [Project::VAULT_SOURCE_ANSILUME, $passwordFile, false],
            'never scanned' => [Project::VAULT_SOURCE_REPOSITORY, null, false],
            'summary is not JSON' => [Project::VAULT_SOURCE_REPOSITORY, '{"cfg": {"vault_password_file": ".vault_pass"', false],
            'summary is a JSON scalar' => [Project::VAULT_SOURCE_REPOSITORY, '"vault_password_file"', false],
            'summary without ansible.cfg settings' => [Project::VAULT_SOURCE_REPOSITORY, '{"findings": []}', false],
            'settings are not an object' => [Project::VAULT_SOURCE_REPOSITORY, '{"cfg": ".vault_pass"}', false],
            'no password source in ansible.cfg' => [
                Project::VAULT_SOURCE_REPOSITORY,
                (string)json_encode(['cfg' => ['vault_password_file' => '', 'vault_identity_list' => null, 'ask_vault_pass' => true, 'vault_id_match' => true]]),
                false,
            ],
            'a mistyped password file' => [Project::VAULT_SOURCE_REPOSITORY, (string)json_encode(['cfg' => ['vault_password_file' => true]]), false],
            'a password file' => [Project::VAULT_SOURCE_REPOSITORY, $passwordFile, true],
            'a vault identity list' => [Project::VAULT_SOURCE_REPOSITORY, (string)json_encode(['cfg' => ['vault_identity_list' => 'prod@prod.pw']]), true],
        ];
    }

    /**
     * True only in 'Ansilume and repository' mode with a password source in
     * the scanned ansible.cfg: then runners get passwords Ansilume cannot
     * check.
     *
     * @dataProvider repositoryPasswordsProvider
     */
    public function testRepositorySuppliesVaultPasswords(string $source, ?string $summary, bool $expected): void
    {
        $p = new Project();
        $p->vault_password_source = $source;
        $p->vault_scan_summary = $summary;

        $this->assertSame($expected, $p->repositorySuppliesVaultPasswords());
    }

    // -- vaultEntries -------------------------------------------------------------

    public function testVaultEntriesAreThisProjectsEntriesByPathAndLine(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $other = $this->createProject($user->id);
        $inline12 = $this->vaultEntry((int)$project->id, 'group_vars/all.yml', 12);
        $file = $this->vaultEntry((int)$project->id, 'vars/secrets.yml', null);
        $inline3 = $this->vaultEntry((int)$project->id, 'group_vars/all.yml', 3);
        $this->vaultEntry((int)$other->id, 'aaa.yml', null);

        $ids = array_map(static fn (ProjectVaultEntry $entry): int => (int)$entry->id, $project->vaultEntries);

        $this->assertSame([(int)$inline3->id, (int)$inline12->id, (int)$file->id], $ids);
        $this->assertSame([], $this->createProject($user->id)->vaultEntries);
    }

    private function vaultEntry(int $projectId, string $path, ?int $line): ProjectVaultEntry
    {
        $entry = new ProjectVaultEntry();
        $entry->project_id = $projectId;
        $entry->path = $path;
        $entry->kind = $line === null ? ProjectVaultEntry::KIND_FILE : ProjectVaultEntry::KIND_INLINE;
        $entry->line = $line;
        $entry->var_key = $line === null ? null : 'key_' . $line;
        $entry->format_version = '1.1';
        $entry->save(false);

        return $entry;
    }

    // -- persistence round-trip ------------------------------------------------

    public function testSaveAndReloadPreservesAllFields(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->refresh();
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $this->assertSame(Project::STATUS_NEW, $project->status);
        $this->assertSame('main', $project->scm_branch);
    }
}
