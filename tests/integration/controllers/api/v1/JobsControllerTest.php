<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Jobs API: jobs without a job template under team scoping.
 *
 * Regression: a job without a template counted as global, so any token with
 * job.cancel could read and cancel the placeholder job of another team's
 * workflow approval step.
 */
class JobsControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const FORBIDDEN = ['error' => ['message' => 'Forbidden.']];

    public function testAnOperatorOfAnotherTeamCannotCancelAWorkflowApprovalPlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->authenticate($s['outsider']);

        $result = $this->controller()->actionCancel((int)$placeholder->id);

        $this->assertSame(self::FORBIDDEN, $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $placeholder->refresh();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
    }

    public function testAnOperatorOfAnotherTeamCannotReadThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->authenticate($s['outsider']);
        $id = (int)$placeholder->id;

        $this->assertSame(self::FORBIDDEN, $this->controller()->actionView($id));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionArtifacts($id));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionArtifactContent($id, 1));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionDownloadArtifact($id, 1));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionDownloadAllArtifacts($id));
    }

    public function testTheOwningTeamMayReadAndCancelThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->authenticate($s['member']);

        $view = $this->controller()->actionView((int)$placeholder->id);
        $this->assertSame((int)$placeholder->id, $view['data']['id']);
        $this->assertNull($view['data']['job_template_id']);

        $canceled = $this->controller()->actionCancel((int)$placeholder->id);
        $this->assertSame(Job::STATUS_CANCELED, $canceled['data']['status']);
    }

    public function testATeamThatOnlyViewsTheWorkflowMayReadButNotCancelThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['viewed']->id);
        $this->authenticate($s['member']);

        $this->assertArrayHasKey('data', $this->controller()->actionView((int)$placeholder->id));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionCancel((int)$placeholder->id));
    }

    public function testThePlaceholderOfAnApprovalOnlyWorkflowIsOpenToEveryone(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id);
        $this->authenticate($s['outsider']);

        $this->assertArrayHasKey('data', $this->controller()->actionView((int)$placeholder->id));
    }

    public function testAJobOfAPurgedTemplateIsOnlyForUnrestrictedUsers(): void
    {
        $s = $this->teamScope();
        $orphan = $this->createJob((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_SUCCEEDED);
        $orphan->job_template_id = null;
        $orphan->save(false);

        $this->authenticate($s['member']);
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionView((int)$orphan->id));

        $this->authenticate($s['admin']);
        $this->assertSame((int)$orphan->id, $this->controller()->actionView((int)$orphan->id)['data']['id']);
    }

    /**
     * The access check comes before the state check, so a caller without
     * access learns nothing about a job's status.
     */
    public function testCancelingAFinishedJobOfAnotherTeamIs403Not409(): void
    {
        $s = $this->teamScope();
        $finished = $this->createJob((int)$s['foreign']->id, (int)$s['admin']->id, Job::STATUS_SUCCEEDED);
        $this->authenticate($s['member']);

        $this->assertSame(self::FORBIDDEN, $this->controller()->actionCancel((int)$finished->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testCancelingAFinishedVisibleJobIs409(): void
    {
        $s = $this->teamScope();
        $finished = $this->createJob((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_SUCCEEDED);
        $this->authenticate($s['member']);

        $result = $this->controller()->actionCancel((int)$finished->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('error', $result);
    }

    public function testJobsWithATemplateStillFollowTheirProject(): void
    {
        $s = $this->teamScope();
        $own = $this->createJob((int)$s['own']->id, (int)$s['admin']->id);
        $open = $this->createJob((int)$s['open']->id, (int)$s['admin']->id);
        $foreign = $this->createJob((int)$s['foreign']->id, (int)$s['admin']->id);
        $this->authenticate($s['member']);

        $this->assertArrayHasKey('data', $this->controller()->actionView((int)$own->id));
        $this->assertArrayHasKey('data', $this->controller()->actionView((int)$open->id));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionView((int)$foreign->id));
        $this->assertSame(self::FORBIDDEN, $this->controller()->actionCancel((int)$foreign->id));
        $foreign->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $foreign->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'jobs-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }

    /**
     * The placeholder job of a running workflow's approval step; the workflow
     * has one job step per $jobTemplateIds.
     */
    private function approvalPlaceholder(int $adminId, int ...$jobTemplateIds): Job
    {
        $rule = $this->createApprovalRule($adminId);
        $workflow = $this->createWorkflowWithJobSteps($adminId, ...$jobTemplateIds);
        $step = $this->createWorkflowStep((int)$workflow->id, 9, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $adminId);

        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $adminId;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->save(false);

        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $run->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->job_id = $job->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time();
        $wjs->created_at = time();
        $wjs->updated_at = time();
        $wjs->save(false);

        return $job;
    }
}
