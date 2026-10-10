<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobLaunchService;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Team scoping of the inbound triggers, with the real launch services: a
 * trigger runs as the user who generated its token (the template's creator
 * for older tokens) and only while that user may launch what it triggers.
 */
class TriggerControllerTeamScopeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private RecordingNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swap('notificationDispatcher', $this->dispatcher);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // ── /trigger/fire ────────────────────────────────────────────────────────

    /**
     * Regression: the trigger launched as the template's creator, whoever
     * generated the token.
     */
    public function testFireRunsAsTheUserWhoGeneratedTheToken(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $raw = $scope['own']->generateTriggerToken($member);

        $result = $this->fire($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($member, (int)$this->launchedJob($result)->launched_by);
        $this->assertNotSame($member, (int)$scope['own']->created_by, 'the template was created by someone else');
    }

    public function testFireWithALegacyTokenRunsAsTheTemplateCreator(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $raw = $this->legacyJobToken($scope['own'], $member);

        $result = $this->fire($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($member, (int)$this->launchedJob($result)->launched_by);
    }

    /**
     * Regression: a trigger kept launching jobs after the user it runs as
     * was disabled.
     */
    public function testFireIsRefusedWhenTheTokenCreatorIsDisabled(): void
    {
        $scope = $this->teamScope();
        $member = $scope['member'];
        $raw = $scope['own']->generateTriggerToken((int)$member->id);
        $member->status = User::STATUS_INACTIVE;
        $member->save(false);

        $result = $this->fire($raw);

        $this->assertJobTriggerRefused($result, $scope['own'], (int)$member->id, TriggerController::DENIED_NOT_PERMITTED);
    }

    public function testFireIsRefusedWhenTheTokenCreatorMayNoLongerLaunchJobs(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $raw = $scope['own']->generateTriggerToken($member);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $auth->revokeAll((string)$member);
        $this->assignRole($member, 'viewer');

        $result = $this->fire($raw);

        $this->assertJobTriggerRefused($result, $scope['own'], $member, TriggerController::DENIED_NOT_PERMITTED);
    }

    /**
     * Regression: a trigger kept launching a team-restricted template after
     * the user it runs as lost operator access to the project.
     *
     * @return array<string, array{0: string}>
     */
    public static function lostAccessProvider(): array
    {
        return ['left the team' => ['left'], 'the team only views the project now' => ['downgraded']];
    }

    /**
     * @dataProvider lostAccessProvider
     */
    public function testFireIsRefusedWhenTheTokenCreatorLostOperatorAccess(string $change): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $own = $scope['own'];
        $raw = $own->generateTriggerToken($member);
        if ($change === 'left') {
            TeamMember::deleteAll(['user_id' => $member]);
        } else {
            TeamProject::updateAll(['role' => TeamProject::ROLE_VIEWER], ['project_id' => $own->project_id]);
        }

        $result = $this->fire($raw);

        $this->assertJobTriggerRefused($result, $own, $member, TriggerController::DENIED_NO_PROJECT_ACCESS);
    }

    public function testFireWithALegacyTokenIsRefusedWhenTheTemplateCreatorMayNotOperateTheProject(): void
    {
        $scope = $this->teamScope();
        $outsider = (int)$scope['outsider']->id;
        $raw = $this->legacyJobToken($scope['own'], $outsider);

        $result = $this->fire($raw);

        $this->assertJobTriggerRefused($result, $scope['own'], $outsider, TriggerController::DENIED_NO_PROJECT_ACCESS);
    }

    public function testFireRunsAsAnAdminInAnotherTeamsProject(): void
    {
        $scope = $this->teamScope();
        $admin = (int)$scope['admin']->id;
        $raw = $scope['foreign']->generateTriggerToken($admin);

        $result = $this->fire($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($admin, (int)$this->launchedJob($result)->launched_by);
    }

    public function testFireOfAnOpenProjectNeedsNoTeam(): void
    {
        $scope = $this->teamScope();
        $outsider = (int)$scope['outsider']->id;
        $raw = $scope['open']->generateTriggerToken($outsider);

        $result = $this->fire($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($outsider, (int)$this->launchedJob($result)->launched_by);
    }

    public function testFireForwardsTheOverridesOfTheBody(): void
    {
        $scope = $this->teamScope();
        $raw = $scope['own']->generateTriggerToken((int)$scope['member']->id);
        \Yii::$app->request->setRawBody((string)json_encode([
            'extra_vars' => ['env' => 'prod'],
            'limit' => 'web1',
            'verbosity' => 2,
        ]));

        $job = $this->launchedJob($this->fire($raw));

        $this->assertSame('prod', json_decode((string)$job->extra_vars, true)['env'] ?? null);
        $this->assertSame('web1', $job->limit);
        $this->assertSame(2, (int)$job->verbosity);
    }

    public function testFireWithAnUnknownTokenIs404AndNotifies(): void
    {
        try {
            $this->fire('not-a-trigger-token');
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame('Trigger not found.', $e->getMessage());
        }

        $notified = $this->dispatcher->payloadsOf(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $this->assertCount(1, $notified);
        $this->assertSame('not-a-', $notified[0]['trigger']['token_prefix'] ?? null, 'the first six characters only');
    }

    public function testFireAnswers500WhenTheLaunchFails(): void
    {
        $scope = $this->teamScope();
        $raw = $scope['own']->generateTriggerToken((int)$scope['member']->id);
        $this->swap('jobLaunchService', new class extends JobLaunchService {
            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                throw new \RuntimeException('test-launch-failure');
            }
        });

        $result = $this->fire($raw);

        $this->assertSame(500, $result->statusCode);
        $this->assertSame(['error' => 'Launch failed.'], $result->data);
    }

    // ── /trigger/fire-workflow ───────────────────────────────────────────────

    public function testFireWorkflowRunsAsTheUserWhoGeneratedTheToken(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $wt = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['open']->id);
        $raw = $wt->generateTriggerToken($member);

        $result = $this->fireWorkflow($raw);

        $this->assertSame(201, $result->statusCode);
        $wfJob = $this->launchedWorkflowJob($result);
        $this->assertSame($member, (int)$wfJob->launched_by);
        $this->assertSame($member, (int)$this->firstStepJob($wfJob)->launched_by);
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED, 'object_id' => $wfJob->id]));
    }

    /**
     * Regression: the workflow trigger launched every job step as the
     * workflow's creator, whatever the user who generated the token may do.
     *
     * @return array<string, array{0: string}>
     */
    public static function stepTheTokenCreatorMayNotOperateProvider(): array
    {
        return ['another team\'s template' => ['foreign'], 'a template the team only views' => ['viewed']];
    }

    /**
     * @dataProvider stepTheTokenCreatorMayNotOperateProvider
     */
    public function testFireWorkflowIsRefusedForAStepTheTokenCreatorMayNotOperate(string $key): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $denied = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $denied);
        $wt = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$denied->id);
        $raw = $wt->generateTriggerToken($member);

        $result = $this->fireWorkflow($raw);

        $this->assertWorkflowTriggerRefused($result, $wt, $member, [(int)$denied->id]);
    }

    public function testFireWorkflowIsRefusedWhenTheTokenCreatorIsDisabled(): void
    {
        $scope = $this->teamScope();
        $member = $scope['member'];
        $wt = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $raw = $wt->generateTriggerToken((int)$member->id);
        $member->status = User::STATUS_INACTIVE;
        $member->save(false);

        $result = $this->fireWorkflow($raw);

        $this->assertWorkflowTriggerRefused($result, $wt, (int)$member->id, []);
    }

    public function testFireWorkflowWithALegacyTokenRunsAsTheWorkflowCreator(): void
    {
        $scope = $this->teamScope();
        $member = (int)$scope['member']->id;
        $wt = $this->createWorkflowWithJobSteps($member, (int)$scope['own']->id);
        $raw = $wt->generateTriggerToken((int)$scope['admin']->id);
        WorkflowTemplate::updateAll(['trigger_token_created_by' => null], ['id' => $wt->id]);

        $result = $this->fireWorkflow($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($member, (int)$this->launchedWorkflowJob($result)->launched_by);
    }

    public function testFireWorkflowWithALegacyTokenIsRefusedWhenTheWorkflowCreatorMayNotOperateAStep(): void
    {
        $scope = $this->teamScope();
        $outsider = (int)$scope['outsider']->id;
        $wt = $this->createWorkflowWithJobSteps($outsider, (int)$scope['own']->id);
        $raw = $wt->generateTriggerToken((int)$scope['admin']->id);
        WorkflowTemplate::updateAll(['trigger_token_created_by' => null], ['id' => $wt->id]);

        $result = $this->fireWorkflow($raw);

        $this->assertWorkflowTriggerRefused($result, $wt, $outsider, [(int)$scope['own']->id]);
    }

    public function testFireWorkflowWithoutJobStepsNeedsNoTeam(): void
    {
        $scope = $this->teamScope();
        $outsider = (int)$scope['outsider']->id;
        $wt = $this->createWorkflowTemplate((int)$scope['admin']->id);
        $this->createWorkflowStep((int)$wt->id, 0, WorkflowStep::TYPE_PAUSE);
        $raw = $wt->generateTriggerToken($outsider);

        $result = $this->fireWorkflow($raw);

        $this->assertSame(201, $result->statusCode);
        $this->assertSame($outsider, (int)$this->launchedWorkflowJob($result)->launched_by);
    }

    public function testFireWorkflowAnswers500ForAWorkflowWithoutSteps(): void
    {
        $scope = $this->teamScope();
        $wt = $this->createWorkflowTemplate((int)$scope['admin']->id);
        $raw = $wt->generateTriggerToken((int)$scope['member']->id);

        $result = $this->fireWorkflow($raw);

        $this->assertSame(500, $result->statusCode);
        $this->assertSame(['error' => 'Launch failed.'], $result->data);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function fire(string $token): Response
    {
        return (new TriggerController('trigger', \Yii::$app))->actionFire($token);
    }

    private function fireWorkflow(string $token): Response
    {
        return (new TriggerController('trigger', \Yii::$app))->actionFireWorkflow($token);
    }

    /**
     * A token as generated before the generating user was recorded: the
     * trigger runs as the template's creator, here $createdBy.
     */
    private function legacyJobToken(JobTemplate $template, int $createdBy): string
    {
        $raw = $template->generateTriggerToken($createdBy);
        JobTemplate::updateAll(
            ['created_by' => $createdBy, 'trigger_token_created_by' => null],
            ['id' => $template->id]
        );
        return $raw;
    }

    private function launchedJob(Response $result): Job
    {
        $this->assertIsArray($result->data);
        $job = Job::findOne($result->data['job_id'] ?? 0);
        $this->assertNotNull($job);
        return $job;
    }

    private function launchedWorkflowJob(Response $result): WorkflowJob
    {
        $this->assertIsArray($result->data);
        $wfJob = WorkflowJob::findOne($result->data['workflow_job_id'] ?? 0);
        $this->assertNotNull($wfJob);
        return $wfJob;
    }

    private function firstStepJob(WorkflowJob $wfJob): Job
    {
        /** @var WorkflowJobStep|null $step */
        $step = WorkflowJobStep::find()->where(['workflow_job_id' => $wfJob->id])->orderBy(['id' => SORT_ASC])->one();
        $this->assertNotNull($step);
        $job = Job::findOne((int)$step->job_id);
        $this->assertNotNull($job);
        return $job;
    }

    private function assertJobTriggerRefused(Response $result, JobTemplate $template, int $userId, string $reason): void
    {
        $this->assertSame(403, $result->statusCode);
        $this->assertSame(['error' => 'Launch refused.'], $result->data, 'the caller learns no team or project details');
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count(), 'no job is launched');

        /** @var AuditLog[] $denials */
        $denials = AuditLog::find()->where([
            'action' => AuditLog::ACTION_TRIGGER_DENIED,
            'object_type' => 'job_template',
            'object_id' => $template->id,
        ])->all();
        $this->assertCount(1, $denials);
        $this->assertSame($userId, (int)$denials[0]->user_id);
        $this->assertSame(
            ['reason' => $reason, 'project_id' => (int)$template->project_id],
            json_decode((string)$denials[0]->metadata, true)
        );
    }

    /**
     * @param list<int> $deniedTemplateIds
     */
    private function assertWorkflowTriggerRefused(
        Response $result,
        WorkflowTemplate $wt,
        int $userId,
        array $deniedTemplateIds
    ): void {
        $this->assertSame(403, $result->statusCode);
        $this->assertSame(['error' => 'Launch refused.'], $result->data, 'the caller learns no team or project details');
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $wt->id])->exists(), 'no workflow job is created');

        /** @var AuditLog[] $denials */
        $denials = AuditLog::find()->where([
            'action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED,
            'object_type' => 'workflow_template',
            'object_id' => $wt->id,
        ])->all();
        $this->assertCount(1, $denials);
        $this->assertSame($userId, (int)$denials[0]->user_id);
        $this->assertSame(
            ['source' => 'trigger', 'job_template_ids' => $deniedTemplateIds],
            json_decode((string)$denials[0]->metadata, true)
        );
    }

    private function swap(string $id, object $replacement): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }
}
