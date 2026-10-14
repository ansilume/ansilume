<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;
use app\tests\unit\GitRemoteFixture;

/**
 * What docs/updating.md and troubleshooting.md show of a sync of the server
 * is what a real sync shows (ProjectService with real git, against a
 * repository that GitRemoteFixture serves at the docs' URL): the rows of
 * git remote set-url and of the fetch in the sync output of an existing
 * checkout, and the error of a branch that starts with "-"; a manual
 * project is synced without git, whatever its branch. The read-only query
 * under **Security: a project's branch read as an option of git** lists
 * exactly the git projects whose branch starts with "-" or contains
 * whitespace, those that saving refuses for their branch (Project's rule),
 * and operators may change projects by default, as the item says.
 *
 * Regression (wave 5f of 2.11.0): the query listed only branches that
 * start with "-", not those with whitespace, which saving refuses too.
 * Fails on the docs from before.
 */
class GitSyncBehaviourDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use RunsTheUpdateSectionQueries;

    /** The URL of the docs' examples; test values, not secrets. */
    private const URL = 'https://deploy:token@git.example.com/org/repo.git';

    /** The checkout of the docs' examples. */
    private const DOCS_CHECKOUT = '/var/www/runtime/projects/5';

    /** The comment the query of the branch item starts with. */
    private const COMMENT = '-- Git projects whose branch starts with "-" (which git read as one of its options) or contains whitespace';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'name', 'scm_branch', 'updated_at'];

    private GitRemoteFixture $remotes;

    /** @var mixed the dispatcher definition the test replaced */
    private mixed $originalDispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();
        $components = \Yii::$app->getComponents(true);
        $this->originalDispatcher = $components['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->remotes = new GitRemoteFixture(sys_get_temp_dir() . '/docs-git-sync-' . uniqid('', true));
        $this->remotes->add(self::URL, "first\n");
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        $this->remotes->remove();
        parent::tearDown();
    }

    /**
     * The sync of an existing checkout shows the rows the docs quote, and a
     * branch that starts with "-" fails it with the error they quote.
     */
    public function testASyncShowsWhatTheDocsQuote(): void
    {
        $project = $this->gitProject('main');
        $dest = $this->remotes->dir . '/project-' . $project->id;
        $this->service()->sync($project);
        $this->service()->sync($project);

        $updating = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $rows = $this->rows($project);
        $quoted = [
            '$ git -C ' . self::DOCS_CHECKOUT . ' remote set-url -- origin https://***@git.example.com/org/repo.git',
            '$ git -C ' . self::DOCS_CHECKOUT . ' fetch --progress --depth 1 -- origin main',
        ];
        foreach ($quoted as $row) {
            $this->assertMentions('`' . $row . '`', $updating, 'updating.md');
            $this->assertContains(str_replace(self::DOCS_CHECKOUT, $dest, $row) . "\n", $rows, 'The sync output shows the row as updating.md quotes it.');
        }

        $project->scm_branch = '-x';
        $project->save(false);
        $error = null;
        try {
            $this->service()->sync($project);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        $failure = sprintf(ProjectService::GIT_FAILED, 128, "fatal: couldn't find remote ref -x");
        $this->assertSame($failure, $error, 'git takes a branch that starts with "-" for a branch name.');
        $this->assertMentions('`' . $failure . '`', $updating, 'updating.md');
        $this->assertMentions('`' . $failure . '`', $this->flat('docs/troubleshooting.md', '## Project sync fails: the branch starts with "-"'), 'troubleshooting.md');
        $this->assertSame(Project::STATUS_ERROR, Project::findOne($project->id)?->status);
    }

    /**
     * regression (wave 5f): the query listed no branch with whitespace. It
     * lists the git projects whose branch starts with "-" and those whose
     * branch contains whitespace, in the order of their IDs, and no git
     * project with a branch the rule takes, nor a manual project: exactly
     * the projects that saving refuses for their branch.
     */
    public function testRegressionTheBranchQueryListsTheGitProjectsWhoseBranchStartsWithADashOrContainsWhitespace(): void
    {
        $listed = [];
        foreach (['-x', '--quiet', 'feature x', ' main', "main\t", "main\n", "a\u{00A0}b"] as $branch) {
            $listed[] = $this->gitProject($branch);
        }
        $notListed = [$this->manualProject('-x'), $this->manualProject('feature x')];
        foreach (['main', 'feature-x', 'hotfix-', 'release/1.2', ''] as $branch) {
            $notListed[] = $this->gitProject($branch);
        }
        $ids = array_map(static fn (Project $project): int => (int)$project->id, [...$listed, ...$notListed]);

        $rows = $this->rowsWhere(self::COMMENT, 'id', $ids);

        $this->assertSame(array_map(static fn (Project $project): int => (int)$project->id, $listed), array_map(static fn (array $row): int => (int)$row['id'], $rows));
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertSame(['-x', '--quiet', 'feature x', ' main', "main\t", "main\n", "a\u{00A0}b"], array_column($rows, 'scm_branch'));
        foreach ([...$listed, ...$notListed] as $project) {
            $this->assertSame(
                in_array($project, $listed, true),
                !$project->validate(['scm_branch']),
                'Listed exactly when saving refuses the branch: ' . (string)json_encode([$project->scm_type, $project->scm_branch])
            );
        }
    }

    /**
     * A manual project is synced without git, whatever its branch: no sync
     * reads the branch of a manual project, as the branch item says.
     */
    public function testAManualProjectIsSyncedWithoutReadingItsBranch(): void
    {
        $manual = $this->manualProject('-x');
        $this->service()->sync($manual);

        $this->assertSame(Project::STATUS_SYNCED, Project::findOne($manual->id)?->status);
        $this->assertDirectoryDoesNotExist($this->remotes->dir . '/project-' . $manual->id, 'No checkout, no git.');
        $this->assertSame([], $this->rows($manual), 'No git command.');
    }

    public function testOperatorsMayChangeProjectsByDefault(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $this->assertArrayHasKey('project.update', $auth->getPermissionsByRole('operator'), 'Whoever could change a project, as operators can by default.');
    }

    /**
     * A manual project with $branch, as a form with a hidden Branch field or
     * the API saves it, and the URL of the examples.
     */
    private function manualProject(string $branch): Project
    {
        $project = $this->gitProject($branch);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->save(false);

        return $project;
    }

    private function gitProject(string $branch): Project
    {
        $project = $this->createProject((int)$this->createUser('docs-git-sync')->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::URL;
        $project->scm_branch = $branch;
        $project->save(false);

        return $project;
    }

    /**
     * The service as the queue-worker runs it, with its checkouts in the
     * fixture's directory and git's environment extended by the fixture's
     * URL rewrites.
     */
    private function service(): ProjectService
    {
        return new class ($this->remotes) extends ProjectService {
            public function __construct(private readonly GitRemoteFixture $remotes)
            {
                parent::__construct();
            }

            public function localPath(Project $project): string
            {
                return $this->remotes->dir . '/project-' . $project->id;
            }

            protected function buildGitEnv(Project $project, ?string &$keyFile): array
            {
                $env = parent::buildGitEnv($project, $keyFile);
                $env['HOME'] = $this->remotes->dir . '/home';
                $count = (int)$env['GIT_CONFIG_COUNT'];
                foreach ($this->remotes->rewrites() as $key => $value) {
                    $env['GIT_CONFIG_KEY_' . $count] = $key;
                    $env['GIT_CONFIG_VALUE_' . $count] = $value;
                    $count++;
                }
                $env['GIT_CONFIG_COUNT'] = (string)$count;

                return $env;
            }
        };
    }

    /**
     * @return list<string> the rows of $project's sync output, in order
     */
    private function rows(Project $project): array
    {
        $rows = [];
        foreach (ProjectSyncLog::find()->where(['project_id' => $project->id])->orderBy(['sequence' => SORT_ASC])->all() as $row) {
            /** @var ProjectSyncLog $row */
            $rows[] = (string)$row->content;
        }

        return $rows;
    }
}
