<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectController;
use app\models\AuditLog;
use app\models\Project;
use app\services\ProjectService;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\helpers\Html;
use yii\validators\RegularExpressionValidator;
use yii\web\Response;
use yii\web\View;

/**
 * The project form refuses a branch that starts with "-" or holds
 * whitespace for a git project with the field error Project::BRANCH_FORMAT:
 * nothing is saved or audited and no sync is queued. Up to 2.10.1 git read
 * a branch that starts with "-" as one of its options; since 2.11.0 it takes
 * it for a branch name, so every sync of such a project fails. A manual
 * project's branch never reaches git, and the form hides it: it is not
 * checked.
 *
 * regression: the New Project and Edit forms saved such a branch and queued
 * a sync with it.
 */
class ProjectBranchFormatTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    private const URL = 'https://git.example.com/org/playbooks.git';

    /** The local path of the manual projects: saving one needs no such directory. */
    private const LOCAL_PATH = '/opt/playbooks/branch-format';

    /** The field error as the form shows it, under the Branch field. */
    private const SHOWN_ERROR = '<div class="help-block">The branch cannot start with &quot;-&quot; or contain whitespace.</div>';

    /** @var mixed the project service the test replaced */
    private mixed $originalProjectService = null;

    /** @var ProjectService&object{queued: int} */
    private ProjectService $syncs;

    protected function setUp(): void
    {
        parent::setUp();
        $components = \Yii::$app->getComponents(true);
        $this->originalProjectService = $components['projectService'] ?? null;
        // A saved git project queues a sync: here it is only counted.
        $this->syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        \Yii::$app->set('projectService', $this->syncs);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('projectService', $this->originalProjectService);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedBranchProvider(): array
    {
        return [
            'a dash first' => ['-x'],
            'an option of git' => ['--upload-pack=touch /tmp/pwned'],
            'a space first' => [' main'],
            'a space inside' => ['a b'],
            'a tab' => ["main\tx"],
            'a newline last' => ["main\n"],
        ];
    }

    /**
     * regression: the old model saved the project and queued its sync.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionTheNewProjectFormSavedABranchThatStartsWithADashOrHoldsWhitespace(string $branch): void
    {
        $this->loginAs($this->createUserWithRole('branch-format-creator', 'operator'));
        $name = 'branch-format-new-' . uniqid('', true);
        $this->setPost(['Project' => ['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => $branch]]);

        $page = $this->runProjectAction('create', '/project/create', []);

        $this->assertIsString($page, 'the form is shown again, not a redirect to the new project');
        $this->assertNull(Project::findOne(['name' => $name]), 'nothing is saved');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertShowsTheFieldError($page);
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_CREATED, 'object_type' => 'project'])->andWhere(['like', 'metadata', $name])->count());
    }

    /**
     * regression: the old model saved the branch and queued a sync with it.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionTheEditFormSavedABranchThatStartsWithADashOrHoldsWhitespace(string $branch): void
    {
        $operator = $this->createUserWithRole('branch-format-editor', 'operator');
        $project = $this->gitProject((int)$operator->id, 'main');
        $this->loginAs($operator);
        $this->setPost(['Project' => ['name' => $project->name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => $branch]]);

        $page = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertIsString($page, 'the form is shown again, not a redirect to the project');
        $this->assertSame('main', $this->reload($project)->scm_branch, 'the stored branch is kept');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertShowsTheFieldError($page);
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    /**
     * A project saved with such a branch before 2.11.0 keeps it until it is
     * saved again: the Edit form, which sends the stored branch back, then
     * refuses every change until the branch is changed too.
     */
    public function testAProjectWhoseStoredBranchStartsWithADashIsSavedOnlyWithAnotherBranch(): void
    {
        $operator = $this->createUserWithRole('branch-format-legacy', 'operator');
        $project = $this->gitProject((int)$operator->id, '-x');
        $this->loginAs($operator);
        $renamed = $project->name . '-renamed';

        $this->setPost(['Project' => ['name' => $renamed, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => '-x']]);
        $page = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertIsString($page);
        $this->assertShowsTheFieldError($page);
        $this->assertSame($project->name, $this->reload($project)->name, 'the new name is not saved either');
        $this->assertSame(0, $this->syncs->queued);

        $this->setPost(['Project' => ['name' => $renamed, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => 'main']]);
        $saved = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the project');
        $stored = $this->reload($project);
        $this->assertSame('main', $stored->scm_branch);
        $this->assertSame($renamed, $stored->name);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    /**
     * The Edit form of such a project opens with the stored branch, without
     * an error: only a save checks it.
     */
    public function testTheEditFormOfAProjectWhoseStoredBranchStartsWithADashOpens(): void
    {
        $operator = $this->createUserWithRole('branch-format-opener', 'operator');
        $project = $this->gitProject((int)$operator->id, '-x');
        $this->loginAs($operator);

        $page = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertIsString($page);
        $this->assertStringContainsString('name="Project[scm_branch]" value="-x"', $page);
        $this->assertStringNotContainsString(self::SHOWN_ERROR, $page);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedBranchProvider(): array
    {
        return [
            'main' => ['main'],
            'a branch in a folder' => ['feature/x'],
            'a release' => ['release-1.2'],
            'a commit' => ['0123456789abcdef0123456789abcdef01234567'],
        ];
    }

    /**
     * @dataProvider acceptedBranchProvider
     */
    public function testTheNewProjectFormSavesABranchThatStartsWithNoDashAndHoldsNoWhitespace(string $branch): void
    {
        $this->loginAs($this->createUserWithRole('branch-format-valid', 'operator'));
        $name = 'branch-format-valid-' . uniqid('', true);
        $this->setPost(['Project' => ['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => $branch]]);

        $saved = $this->runProjectAction('create', '/project/create', []);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the new project');
        $this->assertSame($branch, Project::findOne(['name' => $name])?->scm_branch);
        $this->assertSame(1, $this->syncs->queued);
    }

    /**
     * regression (wave 5f): the branch rule applied to manual projects too.
     * The form hides the Branch field for Manual (no SCM) but sends it, so a
     * branch typed under Git, then the type switched to Manual, kept the
     * project from being created, with the error on the hidden field.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionTheNewProjectFormSavesAManualProjectWithABranchTypedUnderGit(string $branch): void
    {
        $this->loginAs($this->createUserWithRole('branch-format-manual-creator', 'operator'));
        $name = 'branch-format-manual-' . uniqid('', true);
        // The form sends the hidden Git fields too.
        $this->setPost(['Project' => [
            'name' => $name,
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'local_path' => self::LOCAL_PATH,
            'scm_url' => self::URL,
            'scm_branch' => $branch,
        ]]);

        $saved = $this->runProjectAction('create', '/project/create', []);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the new project');
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $project->scm_type);
        $this->assertSame($branch, $project->scm_branch, 'kept as sent: nothing reads the branch of a manual project');
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_CREATED, 'object_id' => $project->id]));
    }

    /**
     * regression (wave 5f): the Edit form of a manual project whose stored
     * branch is such a branch, saved through the API before 2.11.0, sends
     * the branch back in the hidden Branch field, and no save went through.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionTheEditFormSavesAManualProjectWhoseStoredBranchIsSuchABranch(string $branch): void
    {
        $operator = $this->createUserWithRole('branch-format-manual-editor', 'operator');
        $project = $this->manualProject((int)$operator->id, $branch);
        $this->loginAs($operator);
        $renamed = $project->name . '-renamed';
        $this->setPost(['Project' => [
            'name' => $renamed,
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'local_path' => self::LOCAL_PATH,
            'scm_url' => '',
            'scm_branch' => $branch,
        ]]);

        $saved = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the project');
        $stored = $this->reload($project);
        $this->assertSame($renamed, $stored->name);
        $this->assertSame($branch, $stored->scm_branch, 'the stored branch is kept');
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    /**
     * Every save that makes a project a git project checks its branch: such
     * a manual project switched to Git is refused with the field error,
     * which the form shows with the Git fields. Nothing is saved or audited
     * and no sync is queued, until the branch is changed too.
     */
    public function testSwitchingSuchAManualProjectToGitShowsTheFieldError(): void
    {
        $operator = $this->createUserWithRole('branch-format-manual-switcher', 'operator');
        $project = $this->manualProject((int)$operator->id, '-x');
        $this->loginAs($operator);
        $this->setPost(['Project' => ['name' => $project->name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => '-x']]);

        $page = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertIsString($page, 'the form is shown again, not a redirect to the project');
        $this->assertShowsTheFieldError($page);
        $this->assertMatchesRegularExpression('#<div id="git-fields"\s*>#', $page, 'the Git fields, Branch among them, are shown');
        $this->assertSame(Project::SCM_TYPE_MANUAL, $this->reload($project)->scm_type, 'the project stays manual');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));

        $this->setPost(['Project' => ['name' => $project->name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => 'main']]);
        $saved = $this->runProjectAction('update', '/project/update?id=' . $project->id, ['id' => $project->id]);

        $this->assertInstanceOf(Response::class, $saved, 'saved: a redirect to the project');
        $stored = $this->reload($project);
        $this->assertSame(Project::SCM_TYPE_GIT, $stored->scm_type);
        $this->assertSame('main', $stored->scm_branch);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    /**
     * The browser checks the branch only while the form's Scm Type field has
     * Git chosen, as the server checks it for git projects only: the check
     * of the Branch field asks the model's condition (whenClient) first,
     * which reads that field (select#project-scm_type) and compares it with
     * the value for which the form's script shows the Git fields.
     */
    public function testTheBrowserChecksTheBranchOnlyWhileTheTypeIsGit(): void
    {
        $this->loginAs($this->createUserWithRole('branch-format-form', 'operator'));
        $condition = null;
        foreach ((new Project())->getActiveValidators('scm_branch') as $validator) {
            if ($validator instanceof RegularExpressionValidator) {
                $condition = $validator->whenClient;
            }
        }
        $this->assertIsString($condition, 'the format check has a condition in the browser');

        ['page' => $page, 'script' => $script] = $this->newProjectForm();

        $this->assertMatchesRegularExpression('#<select id="project-scm_type"[^>]*name="Project\[scm_type\]"[^>]*>\s*<option value="git"#', $page, 'the Scm Type field');
        $this->assertStringContainsString("this.value === 'git'", $page, 'the form shows the Git fields for this value');
        $this->assertStringContainsString("document.getElementById('project-scm_type')", $condition);
        $this->assertStringContainsString(".value === 'git'", $condition);
        $this->assertStringContainsString('if ((' . $condition . ')(attribute, value)) { yii.validation.regularExpression(value, messages, {"pattern":/^[^\s-]\S*$/u,', $script);
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

    private function assertShowsTheFieldError(string $page): void
    {
        $this->assertSame(self::SHOWN_ERROR, '<div class="help-block">' . Html::encode(Project::BRANCH_FORMAT) . '</div>', 'Project::BRANCH_FORMAT, encoded');
        $this->assertMatchesRegularExpression(
            '#<div class="form-group field-project-scm_branch[^"]*has-error">\s*<label[^>]*for="project-scm_branch"[^>]*>[^<]*</label>\s*<input[^>]*name="Project\[scm_branch\]"[^>]*aria-invalid="true">\s*'
            . preg_quote(self::SHOWN_ERROR, '#') . '#',
            $page,
            'The Branch field shows the error.'
        );
    }

    private function gitProject(int $createdBy, string $branch): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::URL;
        $project->scm_branch = $branch;
        $project->save(false);

        return $project;
    }

    /** A manual project stored with $branch, as the API saved one before 2.11.0. */
    private function manualProject(int $createdBy, string $branch): Project
    {
        $project = $this->createProject($createdBy);
        $project->local_path = self::LOCAL_PATH;
        $project->scm_branch = $branch;
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
