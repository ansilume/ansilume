<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\JobTemplateWarnings;
use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Jobs API: launching by job_template_id (the documented field), the
 * template's warnings in the launch response, and the credentials a job
 * uses, without secrets, on single-job responses.
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

    // -- Launch response: the template's warnings ----------------------------

    public function testTheLaunchOfATemplateWithoutProblemsCarriesNoWarnings(): void
    {
        [$template] = $this->templateWithCredential();
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(['data', 'warnings'], array_keys($result));
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Warnings never block a launch: the job is queued, and the response
     * tells the client what the job may run into.
     */
    public function testTheLaunchCarriesTheVaultWarningOfTheTemplate(): void
    {
        $template = $this->templateWithVaultCheck(Credential::TYPE_VAULT, JobTemplateVaultCheck::STATUS_MISMATCH, [
            ['path' => 'group_vars/all.yml', 'line' => 3, 'key' => 'app_secret'],
            ['path' => 'vars/secrets.yml', 'line' => null, 'key' => null],
        ]);
        $vault = $template->credential;
        $this->assertNotNull($vault);
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => sprintf(
                'The vault password "%s" does not open 2 of the 4 encrypted files or values this template probably loads: '
                . 'group_vars/all.yml:3, vars/secrets.yml. Jobs fail when Ansible needs one of them. '
                . 'Check the password and the inventory; if the files changed, rescan the project.',
                $vault->name
            ),
            'credential_ids' => [(int)$vault->id],
        ]], $result['warnings']);
        $job = Job::findOne((int)$result['data']['id']);
        $this->assertNotNull($job);
        $this->assertSame((int)$template->id, (int)$job->job_template_id);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    public function testTheLaunchCarriesTheWarningAboutAMissingVaultPassword(): void
    {
        $template = $this->templateWithVaultCheck(Credential::TYPE_TOKEN, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, []);
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISSING,
            'message' => 'This template has no vault password, but it probably loads 4 encrypted files or values. '
                . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
            'credential_ids' => [],
        ]], $result['warnings']);
    }

    public function testARejectedLaunchCarriesNoWarnings(): void
    {
        $template = $this->templateWithVaultCheck(Credential::TYPE_TOKEN, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, []);
        $this->setBody(['job_template_id' => $template->id]);
        $this->authenticateWithoutRole();

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
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
     * A template with one credential of the given type as primary and a
     * vault check (as VaultCheckService stores it) over 4 encrypted values.
     *
     * @param list<array{path: string, line: int|null, key: string|null}> $unopened
     */
    private function templateWithVaultCheck(string $credentialType, string $status, array $unopened): JobTemplate
    {
        [$template, $credential] = $this->templateWithCredential();
        $credential->credential_type = $credentialType;
        $credential->save(false);
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = (int)$template->id;
        $check->status = $status;
        $check->credential_id = $credentialType === Credential::TYPE_VAULT ? (int)$credential->id : null;
        $check->relevant_count = 4;
        $check->unopened_count = count($unopened);
        $check->unopened = $unopened === [] ? null : (string)json_encode($unopened);
        $check->checked_at = time();
        $check->scanned_at = $this->vaultScanTimeOf((int)$template->project_id);
        $this->assertTrue($check->save(false));
        $template->refresh();

        return $template;
    }

    /**
     * Switches to a user without any role, who may not launch jobs.
     */
    private function authenticateWithoutRole(): void
    {
        $user = $this->createUser('jobs-api-norole');
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'jobs-api-norole-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->logout(false);
        $userComponent->loginByAccessToken($raw);
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
