<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\Credential;
use app\models\Project;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Request;

/**
 * What web/openapi.yaml says about ProjectInput.scm_credential_id is what
 * POST /api/v1/projects and PUT /api/v1/projects/{id} answer: for a git
 * project the type of the credential is checked against the URL
 * (Project::SSH_URL_NEEDS_SSH_KEY, Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD),
 * for a manual project it is not, and a credential that does not exist is
 * refused for every project. ProjectsGitFieldsTest covers the API in full.
 *
 * Regression (wave 5g of 2.11.0): the API refused a manual project with an
 * SSH URL and a Token credential, and the spec said nothing of the check.
 * Fails on the spec from before, which documented no description.
 */
class OpenApiProjectCredentialContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use TeamScopeFixtures;

    private const SSH_URL = 'git@git.example.com:org/playbooks.git';

    /**
     * regression: the spec said nothing of the type of the credential. It
     * says the type is checked for git projects only, quoting both
     * refusals, and the API answers so: a manual project with an SSH URL and
     * a Token credential is saved and queues no sync, a git project with
     * them is refused and nothing is saved.
     */
    public function testRegressionTheCredentialTypeIsCheckedForGitProjectsOnlyAsTheSpecSays(): void
    {
        $this->assertSame(
            'The credential that syncs of a git project sign in with. For a git project (`scm_type` `git` once the request is applied), an SSH URL needs an SSH Key credential and an HTTPS URL a Token or Username/Password credential; another type answers 422 `'
                . Project::SSH_URL_NEEDS_SSH_KEY . '` or `' . Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD
                . '` and saves nothing. The credential of a manual project is not checked against its URL, as no sync reads them. A credential that does not exist is refused with 422 for every project.',
            $this->specText(['components', 'schemas', 'ProjectInput', 'properties', 'scm_credential_id', 'description'])
        );
        $this->assertSame('integer', $this->specAt(['components', 'schemas', 'ProjectInput', 'properties', 'scm_credential_id', 'type']));
        $this->assertTrue($this->specAt(['components', 'schemas', 'ProjectInput', 'properties', 'scm_credential_id', 'nullable']));

        // A saved git project would queue a sync: here it is only counted.
        $syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        $components = \Yii::$app->getComponents(true);
        $original = $components['projectService'] ?? null;
        \Yii::$app->set('projectService', $syncs);
        try {
            $operator = $this->createUserWithRole('spec-credential-operator', 'operator');
            $token = $this->createCredential((int)$operator->id, Credential::TYPE_TOKEN);
            $this->authenticate($operator);

            $manual = 'spec-credential-manual-' . uniqid('', true);
            $this->setBody(['name' => $manual, 'scm_type' => Project::SCM_TYPE_MANUAL, 'scm_url' => self::SSH_URL, 'scm_credential_id' => $token->id]);
            $this->callApi('create');
            $this->assertSame(201, \Yii::$app->response->statusCode, 'A manual project with an SSH URL and a Token credential is saved.');
            $this->assertTrue(Project::find()->where(['name' => $manual, 'scm_credential_id' => $token->id])->exists());

            $git = 'spec-credential-git-' . uniqid('', true);
            $this->setBody(['name' => $git, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::SSH_URL, 'scm_credential_id' => $token->id]);
            $this->assertSame(['error' => ['message' => Project::SSH_URL_NEEDS_SSH_KEY]], $this->callApi('create'), 'A git project with them is refused.');
            $this->assertSame(422, \Yii::$app->response->statusCode);
            $this->assertFalse(Project::find()->where(['name' => $git])->exists(), 'Nothing is saved.');
        } finally {
            \Yii::$app->set('projectService', $original);
        }
        $this->assertSame(0, $syncs->queued, 'No sync is queued.');
    }

    /**
     * A credential that does not exist is refused with 422 for a manual
     * project too, as the spec says, and nothing is saved.
     */
    public function testACredentialThatDoesNotExistIsRefusedForEveryProject(): void
    {
        $operator = $this->createUserWithRole('spec-credential-missing', 'operator');
        $this->authenticate($operator);
        $name = 'spec-credential-missing-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'scm_credential_id' => (int)Credential::find()->max('id') + 1000]);

        $this->callApi('create');

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(Project::find()->where(['name' => $name])->exists(), 'Nothing is saved.');
    }

    /**
     * Signs $user in with an API token of their own, for the filters of the
     * API controller.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'spec');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
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
        \Yii::$app->response->statusCode = 200;
        $result = (new ProjectsController('api/v1/projects', $this->app()))->runAction($action, $params);
        $this->assertIsArray($result);

        return $result;
    }
}
