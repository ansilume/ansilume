<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\TeamsController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Teams API controller.
 *
 * Exercises authentication, authorization, CRUD operations, member
 * management, and project assignment against a real database with
 * transactions rolled back after each test.
 */
class TeamsControllerTest extends WebControllerTestCase
{
    private TeamsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new TeamsController('api/v1/teams', \Yii::$app);
    }

    // -- Authorization (403) --------------------------------------------------

    public function testIndexRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-admin');
        $this->ctrl->actionIndex();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $admin = $this->authenticateWithAdmin();
        $this->createTeam((int)$admin->id);
        $this->createTeam((int)$admin->id);

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);

        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertGreaterThanOrEqual(2, $meta['total']);
        $this->assertSame(25, $meta['per_page']);

        /** @var array<int, array<string, mixed>> $data */
        $data = $result['data'];
        $first = $data[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('member_count', $first);
        $this->assertArrayHasKey('project_count', $first);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsTeamWithMembersAndProjects(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $member = $this->createUser('member');
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $project = $this->createProject((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, 'operator');

        $data = $this->callSuccess($this->ctrl->actionView((int)$team->id));

        /** @var array<string, mixed> $detail */
        $detail = $data;
        $this->assertSame((int)$team->id, $detail['id']);
        $this->assertArrayHasKey('members', $detail);
        $this->assertArrayHasKey('projects', $detail);
        $this->assertCount(1, $detail['members']);
        $this->assertCount(1, $detail['projects']);

        /** @var array<int, array<string, mixed>> $members */
        $members = $detail['members'];
        $this->assertSame((int)$member->id, $members[0]['user_id']);

        /** @var array<int, array<string, mixed>> $projects */
        $projects = $detail['projects'];
        $this->assertSame((int)$project->id, $projects[0]['project_id']);
        $this->assertSame('operator', $projects[0]['role']);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'name' => 'api-test-team',
            'description' => 'Created via API test',
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $team */
        $team = $data;
        $this->assertSame('api-test-team', $team['name']);
        $this->assertSame('Created via API test', $team['description']);
        $this->assertArrayHasKey('members', $team);
        $this->assertArrayHasKey('projects', $team);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);

        $this->setBody([
            'name' => 'updated-team-name',
            'description' => 'Updated description',
        ]);
        $data = $this->callSuccess($this->ctrl->actionUpdate((int)$team->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $updated */
        $updated = $data;
        $this->assertSame('updated-team-name', $updated['name']);
        $this->assertSame('Updated description', $updated['description']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $member = $this->createUser('del-member');
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $project = $this->createProject((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id);

        $data = $this->callSuccess($this->ctrl->actionDelete((int)$team->id));

        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView((int)$team->id);
    }

    // -- Add Member -----------------------------------------------------------

    public function testAddMemberAddsUser(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $member = $this->createUser('new-member');

        $this->setBody(['user_id' => (int)$member->id]);
        $data = $this->callSuccess($this->ctrl->actionAddMember((int)$team->id));
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $detail */
        $detail = $data;
        $this->assertCount(1, $detail['members']);
    }

    public function testAddMemberRejects422ForDuplicateMember(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $member = $this->createUser('dup-member');
        $this->addTeamMember((int)$team->id, (int)$member->id);

        $this->setBody(['user_id' => (int)$member->id]);
        $this->ctrl->actionAddMember((int)$team->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Remove Member --------------------------------------------------------

    public function testRemoveMemberRemovesUser(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $member = $this->createUser('rm-member');
        $this->addTeamMember((int)$team->id, (int)$member->id);

        $data = $this->callSuccess(
            $this->ctrl->actionRemoveMember((int)$team->id, (int)$member->id)
        );

        /** @var array<string, mixed> $detail */
        $detail = $data;
        $this->assertCount(0, $detail['members']);
    }

    public function testRemoveMemberReturns404ForNonMember(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $nonMember = $this->createUser('non-member');

        $this->ctrl->actionRemoveMember((int)$team->id, (int)$nonMember->id);
        $this->assertSame(404, \Yii::$app->response->statusCode);
    }

    // -- Add Project ----------------------------------------------------------

    public function testAddProjectAssignsProject(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $project = $this->createProject((int)$admin->id);

        $this->setBody([
            'project_id' => (int)$project->id,
            'role' => 'operator',
        ]);
        $data = $this->callSuccess($this->ctrl->actionAddProject((int)$team->id));
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $detail */
        $detail = $data;
        $this->assertCount(1, $detail['projects']);

        /** @var array<int, array<string, mixed>> $projects */
        $projects = $detail['projects'];
        $this->assertSame('operator', $projects[0]['role']);
    }

    // -- Remove Project -------------------------------------------------------

    public function testRemoveProjectUnassignsProject(): void
    {
        $admin = $this->authenticateWithAdmin();
        $team = $this->createTeam((int)$admin->id);
        $project = $this->createProject((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$project->id);

        $data = $this->callSuccess(
            $this->ctrl->actionRemoveProject((int)$team->id, (int)$project->id)
        );

        /** @var array<string, mixed> $detail */
        $detail = $data;
        $this->assertCount(0, $detail['projects']);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Extract the data payload from a success response.
     *
     * @param array<string, mixed> $result
     */
    private function callSuccess(array $result): mixed
    {
        $this->assertArrayHasKey('data', $result);
        return $result['data'];
    }

    /**
     * Create a user with no RBAC role — will fail all permission checks.
     */
    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Create an admin user with full permissions and authenticate.
     */
    private function authenticateWithAdmin(): User
    {
        $user = $this->createUser('api-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'admin-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }
}
