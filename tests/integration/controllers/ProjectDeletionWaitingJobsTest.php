<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\ProjectsController;
use app\controllers\ProjectController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\JobCancellationService;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * Deleting a project, in the web UI and through the API, cancels the
 * waiting jobs of its deleted job templates before it purges them, as the
 * user who deletes the project: a job without a template never runs, and
 * nothing could approve it any more. The answer stays the delete's.
 */
class ProjectDeletionWaitingJobsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testTheWebDeleteCancelsTheWaitingJobsOfItsDeletedTemplatesAsTheUser(): void
    {
        $user = $this->createUser('pdw-web');
        $user->is_superadmin = true;
        $user->save(false);
        ['template' => $template, 'job' => $job, 'request' => $request] = $this->deletedTemplateWithWaitingJob($user);
        $project = $this->projectOf($template);
        $this->loginAs($user);

        $this->webController()->actionDelete((int)$project->id);

        $this->assertSame(['success' => "Project \"{$project->name}\" deleted."], \Yii::$app->session->getAllFlashes());
        $this->assertNull(Project::findOne($project->id));
        $this->assertCanceledBy($job, $request, (int)$user->id);
    }

    public function testTheApiDeleteCancelsTheWaitingJobsOfItsDeletedTemplatesAsTheUser(): void
    {
        $user = $this->createUserWithRole('pdw-api', 'admin');
        ['template' => $template, 'job' => $job, 'request' => $request] = $this->deletedTemplateWithWaitingJob($user);
        $project = $this->projectOf($template);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'project-delete');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);

        $result = (new ProjectsController('api/v1/projects', $this->app()))->actionDelete((int)$project->id);

        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(Project::findOne($project->id));
        $this->assertCanceledBy($job, $request, (int)$user->id);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * A job template of a project of its own, deleted while one of its jobs
     * waited for approval, without the delete's cancel (a soft delete
     * through the model).
     *
     * @return array{template: JobTemplate, job: Job, request: ApprovalRequest}
     */
    private function deletedTemplateWithWaitingJob(User $owner): array
    {
        $template = $this->cancelFixtureTemplate((int)$owner->id);
        $launcher = $this->createUserWithRole('pdw-launcher', 'operator');
        $approver = $this->createUserWithRole('pdw-approver', 'operator');
        ['job' => $job, 'request' => $request] = $this->jobWaitingForApproval($template, (int)$launcher->id, (int)$approver->id);
        $this->assertTrue($template->softDelete());

        return ['template' => $template, 'job' => $job, 'request' => $request];
    }

    private function projectOf(JobTemplate $template): Project
    {
        $project = Project::findOne($template->project_id);
        $this->assertNotNull($project);

        return $project;
    }

    /**
     * $job and its request are canceled for the template's delete, and both
     * entries name user #$userId.
     */
    private function assertCanceledBy(Job $job, ApprovalRequest $request, int $userId): void
    {
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNull($job->job_template_id, 'purged');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $canceled = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id);
        $this->assertSame($userId, (int)$canceled->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($canceled));
        $closed = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id);
        $this->assertSame($userId, (int)$closed->user_id);
    }

    private function webController(): ProjectController
    {
        return new class ('project', $this->app()) extends ProjectController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                return new \yii\web\Response();
            }
        };
    }

    private function app(): \yii\base\Module
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;

        return $app;
    }
}
