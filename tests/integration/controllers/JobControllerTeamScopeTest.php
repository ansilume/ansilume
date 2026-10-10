<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\Job;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Jobs without a job template in the web UI.
 *
 * Regression: a job without a template counted as global, so any operator
 * could open and cancel the placeholder job of another team's workflow
 * approval step (canceling it fails that step and the workflow). Such a job
 * now follows its workflow; one of a purged template is for unrestricted
 * users only.
 */
class JobControllerTeamScopeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testAnOperatorOfAnotherTeamCannotCancelAWorkflowApprovalPlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->loginAs($s['outsider']);

        try {
            $this->makeController()->actionCancel((int)$placeholder->id);
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException) {
        }
        $placeholder->refresh();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
    }

    public function testAnOperatorOfAnotherTeamCannotViewOrPollThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->loginAs($s['outsider']);

        $this->assertForbidden(fn () => $this->makeController()->actionView((int)$placeholder->id));
        $this->assertForbidden(fn () => $this->makeController()->actionLogPoll((int)$placeholder->id));
        $this->assertForbidden(fn () => $this->makeController()->actionRelaunch((int)$placeholder->id));
        $this->assertForbidden(fn () => $this->makeController()->actionArtifactContent((int)$placeholder->id, 1));
        $this->assertForbidden(fn () => $this->makeController()->actionDownloadArtifact((int)$placeholder->id, 1));
        $this->assertForbidden(fn () => $this->makeController()->actionDownloadAllArtifacts((int)$placeholder->id));
    }

    public function testTheOwningTeamMayViewAndCancelThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['own']->id);
        $this->loginAs($s['member']);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:view', $ctrl->actionView((int)$placeholder->id));

        $this->assertInstanceOf(Response::class, $this->makeController()->actionCancel((int)$placeholder->id));
        $placeholder->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $placeholder->status);
    }

    public function testATeamThatOnlyViewsTheWorkflowMayViewButNotCancelThePlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['viewed']->id);
        $this->loginAs($s['member']);

        $this->assertSame('rendered:view', $this->makeController()->actionView((int)$placeholder->id));
        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$placeholder->id));
    }

    public function testThePlaceholderOfAnApprovalOnlyWorkflowIsOpenToEveryone(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id);
        $this->loginAs($s['outsider']);

        $this->assertSame('rendered:view', $this->makeController()->actionView((int)$placeholder->id));
    }

    public function testAJobOfAPurgedTemplateIsOnlyForUnrestrictedUsers(): void
    {
        $s = $this->teamScope();
        $orphan = $this->createJob((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_SUCCEEDED);
        $orphan->job_template_id = null;
        $orphan->save(false);

        $this->loginAs($s['member']);
        $this->assertForbidden(fn () => $this->makeController()->actionView((int)$orphan->id));

        $this->loginAs($s['admin']);
        $this->assertSame('rendered:view', $this->makeController()->actionView((int)$orphan->id));
    }

    public function testAnAdminMayCancelAnyPlaceholder(): void
    {
        $s = $this->teamScope();
        $placeholder = $this->approvalPlaceholder((int)$s['admin']->id, (int)$s['foreign']->id);
        $this->loginAs($s['admin']);

        $this->makeController()->actionCancel((int)$placeholder->id);

        $placeholder->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $placeholder->status);
    }

    public function testJobsWithATemplateStillFollowTheirProject(): void
    {
        $s = $this->teamScope();
        $own = $this->createJob((int)$s['own']->id, (int)$s['admin']->id);
        $foreign = $this->createJob((int)$s['foreign']->id, (int)$s['admin']->id);
        $open = $this->createJob((int)$s['open']->id, (int)$s['admin']->id);
        $this->loginAs($s['member']);

        $this->assertSame('rendered:view', $this->makeController()->actionView((int)$own->id));
        $this->assertSame('rendered:view', $this->makeController()->actionView((int)$open->id));
        $this->assertForbidden(fn () => $this->makeController()->actionView((int)$foreign->id));
        $this->assertForbidden(fn () => $this->makeController()->actionCancel((int)$foreign->id));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected 403');
        } catch (ForbiddenHttpException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
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

    private function makeController(): JobController
    {
        return new class ('job', \Yii::$app) extends JobController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
