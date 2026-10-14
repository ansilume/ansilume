<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Project;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * POST and PUT /api/v1/projects check the type of a project's credential
 * against its URL for git projects only, as the model decides: a git
 * project with an SSH URL needs an SSH Key credential, one with an HTTPS URL
 * a Token or Username/Password credential, or the answer is 422 with the
 * refusal. A manual project's URL and credential reach no git: they are
 * saved as sent. A credential that does not exist is refused for every
 * project.
 *
 * regression: the API answered 422 "SSH URLs require an SSH Key
 * credential." for a manual project with an SSH URL and a Token credential.
 */
class ProjectsGitFieldsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const SSH_URL = 'git@git.example.com:org/playbooks.git';

    private const HTTPS_URL = 'https://git.example.com/org/playbooks.git';

    /** The answer for an SSH URL with a credential of another type. */
    private const SSH_REFUSED = ['error' => ['message' => 'SSH URLs require an SSH Key credential.']];

    /** The answer for an HTTPS URL with a credential of another type. */
    private const HTTPS_REFUSED = ['error' => ['message' => 'HTTPS URLs require a Token or Username/Password credential.']];

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
     * A credential of another type than the URL needs, and the answer for a
     * git project with it.
     *
     * @return array<string, array{0: string, 1: string, 2: array{error: array{message: string}}}>
     */
    public static function mismatchProvider(): array
    {
        return [
            'an SSH URL and a Token credential' => [self::SSH_URL, Credential::TYPE_TOKEN, self::SSH_REFUSED],
            'an SSH URL and a Username/Password credential' => [self::SSH_URL, Credential::TYPE_USERNAME_PASSWORD, self::SSH_REFUSED],
            'an HTTPS URL and an SSH Key credential' => [self::HTTPS_URL, Credential::TYPE_SSH_KEY, self::HTTPS_REFUSED],
        ];
    }

    /**
     * regression: the old model answered 422 with the refusal of a git
     * project and saved nothing.
     *
     * @dataProvider mismatchProvider
     */
    public function testRegressionPostOfAManualProjectWithACredentialOfAnotherTypeSavesIt(string $url, string $type): void
    {
        $operator = $this->createUserWithRole('git-fields-api-creator', 'operator');
        $credential = $this->createCredential((int)$operator->id, $type);
        $this->authenticate($operator);
        $name = 'git-fields-api-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'scm_url' => $url, 'scm_credential_id' => $credential->id]);

        $answer = $this->callApi('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $data['scm_type'] ?? null);
        $this->assertSame($url, $data['scm_url'] ?? null);
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame((int)$credential->id, (int)$project->scm_credential_id, 'stored as sent');
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_CREATED, 'object_id' => $project->id]));
    }

    /**
     * regression: the old model answered 422 for every PUT of a manual
     * project stored with such a credential, whatever it changed, and for
     * such a credential sent for a manual project.
     */
    public function testRegressionPutOfAManualProjectWithACredentialOfAnotherTypeSavesIt(): void
    {
        $operator = $this->createUserWithRole('git-fields-api-editor', 'operator');
        $token = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
        $stored = $this->project((int)$operator->id, Project::SCM_TYPE_MANUAL, self::SSH_URL, (int)$token->id);
        $sent = $this->project((int)$operator->id, Project::SCM_TYPE_MANUAL, null, null);
        $this->authenticate($operator);
        $renamed = $stored->name . '-renamed';

        $this->setBody(['name' => $renamed]);
        $answer = $this->callApi('update', ['id' => $stored->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($renamed, $this->reload($stored)->name);
        $this->assertSame((int)$token->id, (int)$this->reload($stored)->scm_credential_id, 'the stored credential is kept');

        $this->setBody(['scm_url' => self::SSH_URL, 'scm_credential_id' => $token->id]);
        $answer = $this->callApi('update', ['id' => $sent->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(self::SSH_URL, $this->reload($sent)->scm_url);
        $this->assertSame((int)$token->id, (int)$this->reload($sent)->scm_credential_id);
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
    }

    /**
     * A git project is checked as before: 422 with the refusal, nothing
     * saved or audited, no sync queued.
     *
     * @dataProvider mismatchProvider
     * @param array{error: array{message: string}} $refused
     */
    public function testPostOfAGitProjectWithACredentialOfAnotherTypeIsRefused(string $url, string $type, array $refused): void
    {
        $operator = $this->createUserWithRole('git-fields-api-git', 'operator');
        $credential = $this->createCredential((int)$operator->id, $type);
        $this->authenticate($operator);
        $name = 'git-fields-api-git-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => $url, 'scm_credential_id' => $credential->id]);

        $answer = $this->callApi('create');

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($refused, $answer);
        $this->assertContains($answer['error']['message'] ?? null, [Project::SSH_URL_NEEDS_SSH_KEY, Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD]);
        $this->assertNull(Project::findOne(['name' => $name]), 'nothing is saved');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_CREATED])->andWhere(['like', 'metadata', $name])->count());
    }

    /**
     * A PUT that gives a git project a credential of another type than its
     * URL needs is refused as before, and so is one that makes such a
     * manual project a git project, until it sends a credential of the type
     * the URL needs.
     */
    public function testPutOfAGitProjectWithACredentialOfAnotherTypeIsRefused(): void
    {
        $operator = $this->createUserWithRole('git-fields-api-switcher', 'operator');
        $token = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
        $sshKey = $this->createCredential((int)$operator->id, Credential::TYPE_SSH_KEY);
        $git = $this->project((int)$operator->id, Project::SCM_TYPE_GIT, self::HTTPS_URL, (int)$token->id);
        $manual = $this->project((int)$operator->id, Project::SCM_TYPE_MANUAL, self::SSH_URL, (int)$token->id);
        $this->authenticate($operator);

        $this->setBody(['scm_credential_id' => $sshKey->id]);
        $refused = $this->callApi('update', ['id' => $git->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($refused));
        $this->assertSame(self::HTTPS_REFUSED, $refused);
        $this->assertSame((int)$token->id, (int)$this->reload($git)->scm_credential_id, 'the stored credential is kept');

        $this->setBody(['scm_type' => Project::SCM_TYPE_GIT]);
        $refused = $this->callApi('update', ['id' => $manual->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($refused));
        $this->assertSame(self::SSH_REFUSED, $refused);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $this->reload($manual)->scm_type, 'the project stays manual');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => [$git->id, $manual->id]]));

        $this->setBody(['scm_type' => Project::SCM_TYPE_GIT, 'scm_credential_id' => $sshKey->id]);
        $saved = $this->callApi('update', ['id' => $manual->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($saved));
        $this->assertSame(Project::SCM_TYPE_GIT, $this->reload($manual)->scm_type);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    /**
     * A credential that does not exist is refused for every project, as
     * the database needs it, with the error of the existence rule.
     */
    public function testACredentialThatDoesNotExistIsRefusedForEveryProject(): void
    {
        $operator = $this->createUserWithRole('git-fields-api-missing', 'operator');
        $credential = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
        $missing = (int)$credential->id;
        $credential->delete();
        $this->authenticate($operator);

        foreach ([Project::SCM_TYPE_MANUAL, Project::SCM_TYPE_GIT] as $scmType) {
            $name = 'git-fields-api-missing-' . uniqid('', true);
            $this->setBody(['name' => $name, 'scm_type' => $scmType, 'scm_url' => self::HTTPS_URL, 'scm_credential_id' => $missing]);

            $answer = $this->callApi('create');

            $this->assertSame(422, \Yii::$app->response->statusCode, $scmType . ': ' . json_encode($answer));
            $this->assertSame(['error' => ['message' => 'Scm Credential Id is invalid.']], $answer, $scmType);
            $this->assertNull(Project::findOne(['name' => $name]), $scmType . ': nothing is saved');
        }
        $this->assertSame(0, $this->syncs->queued);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'git-fields-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
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

    /**
     * The action of the API controller, through its filters, as the caller
     * of the bearer token.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    private function callApi(string $action, array $params = []): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $result = (new ProjectsController('api/v1/projects', $app))->runAction($action, $params);
        $this->assertIsArray($result);

        return $result;
    }

    private function project(int $createdBy, string $scmType, ?string $url, ?int $credentialId): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_type = $scmType;
        $project->scm_url = $url;
        $project->scm_credential_id = $credentialId;
        $project->save(false);

        return $project;
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertInstanceOf(Project::class, $stored);

        return $stored;
    }
}
