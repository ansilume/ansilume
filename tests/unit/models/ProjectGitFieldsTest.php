<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Credential;
use app\models\Project;
use app\tests\integration\DbTestCase;
use yii\validators\ExistValidator;
use yii\validators\NumberValidator;
use yii\validators\StringValidator;
use yii\validators\Validator;
use yii\web\AssetManager;
use yii\web\View;

/**
 * The Git fields of a project (Project::GIT_FIELDS: scm_url, scm_branch,
 * scm_credential_id), which the project form shows only while Git is
 * chosen. What they mean to git, the URL, the branch format and the type
 * of the credential, is checked for git projects only, on the server
 * ('when') and in the browser ('whenClient'). Their column limits and the
 * credential's existence hold for every project, as the database needs
 * them; the browser checks those too only while Git is chosen, as it would
 * otherwise refuse a field the form hides. Uses the test database for the
 * credentials, in a transaction that is rolled back.
 *
 * regression: the credential type was checked for every project, so a
 * manual project whose hidden Git fields held an SSH URL and a Token
 * credential, chosen under Git before the switch to Manual (no SCM), was
 * refused with "SSH URLs require an SSH Key credential." under the hidden
 * Credential field: nothing visible, nothing saved.
 */
class ProjectGitFieldsTest extends DbTestCase
{
    /** The fields the project form shows only while Git is chosen (#git-fields). */
    private const GIT_FIELDS = ['scm_url', 'scm_branch', 'scm_credential_id'];

    private const SSH_URL = 'git@git.example.com:org/playbooks.git';

    private const HTTPS_URL = 'https://git.example.com/org/playbooks.git';

    /** The local path of the manual projects: validating one needs no such directory. */
    private const LOCAL_PATH = '/opt/playbooks/git-fields';

    /**
     * A credential of another type than the URL needs, and the refusal a
     * git project with it gets.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function mismatchProvider(): array
    {
        $ssh = 'SSH URLs require an SSH Key credential.';
        $https = 'HTTPS URLs require a Token or Username/Password credential.';

        return [
            'an SSH URL and a Token credential' => [self::SSH_URL, Credential::TYPE_TOKEN, $ssh],
            'an SSH URL and a Username/Password credential' => [self::SSH_URL, Credential::TYPE_USERNAME_PASSWORD, $ssh],
            'an ssh:// URL and a Token credential' => ['ssh://git@git.example.com/org/playbooks.git', Credential::TYPE_TOKEN, $ssh],
            'an HTTPS URL and an SSH Key credential' => [self::HTTPS_URL, Credential::TYPE_SSH_KEY, $https],
            'an HTTPS URL and a Vault credential' => [self::HTTPS_URL, Credential::TYPE_VAULT, $https],
        ];
    }

    /**
     * regression: the old model refused each of these manual projects with
     * the refusal of a git project, on its hidden Credential field.
     *
     * @dataProvider mismatchProvider
     */
    public function testRegressionTheCredentialTypeOfAManualProjectIsNotChecked(string $url, string $type): void
    {
        $project = $this->project(Project::SCM_TYPE_MANUAL, $url, $this->credentialOf($type));

        $this->assertTrue($project->validate(), 'valid: ' . json_encode($project->getErrors()));
        $this->assertSame([], $project->getErrors('scm_credential_id'));
    }

    /**
     * A git project is checked as before: the refusal is the field error of
     * the Credential field, and the only error.
     *
     * @dataProvider mismatchProvider
     */
    public function testTheCredentialTypeOfAGitProjectIsChecked(string $url, string $type, string $refusal): void
    {
        $project = $this->project(Project::SCM_TYPE_GIT, $url, $this->credentialOf($type));

        $this->assertFalse($project->validate());
        $this->assertSame(['scm_credential_id' => [$refusal]], $project->getErrors());
        $this->assertContains($refusal, [Project::SSH_URL_NEEDS_SSH_KEY, Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD]);
    }

