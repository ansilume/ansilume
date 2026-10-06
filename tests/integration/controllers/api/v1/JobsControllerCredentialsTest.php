<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Jobs API: launching by job_template_id (the documented field) and the
 * credentials a job uses, without secrets, on single-job responses.
 */
class JobsControllerCredentialsTest extends WebControllerTestCase
{
    private JobsController $ctrl;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new JobsController('api/v1/jobs', \Yii::$app);
        $user = $this->createUser('jobs-api');
        $this->userId = (int)$user->id;
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $admin = $auth->getRole('admin');
        $this->assertNotNull($admin);
        $auth->assign($admin, (string)$user->id);
        ['raw' => $raw] = ApiToken::generate($this->userId, 'jobs-api-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Regression: the spec documents job_template_id, but the API only read
     * template_id, so a client following the spec got "Job template #0 not found."
     */
    public function testLaunchByJobTemplateIdReturnsTheCredentials(): void
    {
        [$template, $credential] = $this->templateWithCredential();
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertArrayHasKey('data', $result);
        $this->assertSame($template->id, $result['data']['job_template_id']);
        $this->assertSame([[
            'id' => (int)$credential->id,
            'name' => $credential->name,
            'credential_type' => Credential::TYPE_TOKEN,
            'role' => Credential::ROLE_PRIMARY,
            'deleted' => false,
        ]], $result['data']['credentials']);
    }

    public function testLaunchStillAcceptsTemplateId(): void
    {
        [$template] = $this->templateWithCredential();
        $this->setBody(['template_id' => $template->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame($template->id, $result['data']['job_template_id']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function missingTemplateIdProvider(): array
    {
        return [
            'no id' => [[]],
            'zero' => [['job_template_id' => 0]],
            'not a number' => [['job_template_id' => 'abc']],
        ];
    }

    /**
     * @dataProvider missingTemplateIdProvider
     * @param array<string, mixed> $body
     */
    public function testLaunchWithoutATemplateIdIs422(array $body): void
    {
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'job_template_id is required.']], $result);
    }

    public function testLaunchOfAnUnknownTemplateIs404(): void
    {
        $this->setBody(['job_template_id' => 999999]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Job template #999999 not found.']], $result);
    }

    public function testViewNamesADeletedCredentialFromTheLaunchSnapshot(): void
    {
        [$template, $credential] = $this->templateWithCredential();
        $this->setBody(['job_template_id' => $template->id]);
        $jobId = (int)$this->ctrl->actionCreate()['data']['id'];
        $name = $credential->name;
        $credential->delete();

        $result = $this->ctrl->actionView($jobId);

        $this->assertSame([[
            'id' => (int)$credential->id,
            'name' => $name,
            'credential_type' => Credential::TYPE_TOKEN,
            'role' => Credential::ROLE_PRIMARY,
            'deleted' => true,
        ]], $result['data']['credentials']);
    }

    public function testCancelResponseIncludesTheCredentials(): void
    {
        [$template, $credential] = $this->templateWithCredential();
        $this->setBody(['job_template_id' => $template->id]);
        $jobId = (int)$this->ctrl->actionCreate()['data']['id'];

        $result = $this->ctrl->actionCancel($jobId);

        $this->assertSame(Job::STATUS_CANCELED, $result['data']['status']);
        $this->assertSame([(int)$credential->id], array_column($result['data']['credentials'], 'id'));
    }

    public function testTheListOmitsCredentials(): void
    {
        [$template] = $this->templateWithCredential();
        $this->setBody(['job_template_id' => $template->id]);
        $this->ctrl->actionCreate();

        $result = $this->ctrl->actionIndex();

        $this->assertNotEmpty($result['data']);
        foreach ($result['data'] as $row) {
            $this->assertArrayNotHasKey('credentials', $row);
        }
    }

    /**
     * @return array{0: JobTemplate, 1: Credential}
     */
    private function templateWithCredential(): array
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $credential = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $template->credential_id = $credential->id;
        $template->save(false);
        $template->refresh();

        return [$template, $credential];
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
