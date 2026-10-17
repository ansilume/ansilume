<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Project;
use app\services\ProjectService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\helpers\Html;
use yii\web\Response;
use yii\web\View;

/**
 * The project form hides the Git fields (URL, Branch, Credential) unless
 * Git is chosen, and sends them all the same. What they mean to git is
 * checked for git projects only, so a manual project is saved with the Git
 * fields typed under Git. The database's rules of those fields hold for
 * every project: the browser checks them only while Git is chosen, and the
 * form shows the server's errors of hidden Git fields above itself.
 *
 * regression: the credential type was checked for every project, so a New
 * Project with an SSH URL and a Token credential chosen under Git, then
 * switched to Manual (no SCM), was refused with "SSH URLs require an SSH
 * Key credential." under the hidden Credential field: nothing visible,
 * nothing saved.
 */
class ProjectGitFieldsTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;
    use TeamScopeFixtures;

    private const SSH_URL = 'git@git.example.com:org/playbooks.git';

    private const HTTPS_URL = 'https://git.example.com/org/playbooks.git';

    /** The local path of the manual projects: saving one needs no such directory. */
    private const LOCAL_PATH = '/opt/playbooks/git-fields';

    /** Project::GIT_FIELDS_REFUSED, the line above the errors of hidden Git fields. */
    private const HIDDEN_ERRORS = 'Not saved: the hidden Git fields have errors. Choose Git under Scm Type to see and correct them, then switch back and save.';

    /** The id of the box above the form with the errors of hidden Git fields. */
    private const HIDDEN_ERRORS_ID = 'project-git-field-errors';

    /** @var ProjectService&object{queued: int} */
    private ProjectService $syncs;

    protected function setUp(): void
    {
        parent::setUp();
        // A saved git project queues a sync: here it is only counted.
        $this->syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        $this->swapComponent('projectService', $this->syncs);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * A credential of another type than the URL needs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mismatchProvider(): array
    {
        return [
            'an SSH URL and a Token credential' => [self::SSH_URL, Credential::TYPE_TOKEN],
            'an SSH URL and a Username/Password credential' => [self::SSH_URL, Credential::TYPE_USERNAME_PASSWORD],
            'an HTTPS URL and an SSH Key credential' => [self::HTTPS_URL, Credential::TYPE_SSH_KEY],
        ];
    }

    /**
     * regression: the old model refused the project, with the error under
     * the hidden Credential field, and saved nothing.
     *
     * @dataProvider mismatchProvider
     */
    public function testRegressionTheNewProjectFormSavesAManualProjectWithACredentialChosenUnderGit(string $url, string $type): void
    {
        $operator = $this->createUserWithRole('git-fields-creator', 'operator');
        $credential = $this->createCredential((int)$operator->id, $type);
        $this->loginAs($operator);
        $name = 'git-fields-new-' . uniqid('', true);
        // The form sends the hidden Git fields too, as they were filled under Git.
        $this->setPost(['Project' => $this->manualForm($name, $url, (string)$credential->id)]);

        $saved = $this->runProjectAction('create', '/project/create', []);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the new project');
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $this->assertSame($url, $project->scm_url, 'stored as sent');
        $this->assertSame((int)$credential->id, (int)$project->scm_credential_id, 'stored as sent');
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_CREATED, 'object_id' => $project->id]));
    }

    /**
     * regression: the Edit form of such a manual project sends its stored
     * Git fields back, hidden, and the old model refused every save of it.
     */
    public function testRegressionTheEditFormSavesSuchAStoredManualProject(): void
    {
        $operator = $this->createUserWithRole('git-fields-editor', 'operator');
        $credential = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
        $project = $this->manualProject((int)$operator->id, self::SSH_URL, (int)$credential->id);
        $this->loginAs($operator);

        $form = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);
        $this->assertIsString($form);
        $this->assertStringContainsString('<div id="git-fields" style="display:none">', $form, 'the Git fields are hidden');
        $this->assertStringContainsString('<option value="' . $credential->id . '" selected>', $form, 'the stored credential is sent back');

        $this->setPost(['Project' => ['description' => 'saved with its hidden Git fields'] + $this->manualForm($project->name, self::SSH_URL, (string)$credential->id)]);
        $saved = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the project');
        $stored = $this->reload($project);
        $this->assertSame('saved with its hidden Git fields', $stored->description);
        $this->assertSame(self::SSH_URL, $stored->scm_url);
        $this->assertSame((int)$credential->id, (int)$stored->scm_credential_id);
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    /**
     * Every save that makes a project a git project checks the credential
     * type: such a manual project switched to Git is refused with the field
     * error, which the form shows under the Credential field with the Git
     * fields. Nothing is saved or audited and no sync is queued, until the
     * project gets a credential of the type its URL needs.
     */
    public function testSwitchingSuchAManualProjectToGitShowsTheCredentialError(): void
    {
        $operator = $this->createUserWithRole('git-fields-switcher', 'operator');
        $token = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
        $project = $this->manualProject((int)$operator->id, self::SSH_URL, (int)$token->id);
        $this->loginAs($operator);
        $this->setPost(['Project' => $this->gitForm($project->name, self::SSH_URL, (string)$token->id)]);

        $page = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertIsString($page, 'the form is shown again, not a redirect to the project');
        $this->assertShowsTheCredentialError($page, Project::SSH_URL_NEEDS_SSH_KEY);
        $this->assertMatchesRegularExpression('#<div id="git-fields"\s*>#', $page, 'the Git fields, Credential among them, are shown');
        $this->assertStringNotContainsString(self::HIDDEN_ERRORS_ID, $page, 'nothing above the form: the field shows its error');
        $this->assertSame(Project::SCM_TYPE_MANUAL, $this->reload($project)->scm_type, 'the project stays manual');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));

        $sshKey = $this->createCredential((int)$operator->id, Credential::TYPE_SSH_KEY);
        $this->setPost(['Project' => $this->gitForm($project->name, self::SSH_URL, (string)$sshKey->id)]);
        $saved = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the project');
        $stored = $this->reload($project);
        $this->assertSame(Project::SCM_TYPE_GIT, $stored->scm_type);
        $this->assertSame((int)$sshKey->id, (int)$stored->scm_credential_id);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    /**
     * The database's rules of the Git fields hold for every project. The
     * form keeps the URL and the branch within their columns and offers
     * existing credentials only: a crafted request, or a credential deleted
     * while the form was open, breaks them.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function brokenColumnProvider(): array
    {
        return [
            'a credential deleted while the form was open' => ['scm_credential_id', 'deleted', 'Scm Credential Id is invalid.'],
            'a credential ID that is no integer' => ['scm_credential_id', 'abc', 'Scm Credential Id must be an integer.'],
            'a URL too long for its column' => ['scm_url', 'https://git.example.com/' . str_repeat('a', 489), 'Scm Url should contain at most 512 characters.'],
            'a branch too long for its column' => ['scm_branch', str_repeat('b', 129), 'Scm Branch should contain at most 128 characters.'],
        ];
    }

    /**
     * regression: the old form showed such an error only under its field,
     * which it hides for Manual (no SCM): nothing visible, nothing saved.
     *
     * @dataProvider brokenColumnProvider
     */
    public function testRegressionTheErrorOfAHiddenGitFieldIsShownAboveTheForm(string $attribute, string $value, string $error): void
    {
        $operator = $this->createUserWithRole('git-fields-hidden-error', 'operator');
        if ($value === 'deleted') {
            $credential = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
            $value = (string)$credential->id;
            $credential->delete();
        }
        $this->loginAs($operator);
        $name = 'git-fields-hidden-error-' . uniqid('', true);
        $this->setPost(['Project' => [$attribute => $value] + $this->manualForm($name, self::HTTPS_URL, '')]);

        $page = $this->runProjectAction('create', '/project/create', []);

        $this->assertIsString($page, 'the form is shown again, not a redirect to a new project');
        $this->assertNull(Project::findOne(['name' => $name]), 'nothing is saved');
        $this->assertStringContainsString('<div id="git-fields" style="display:none">', $page, 'the Git fields are hidden, with the error under its field');
        $box = $this->hiddenErrorsBox($page);
        $this->assertStringContainsString(Html::encode(self::HIDDEN_ERRORS), $box);
        $this->assertSame(['<li>' . Html::encode($error) . '</li>'], $this->listItems($box), 'the error, once');
        $this->assertSame(self::HIDDEN_ERRORS, Project::GIT_FIELDS_REFUSED);
    }

    /**
     * A git project shows such an error under its field, which the form
     * shows: nothing above the form.
     */
    public function testTheErrorOfAShownGitFieldIsShownUnderItsField(): void
    {
        $this->loginAs($this->createUserWithRole('git-fields-shown-error', 'operator'));
        $name = 'git-fields-shown-error-' . uniqid('', true);
        $this->setPost(['Project' => ['scm_credential_id' => 'abc'] + $this->gitForm($name, self::HTTPS_URL, '')]);

        $page = $this->runProjectAction('create', '/project/create', []);

        $this->assertIsString($page);
        $this->assertNull(Project::findOne(['name' => $name]), 'nothing is saved');
        $this->assertShowsTheCredentialError($page, 'Scm Credential Id must be an integer.');
        $this->assertStringNotContainsString(self::HIDDEN_ERRORS_ID, $page);
    }

    /**
     * A project of an unknown type, from a crafted request: the form hides
     * the Git fields, as for every type but Git, so their errors are shown
     * above it, beside the error of the type.
     */
    public function testTheErrorOfAHiddenGitFieldOfAProjectOfAnUnknownTypeIsShownAboveTheForm(): void
    {
        $this->loginAs($this->createUserWithRole('git-fields-unknown-type', 'operator'));
        $this->setPost(['Project' => ['scm_type' => 'svn', 'scm_credential_id' => 'abc'] + $this->manualForm('git-fields-svn-' . uniqid('', true), self::HTTPS_URL, '')]);

        $page = $this->runProjectAction('create', '/project/create', []);

        $this->assertIsString($page);
        $this->assertStringContainsString('Scm Type is invalid.', $page);
        $this->assertSame(['<li>' . Html::encode('Scm Credential Id must be an integer.') . '</li>'], $this->listItems($this->hiddenErrorsBox($page)));
    }

    /**
     * The form keeps the URL and the branch within their columns: the
     * browser takes no more characters than the model's length rule
     * allows. The Git fields of the form are the fields of
     * Project::GIT_FIELDS, whose errors are shown above the form while they
     * are hidden.
     */
    public function testTheFormKeepsTheGitFieldsWithinTheirColumns(): void
    {
        $this->loginAs($this->createUserWithRole('git-fields-form', 'operator'));

        ['page' => $page] = $this->newProjectForm();

        $this->assertMatchesRegularExpression('#<input type="text" id="project-scm_url"[^>]*maxlength="512"#', $page);
        $this->assertMatchesRegularExpression('#<input type="text" id="project-scm_branch"[^>]*maxlength="128"#', $page);
        $start = strpos($page, '<div id="git-fields"');
        $end = strpos($page, '<div id="manual-fields"');
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        preg_match_all('#name="Project\[(\w+)\]"#', substr($page, $start, $end - $start), $fields);
        $this->assertSame(Project::GIT_FIELDS, $fields[1], 'the fields inside #git-fields');
        $this->assertStringContainsString(
            '<div class="hint-block">' . Html::encode(Project::SSH_URL_NEEDS_SSH_KEY . ' ' . Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD) . '</div>',
            $page,
            'the Credential field names both refusals'
        );
    }

    /**
     * regression: the browser checked the hidden Git fields while another
     * type was chosen: their length, in UTF-16 code units where the server
     * counts characters, and the credential ID. A manual project whose
     * stored branch is 100 characters outside the Basic Multilingual Plane
     * (200 code units, within the 128 characters of the column) could not
     * be sent from its Edit form, with the error under the hidden Branch
     * field. Every check of a Git field now asks the model's condition
     * (whenClient) first, which reads the form's Scm Type field; the other
     * fields are checked whatever the type.
     */
    public function testRegressionTheBrowserChecksTheGitFieldsOnlyWhileGitIsChosen(): void
    {
        $this->loginAs($this->createUserWithRole('git-fields-browser', 'operator'));
        $gitChosen = (new \ReflectionClass(Project::class))->getConstant('GIT_CHOSEN_IN_THE_FORM');
        $this->assertIsString($gitChosen);
        $condition = 'if ((' . $gitChosen . ')(attribute, value)) { yii.validation.';

        ['script' => $script] = $this->newProjectForm();

        $checks = $this->checksByField($script);
        foreach (['scm_url' => 1, 'scm_branch' => 2, 'scm_credential_id' => 1] as $field => $count) {
            $this->assertSame($count, substr_count($checks[$field] ?? '', 'yii.validation.'), $field . ': its checks in the browser');
            $this->assertSame($count, substr_count($checks[$field] ?? '', $condition), $field . ': every check asks whether Git is chosen');
        }
        foreach (['name', 'scm_type', 'local_path'] as $field) {
            $this->assertGreaterThan(0, substr_count($checks[$field] ?? '', 'yii.validation.'), $field . ': checked in the browser');
            $this->assertSame(0, substr_count($checks[$field] ?? '', $gitChosen), $field . ': checked whatever the type');
        }
    }

    /**
     * The script of the form, cut into the checks of each field: the part
     * of the yiiActiveForm attribute list from the field's entry to the next.
     *
     * @return array<string, string>
     */
    private function checksByField(string $script): array
    {
        $checks = [];
        foreach (preg_split('/(?=\{"id":"project-)/', $script) ?: [] as $part) {
            if (preg_match('/^\{"id":"project-(\w+)"/', $part, $match) === 1) {
                $checks[$match[1]] = $part;
            }
        }

        return $checks;
    }

    /** The box above the form with the errors of hidden Git fields. */
    private function hiddenErrorsBox(string $page): string
    {
        $found = preg_match('#<div class="alert alert-danger" id="' . self::HIDDEN_ERRORS_ID . '" role="alert">(.*?)</div>#s', $page, $match, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, 'the errors of the hidden Git fields are shown above the form');
        $form = strpos($page, '<form id="project-form"');
        $this->assertIsInt($form);
        $this->assertLessThan($form, $match[0][1], 'above the form');

        return $match[1][0];
    }

    /**
     * @return list<string>
     */
    private function listItems(string $box): array
    {
        preg_match_all('#<li>.*?</li>#s', $box, $items);

        return $items[0];
    }

    private function assertShowsTheCredentialError(string $page, string $error): void
    {
        $this->assertMatchesRegularExpression(
            '#<div class="form-group field-project-scm_credential_id[^"]*has-error">\s*<label[^>]*for="project-scm_credential_id"[^>]*>[^<]*</label>\s*'
            . '<select id="project-scm_credential_id"[^>]*aria-invalid="true">.*?</select>\s*<div class="hint-block">[^<]*</div>\s*'
            . preg_quote('<div class="help-block">' . Html::encode($error) . '</div>', '#') . '#s',
            $page,
            'The Credential field shows the error.'
        );
    }

    /**
     * The fields of the form for a manual project, the hidden Git fields
     * included, as the browser sends them.
     *
     * @return array<string, string>
     */
    private function manualForm(string $name, string $url, string $credentialId): array
    {
        return [
            'name' => $name,
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'scm_url' => $url,
            'scm_branch' => 'main',
            'scm_credential_id' => $credentialId,
            'local_path' => self::LOCAL_PATH,
            'vault_password_source' => Project::VAULT_SOURCE_ANSILUME,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function gitForm(string $name, string $url, string $credentialId): array
    {
        return ['scm_type' => Project::SCM_TYPE_GIT] + $this->manualForm($name, $url, $credentialId);
    }

    /** A manual project stored with the Git fields typed under Git. */
    private function manualProject(int $createdBy, string $url, int $credentialId): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_url = $url;
        $project->scm_credential_id = $credentialId;
        $project->local_path = self::LOCAL_PATH;
        $project->save(false);

        return $project;
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertInstanceOf(Project::class, $stored);

        return $stored;
    }

    /**
     * The New Project form, and the script it registered for the browser,
     * as the signed-in user sees them.
     *
     * @return array{page: string, script: string}
     */
    private function newProjectForm(): array
    {
        $ctrl = $this->projectController();

        return $this->withRealViews($ctrl, '/project/create', function () use ($ctrl): array {
            $page = $ctrl->runAction('create');
            $this->assertIsString($page);
            $view = \Yii::$app->view;
            $this->assertInstanceOf(View::class, $view);

            return ['page' => $page, 'script' => implode("\n", $view->js[View::POS_READY] ?? [])];
        });
    }

    /**
     * Runs the action of ProjectController through its filters, as the
     * signed-in user, with the real form view (without the layout).
     *
     * @param array<string, mixed> $params
     */
    private function runProjectAction(string $action, string $url, array $params): mixed
    {
        $ctrl = $this->projectController();

        return $this->withRealViews($ctrl, $url, static fn (): mixed => $ctrl->runAction($action, $params));
    }

    /** ProjectController, rendering its real views without the layout. */
    private function projectController(): ProjectController
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return new class ('project', $app) extends ProjectController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }
}