    /**
     * A credential of the type the URL needs is accepted for either type.
     */
    public function testACredentialOfTheTypeTheUrlNeedsIsAccepted(): void
    {
        $matches = [
            [self::SSH_URL, Credential::TYPE_SSH_KEY],
            [self::HTTPS_URL, Credential::TYPE_TOKEN],
            [self::HTTPS_URL, Credential::TYPE_USERNAME_PASSWORD],
        ];
        foreach ($matches as [$url, $type]) {
            foreach ([Project::SCM_TYPE_GIT, Project::SCM_TYPE_MANUAL] as $scmType) {
                $project = $this->project($scmType, $url, $this->credentialOf($type));
                $this->assertTrue($project->validate(), "{$scmType}, {$url}, {$type}: " . json_encode($project->getErrors()));
            }
        }
    }

    /**
     * Every save that makes a project a git project checks the credential
     * type: such a manual project switched to git is refused until it gets
     * a credential of the type its URL needs.
     */
    public function testAManualProjectSwitchedToGitIsCheckedForTheCredentialType(): void
    {
        $project = $this->project(Project::SCM_TYPE_MANUAL, self::SSH_URL, $this->credentialOf(Credential::TYPE_TOKEN));
        $this->assertTrue($project->validate(), 'manual: not checked');

        $project->scm_type = Project::SCM_TYPE_GIT;

        $this->assertFalse($project->validate(), 'git: checked');
        $this->assertSame([Project::SSH_URL_NEEDS_SSH_KEY], $project->getErrors('scm_credential_id'));

        $project->scm_credential_id = $this->credentialOf(Credential::TYPE_SSH_KEY);
        $this->assertTrue($project->validate(), 'git, with an SSH Key credential');
    }

    /**
     * What the database needs holds for every project: the column limits
     * of the URL and the branch, and a credential ID that is an integer of
     * an existing credential. The form shows these errors above itself
     * while it hides the Git fields (Project::gitFieldErrors()).
     *
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function brokenColumnProvider(): array
    {
        return [
            'a URL too long for its column' => ['scm_url', 'https://git.example.com/' . str_repeat('a', 489), 'Scm Url should contain at most 512 characters.'],
            'a branch too long for its column' => ['scm_branch', str_repeat('b', 129), 'Scm Branch should contain at most 128 characters.'],
            'a credential ID that is no integer' => ['scm_credential_id', 'abc', 'Scm Credential Id must be an integer.'],
            'a credential that does not exist' => ['scm_credential_id', 2147483647, 'Scm Credential Id is invalid.'],
        ];
    }

    /**
     * @dataProvider brokenColumnProvider
     */
    public function testWhatTheDatabaseNeedsIsCheckedForEveryProject(string $attribute, mixed $value, string $error): void
    {
        foreach ([Project::SCM_TYPE_GIT, Project::SCM_TYPE_MANUAL] as $scmType) {
            $project = $this->project($scmType, self::HTTPS_URL, null);
            $project->$attribute = $value;

            $this->assertFalse($project->validate(), $scmType);
            $this->assertSame([$attribute => [$error]], $project->getErrors(), $scmType . ': the one error');
            $this->assertSame([$error], $project->gitFieldErrors(), $scmType);
        }
    }

    /**
     * gitFieldErrors() gives the errors of the Git fields only, in the
     * order of the fields.
     */
    public function testGitFieldErrorsAreTheErrorsOfTheGitFieldsInTheirOrder(): void
    {
        $project = $this->project(Project::SCM_TYPE_MANUAL, 'https://git.example.com/' . str_repeat('a', 489), null);
        $project->name = '';
        $project->scm_branch = str_repeat('b', 129);
        $project->scm_credential_id = 'abc';
        $project->local_path = str_repeat('p', 513);

        $this->assertFalse($project->validate());

        $this->assertArrayHasKey('name', $project->getErrors());
        $this->assertArrayHasKey('local_path', $project->getErrors());
        $this->assertSame([
            'Scm Url should contain at most 512 characters.',
            'Scm Branch should contain at most 128 characters.',
            'Scm Credential Id must be an integer.',
        ], $project->gitFieldErrors());
        $this->assertSame([], $this->project(Project::SCM_TYPE_MANUAL, self::SSH_URL, null)->gitFieldErrors(), 'none before a validation');
    }

