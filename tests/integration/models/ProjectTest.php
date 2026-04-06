<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Credential;
use app\models\Project;
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
