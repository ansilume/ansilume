<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\JobController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\RelaunchService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Re-launch of a job from the job page, with its password answers, and what
 * its flash says: queued (JobController::FLASH_RELAUNCHED), or waiting for
 * approval (FLASH_RELAUNCHED_WAITING).
 *
 * Regression: re-launch copied the original job's merged extra vars, so the
 * plaintext password answers of the original launch were launched again,
 * and stored again in plaintext. Now they are carried over sealed, and only
 * while the template still runs on the project and inventory the job ran on.
 */
class JobControllerRelaunchTest extends WebControllerTestCase
{
    private const ANSWER = 'relaunch-answer-marker-5150';
    private const TEST_KEY = 'relaunch-test-key-0123456789abcdef';

    private User $user;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = self::TEST_KEY;
        $this->user = $this->createUser('relaunch');
        $this->loginAs($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    // -- Password answers -------------------------------------------------------

    public function testARelaunchCarriesTheSealedAnswersWithoutShowingThem(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $original->limit = 'webservers';
        $original->verbosity = 2;
        $original->check_mode = 1;
        $original->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame(sprintf(JobController::FLASH_RELAUNCHED, $job->id), $this->flash('success'));
        $this->assertSame(['view', 'id' => $job->id], $ctrl->capturedRedirect);
        $this->assertSame(
            [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
            json_decode((string)$job->extra_vars, true)
        );
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
        $this->assertSame('webservers', $job->limit);
        $this->assertSame(2, $job->verbosity);
        $this->assertSame(1, (int)$job->check_mode);
        $this->assertSame((int)$this->user->id, (int)$job->launched_by);

        $audit = $this->launchAudit($job);
        $this->assertSame((int)$original->id, $audit['relaunch_of']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['carried_secret_keys']);
        $everythingShown = (string)$job->extra_vars . (string)$job->runner_payload . (string)json_encode($audit)
            . (string)json_encode(\Yii::$app->session->getAllFlashes());
        $this->assertStringNotContainsString(self::ANSWER, $everythingShown);
    }

    /**
     * The answers stay secret although the template's survey no longer has
     * that password field: they were entered as passwords.
     */
    public function testAnswersOfAFieldRemovedSinceStaySealed(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $template->survey_fields = null;
        $template->save(false);

        $this->makeController()->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame(SurveySecrets::PLACEHOLDER, json_decode((string)$job->extra_vars, true)[self::SURVEY_PASSWORD_FIELD]);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    /**
     * A job whose only extra vars are password answers re-launches with
     * them alone.
     */
    public function testAJobWithOnlyPasswordAnswersRelaunchesWithThem(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->createJobWithSealedSecrets($template, (int)$this->user->id, [self::SURVEY_PASSWORD_FIELD => self::ANSWER], [], Job::STATUS_FAILED);

        $this->makeController()->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$job->extra_vars, true));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    /**
     * A finished job from before the update still holds its answer in
     * plaintext until the history is scrubbed; its re-launch stores it
     * sealed.
     */
    public function testAPlaintextAnswerOfAnOlderJobIsSealedByTheRelaunch(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $original->extra_vars = (string)json_encode([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
        $original->save(false);

        $this->makeController()->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertStringNotContainsString(self::ANSWER, (string)$job->extra_vars . (string)$job->runner_payload);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
        $this->assertSame([], $this->launchAudit($job)['carried_secret_keys']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changedTargetProvider(): array
    {
        return ['the template moved to another project' => ['project_id'], 'the template points at another inventory' => ['inventory_id']];
    }

    /**
     * Stored answers are not handed to a playbook or hosts they were not
     * entered for: a template moved to another project (another team may
     * operate it) or pointed at other hosts.
     *
     * @dataProvider changedTargetProvider
     */
    public function testAnswersAreNotReusedWhenTheTemplateNoLongerRunsWhereTheJobRan(string $attribute): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $template->$attribute = $attribute === 'project_id'
            ? (int)$this->createProject((int)$this->user->id)->id
            : (int)$this->createInventory((int)$this->user->id)->id;
        $template->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $this->assertSame(RelaunchService::TARGET_CHANGED, $this->flash('danger'));
        $this->assertSame(['view', 'id' => (int)$original->id], $ctrl->capturedRedirect);
        $this->assertNoRelaunchOf($original);
    }

    public function testAnswersAreNotReusedWhenTheRunnerPayloadDoesNotNameTheTarget(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $original->runner_payload = (string)json_encode(['project_id' => (int)$template->project_id]);
        $original->save(false);

        $this->makeController()->actionRelaunch((int)$original->id);

        $this->assertSame(RelaunchService::TARGET_CHANGED, $this->flash('danger'));
        $this->assertNoRelaunchOf($original);
    }

    /**
     * IDs recorded as digit strings match the template's IDs.
     */
    public function testATargetRecordedAsTextStillMatches(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $payload = $original->decodedRunnerPayload();
        $payload['project_id'] = (string)$template->project_id;
        $payload['inventory_id'] = (string)$template->inventory_id;
        $original->runner_payload = (string)json_encode($payload);
        $original->save(false);

        $this->makeController()->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    /**
     * Answers sealed with another APP_SECRET_KEY: a message instead of a
     * 500, and no job.
     */
    public function testUndecryptableAnswersAreReportedWithoutAJob(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $original = $this->finishedJobWithAnswers($template);
        $_ENV['APP_SECRET_KEY'] = 'another-key-after-a-rotation-0123456789';

        $ctrl = $this->makeController();
        $result = $ctrl->actionRelaunch((int)$original->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(RelaunchService::ANSWERS_UNDECRYPTABLE, $this->flash('danger'));
        $this->assertSame(['view', 'id' => (int)$original->id], $ctrl->capturedRedirect);
        $this->assertNoRelaunchOf($original);
    }

    // -- What the flash says -------------------------------------------------------

    /**
     * A re-launch whose job is queued says so, in the words the flash had
     * before 2.12.0.
     */
    public function testAQueuedRelaunchSaysItWasRelaunched(): void
    {
        $original = $this->createJob((int)$this->plainTemplate()->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame(['success' => "Re-launched as Job #{$job->id}."], \Yii::$app->session->getAllFlashes());
        $this->assertSame(sprintf(JobController::FLASH_RELAUNCHED, $job->id), $this->flash('success'));
        $this->assertSame(['view', 'id' => $job->id], $ctrl->capturedRedirect);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gateProvider(): array
    {
        return [
            'the job template\'s own rule' => ['job_template'],
            'the rule of the template\'s inventory' => ['inventory'],
        ];
    }

    /**
     * regression: a re-launch whose new job waits for approval flashed the
     * success "Re-launched as Job #N." of a queued job, although the job
     * does not run before it is approved. Since 2.12.0 an inventory's rule
     * makes many re-launches wait; the launch from the template page has
     * always said "is waiting for approval" (info).
     *
     * @dataProvider gateProvider
     */
    public function testRegressionARelaunchThatWaitsForApprovalSaidItWasRelaunchedLikeAQueuedJob(string $gatedBy): void
    {
        $template = $this->plainTemplate();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $this->gate($template, $gatedBy);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status, 'the new job waits for approval');
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayNotHasKey('success', $flashes, 'a job that waits for approval was not re-launched like a queued one');
        $this->assertSame(['info' => "Re-launched as Job #{$job->id}, which is waiting for approval."], $flashes);
        $this->assertSame(sprintf(JobController::FLASH_RELAUNCHED_WAITING, $job->id), $this->flash('info'));
        $this->assertSame(['view', 'id' => $job->id], $ctrl->capturedRedirect, 'on to the new job');
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);
        $this->assertSame($gatedBy, $request->requiredBy());
    }

    // -- What the job page's button requires --------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function unfinishedStatusProvider(): array
    {
        return [
            'pending' => [Job::STATUS_PENDING],
            'queued' => [Job::STATUS_QUEUED],
            'running' => [Job::STATUS_RUNNING],
            'pending approval' => [Job::STATUS_PENDING_APPROVAL],
        ];
    }

    /**
     * Regression: the button shows for finished jobs only, but the action
     * launched any job again, a running one included.
     *
     * @dataProvider unfinishedStatusProvider
     */
    public function testAJobThatHasNotFinishedIsNotRelaunched(string $status): void
    {
        $template = $this->plainTemplate();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, $status);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $this->assertSame("Job #{$original->id} cannot be re-launched in status \"{$status}\".", $this->flash('danger'));
        $this->assertSame(['view', 'id' => (int)$original->id], $ctrl->capturedRedirect);
        $this->assertNoRelaunchOf($original);
    }

    /**
     * Regression: a job of a deleted template was launched again and never
     * claimed (runners skip deleted templates), so it stayed queued.
     */
    public function testAJobOfADeletedTemplateIsNotRelaunched(): void
    {
        $template = $this->plainTemplate();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $template->softDelete();

        $this->makeController()->actionRelaunch((int)$original->id);

        $this->assertSame(RelaunchService::TEMPLATE_GONE, $this->flash('danger'));
        $this->assertNoRelaunchOf($original);
    }

    public function testAJobWithoutATemplateIsNotRelaunched(): void
    {
        $admin = $this->createUser('relaunch-admin');
        $this->assignRole((int)$admin->id, 'admin');
        $this->loginAs($admin);
        $original = $this->createJob((int)$this->plainTemplate()->id, (int)$admin->id, Job::STATUS_SUCCEEDED);
        $original->job_template_id = null;
        $original->save(false);

        $this->makeController()->actionRelaunch((int)$original->id);

        $this->assertSame(RelaunchService::TEMPLATE_GONE, $this->flash('danger'));
        $this->assertSame(0, (int)Job::find()->where(['>', 'id', $original->id])->andWhere(['launched_by' => $admin->id])->count());
    }

    public function testAJobWithoutExtraVarsRelaunchesWithNone(): void
    {
        $template = $this->plainTemplate();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_CANCELED);

        $this->makeController()->actionRelaunch((int)$original->id);

        $job = $this->relaunchOf($original);
        $this->assertNull($job->extra_vars);
        $this->assertNull($job->getSealedSecretExtraVars());
        $this->assertSame((int)$original->id, $this->launchAudit($job)['relaunch_of']);
    }

    /**
     * A refused launch keeps today's message.
     */
    public function testALaunchTheTemplateRefusesIsReported(): void
    {
        $template = $this->plainTemplate();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $template->survey_fields = (string)json_encode([['name' => 'db[pw', 'type' => 'password']]);
        $template->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionRelaunch((int)$original->id);

        $this->assertSame(
            'Re-launch failed: Survey field "db[pw" has a name that cannot be used for a password answer; rename it in the survey before launching.',
            $this->flash('danger')
        );
        $this->assertSame(['view', 'id' => (int)$original->id], $ctrl->capturedRedirect);
        $this->assertNoRelaunchOf($original);
    }

    // -- Access ---------------------------------------------------------------

    /**
     * Regression: re-launch needed job.cancel, not job.launch, so a role
     * that may cancel but not launch jobs could start them this way.
     */
    public function testTheAccessFilterRefusesARoleThatMayCancelButNotLaunch(): void
    {
        $original = $this->createJob((int)$this->plainTemplate()->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $this->loginWithPermissions('cancel-only', ['job.view', 'job.cancel']);
        $this->setPost([]);

        try {
            $this->makeController()->runAction('relaunch', ['id' => $original->id]);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not allowed to perform this action.', $e->getMessage(), 'the access filter refuses, not the action');
        }
        $this->assertNoRelaunchOf($original);
    }

    public function testTheAccessFilterAdmitsARoleThatMayLaunch(): void
    {
        $original = $this->createJob((int)$this->plainTemplate()->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $launcher = $this->loginWithPermissions('launch-only', ['job.view', 'job.launch']);
        $this->setPost([]);

        $this->makeController()->runAction('relaunch', ['id' => $original->id]);

        $this->assertSame((int)$launcher->id, (int)$this->relaunchOf($original)->launched_by);
    }

    /**
     * Cancel keeps its own permission.
     */
    public function testTheAccessFilterStillAdmitsACancelOnlyRoleToCancel(): void
    {
        $original = $this->createJob((int)$this->plainTemplate()->id, (int)$this->user->id, Job::STATUS_QUEUED);
        $this->loginWithPermissions('cancel-only', ['job.view', 'job.cancel']);
        $this->setPost([]);

        $this->makeController()->runAction('cancel', ['id' => $original->id]);

        $original->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $original->status);
    }

    // The opened answers stay out of stack traces: RelaunchService's
    // parameters that carry them are #[\SensitiveParameter]
    // (tests/unit/SensitiveParametersTest.php).

    // -- Helpers --------------------------------------------------------------

    private function finishedJobWithAnswers(JobTemplate $template): Job
    {
        return $this->createJobWithSealedSecrets(
            $template,
            (int)$this->user->id,
            [self::SURVEY_PASSWORD_FIELD => self::ANSWER],
            [self::SURVEY_TEXT_FIELD => 'prod'],
            Job::STATUS_SUCCEEDED
        );
    }

    private function plainTemplate(): JobTemplate
    {
        $userId = (int)$this->user->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * Give $template an approval rule ($gatedBy job_template), or its
     * inventory one (inventory), as an admin's save stores it. Its approver
     * is an operator, who may decide, so the launch can be approved.
     */
    private function gate(JobTemplate $template, string $gatedBy): void
    {
        $approver = $this->createUser('relaunch-approver');
        $this->assignRole((int)$approver->id, 'operator');
        $rule = $this->createApprovalRule(
            (int)$this->user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$approver->id]])
        );
        if ($gatedBy === ApprovalRequest::REQUIRED_BY_INVENTORY) {
            // approval_rule_id is no mass-assigned attribute of an inventory.
            $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => (int)$rule->id], ['id' => $template->inventory_id]));

            return;
        }
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
    }

    /**
     * The one job launched after $original from its template.
     */
    private function relaunchOf(Job $original): Job
    {
        /** @var Job[] $jobs */
        $jobs = Job::find()->where(['job_template_id' => $original->job_template_id])->andWhere(['>', 'id', $original->id])->all();
        $this->assertCount(1, $jobs, 'one job was re-launched; flashes: ' . json_encode(\Yii::$app->session->getAllFlashes()));

        return $jobs[0];
    }

    private function assertNoRelaunchOf(Job $original): void
    {
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $original->job_template_id])->andWhere(['>', 'id', $original->id])->count());
    }

    private function flash(string $type): mixed
    {
        return \Yii::$app->session->getAllFlashes()[$type] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function launchAudit(Job $job): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => AuditLog::ACTION_JOB_LAUNCHED, 'object_type' => 'job', 'object_id' => $job->id])->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }

    private function assignRole(int $userId, string $roleName): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$userId);
    }

    /**
     * A user with a custom role that holds exactly $permissions.
     *
     * @param list<string> $permissions
     */
    private function loginWithPermissions(string $label, array $permissions): User
    {
        $user = $this->createUser($label);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('relaunch-' . $label . '-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    private function makeController(): JobController
    {
        return new class ('job', \Yii::$app) extends JobController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