    /**
     * Closes the class of the bug: every rule of a Git field is either a
     * rule of the database (the column limit, the integer and the
     * existence of the credential), checked for every project, or checked
     * for git projects only. Every rule with a check in the browser runs it
     * only while the form has Git chosen (whenClient), as the form hides
     * the Git fields otherwise: the server checks them, and the form shows
     * the server's errors of hidden Git fields above itself.
     *
     * regression: the credential type was checked for manual projects too,
     * and the browser checked the length of the hidden URL and Branch fields
     * and the credential ID while another type was chosen. It counts
     * UTF-16 code units where the server counts characters, so a stored
     * branch of 100 characters outside the Basic Multilingual Plane (200
     * code units, within the 128 characters of the column) kept the Edit
     * form of a manual project from being sent, with the error on a hidden
     * field.
     */
    public function testRegressionEveryRuleOfAGitFieldIsADatabaseRuleOrForGitProjectsOnly(): void
    {
        $gitChosen = (new \ReflectionClass(Project::class))->getConstant('GIT_CHOSEN_IN_THE_FORM');
        $this->assertIsString($gitChosen);
        $view = new View();
        // The tests have no web root: the validators' asset bundle is a dummy.
        $view->setAssetManager(new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        $project = new Project();
        $databaseRules = [];
        $gitRules = [];
        foreach (self::GIT_FIELDS as $attribute) {
            foreach ($project->getActiveValidators($attribute) as $validator) {
                $label = $attribute . ', ' . $validator::class;
                if ($this->isDatabaseRule($validator)) {
                    $this->assertNull($validator->when, $label . ': checked for every project');
                    $databaseRules[] = $label;
                } else {
                    $this->assertCheckedForGitProjectsOnly($validator, $attribute, $label);
                    $gitRules[] = $label;
                }
                if ((string)$validator->clientValidateAttribute($project, $attribute, $view) !== '') {
                    $this->assertSame($gitChosen, $validator->whenClient, $label . ': checked in the browser only while Git is chosen');
                }
            }
        }

        $this->assertCount(4, $databaseRules, implode('; ', $databaseRules));
        $this->assertCount(3, $gitRules, implode('; ', $gitRules));
        $this->assertSame(self::GIT_FIELDS, Project::GIT_FIELDS, 'the fields gitFieldErrors() reads');
    }

    private function assertCheckedForGitProjectsOnly(Validator $validator, string $attribute, string $label): void
    {
        $when = $validator->when;
        $this->assertIsCallable($when, $label . ': checked for git projects only');
        foreach ([Project::SCM_TYPE_GIT => true, Project::SCM_TYPE_MANUAL => false, 'svn' => false] as $scmType => $checked) {
            $typed = new Project();
            $typed->scm_type = $scmType;
            $this->assertSame($checked, $when($typed, $attribute), $label . ': ' . $scmType);
        }
    }

    /** A rule the database needs: a column limit, the integer or the existence of the credential. */
    private function isDatabaseRule(Validator $validator): bool
    {
        if ($validator instanceof StringValidator) {
            return $validator->max !== null && $validator->min === null && $validator->length === null;
        }
        if ($validator instanceof NumberValidator) {
            return $validator->integerOnly && $validator->min === null && $validator->max === null;
        }

        return $validator instanceof ExistValidator;
    }

    /** A new project of $scmType, with the Git fields the form sends. */
    private function project(string $scmType, ?string $url, ?int $credentialId): Project
    {
        $project = new Project();
        $project->name = 'git-fields-' . uniqid('', true);
        $project->scm_type = $scmType;
        $project->scm_url = $url;
        $project->scm_branch = 'main';
        $project->scm_credential_id = $credentialId;
        $project->local_path = self::LOCAL_PATH;
        $project->status = Project::STATUS_NEW;

        return $project;
    }

    private function credentialOf(string $type): int
    {
        return (int)$this->createCredential((int)$this->createUser('git-fields')->id, $type)->id;
    }
}
