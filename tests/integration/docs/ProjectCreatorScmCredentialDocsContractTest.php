<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\ProjectsController;
use app\jobs\SyncProjectJob;
use app\models\ApiToken;
use app\models\Credential;
use app\models\Project;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\unit\docs\InventoryApprovalDocsContractTest;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The limit of the SCM credential that docs/credentials.md (RBAC),
 * docs/updating.md (What an approval rule does not stop, 2.12.0) and
 * CLAUDE.md (security rule 12) state is what the code does: a user who may
 * create projects but not change them (project.create without
 * project.update) creates a git project with a token credential that
 * another user made, through POST /api/v1/projects, and its sync is queued
 * at once, with that credential. So the three texts name whoever may create
 * a project beside whoever may change one, and the advice keeps both
 * permissions for trusted users.
 *
 * Regression (2.12.0 review, SEC-1): the texts named only whoever may
 * change a project (project.update), and advised to restrict that
 * permission alone, which left project.create open. Fails on the docs from
 * before. Once a later release scopes credentials, the behaviour half fails
 * here, and the docs change with it.
 */
class ProjectCreatorScmCredentialDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;
    use RestoresComponents;

    /** The repository of the example; a test value. */
    private const URL = 'https://git.example.com/org/repo.git';

    /** What the three texts said before, naming only whoever may change a project. */
    private const ONLY_CHANGE = 'may change a project (`project.update`) can make its sync';

    /** @var \ArrayObject<int, mixed> the jobs pushed to the queue, in their order */
    private \ArrayObject $pushed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushed = new \ArrayObject();
        $this->swapComponent('queue', new class ($this->pushed) extends \yii\base\Component {
            /**
             * @param \ArrayObject<int, mixed> $pushed
             */
            public function __construct(private readonly \ArrayObject $pushed)
            {
                parent::__construct();
            }

            public function push(mixed $job): ?string
            {
                $this->pushed->append($job);

                return null;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * regression (SEC-1): a role with project.create and without
     * project.update creates a git project with another user's token
     * credential (201), and its sync is queued with that credential; the
     * three texts name project.create beside project.update.
     */
    public function testRegressionTheScmCredentialLimitNamesWhoeverMayCreateAProject(): void
    {
        $owner = $this->createUser('docs-scm-owner');
        $credential = $this->createCredential((int)$owner->id, Credential::TYPE_TOKEN);
        $creator = $this->creatorOnly();
        ['raw' => $raw] = ApiToken::generate((int)$creator->id, 'docs-scm-creator');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        $this->setPost([
            'name' => 'docs-scm-' . uniqid('', true),
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url' => self::URL,
            'scm_branch' => 'main',
            'scm_credential_id' => (int)$credential->id,
        ]);

        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        $answer = (new ProjectsController('api/v1/projects', $app))->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, 'A user who may create projects creates one: ' . (string)json_encode($answer));
        $this->assertIsArray($answer);
        $project = Project::findOne((int)($answer['data']['id'] ?? 0));
        $this->assertNotNull($project);
        $this->assertSame(
            [(int)$creator->id, (int)$credential->id, self::URL],
            [(int)$project->created_by, (int)$project->scm_credential_id, (string)$project->scm_url],
            'The project names the credential of another user and the server of the creator\'s choosing.'
        );
        $jobs = $this->pushed->getArrayCopy();
        $this->assertCount(1, $jobs, 'Its sync is queued at once.');
        $job = $jobs[0];
        $this->assertInstanceOf(SyncProjectJob::class, $job);
        $this->assertSame((int)$project->id, $job->projectId, 'The sync of this project, with that credential.');

        $texts = [
            'credentials.md' => [
                $this->flat('docs/credentials.md', '## RBAC'),
                'Credentials have no team scope. Whoever may create or change a project (`project.create`, `project.update`) can make its sync present any token or username and password credential to a server of their choosing, so grant `project.create` and `project.update` only to users you would trust with every such credential.',
            ],
            'updating.md' => [
                $this->flat('docs/updating.md', InventoryApprovalDocsContractTest::UPDATE_SECTION),
                'Whoever may create or change a project (`project.create`, `project.update`) can make its sync present any token or username and password credential to a server of their choosing, as credentials have no team scope. A later release locks these. Until then grant `project.create`, `project.update` and `credential.update` only to users you would let launch the gated work',
            ],
            'CLAUDE.md' => [
                $this->flat('CLAUDE.md'),
                'whoever may create or change a project can make its sync present any token or username and password credential to a server of their choosing, as credentials are not team-scoped',
            ],
        ];
        foreach ($texts as $where => [$text, $sentence]) {
            $this->assertMentions($sentence, $text, $where);
            $this->assertStringNotContainsStringIgnoringCase(self::ONLY_CHANGE, $text, "{$where} names whoever may create a project too.");
            $this->assertStringNotContainsStringIgnoringCase('may change a project can make its sync', $text, "{$where} names whoever may create a project too.");
        }
    }

    /**
     * A user with a custom role that holds project.create but neither
     * project.update nor credential.view.
     */
    private function creatorOnly(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('docs-project-creator-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        $permission = $auth->getPermission('project.create');
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $creator = $this->createUser('docs-scm-creator');
        $auth->assign($role, (string)$creator->id);
        $this->assertTrue($auth->checkAccess((int)$creator->id, 'project.create'));
        $this->assertFalse($auth->checkAccess((int)$creator->id, 'project.update'), 'The user may not change a project.');
        $this->assertFalse($auth->checkAccess((int)$creator->id, 'credential.view'), 'Nor see credentials.');

        return $creator;
    }
}
