<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\JobTemplateDeletionService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Deleting a job template in the web UI: its waiting jobs are canceled,
 * its schedules disabled, and the flash says how many.
 */
class JobTemplateControllerDeleteTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Swapped before any write, so that tearDown() restores it also when
        // a write fails.
        $this->swapComponent('notificationDispatcher', new \app\tests\integration\services\RecordingNotificationDispatcher());
        $this->admin = $this->createUserWithRole('jt-delete-admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * Regression: deleting a job template left its waiting jobs queued (no
     * runner claims a job of a deleted template) and its schedules enabled.
     */
    public function testDeletingATemplateCancelsItsWaitingJobsAndDisablesItsSchedules(): void
    {
        $template = $this->template();
        $queued = $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_QUEUED);
        $waiting = $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $running = $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_RUNNING);
        $schedule = $this->schedule($template);
        $this->loginAs($this->admin);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete((int)$template->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $queued->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $queued->status);
        $waiting->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $waiting->status);
        $running->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $running->status, 'a running job finishes');
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull(JobTemplate::findOne($template->id));
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $this->assertSame(['success' => sprintf(JobTemplateController::FLASH_DELETED, $template->name, 2, 1)], $session->getAllFlashes());
        $entry = AuditLog::findOne(['action' => AuditLog::ACTION_TEMPLATE_DELETED, 'object_type' => 'job_template', 'object_id' => $template->id]);
        $this->assertNotNull($entry);
        $this->assertSame((int)$this->admin->id, (int)$entry->user_id);
        $this->assertSame(
            ['name' => $template->name, 'source' => JobTemplateDeletionService::SOURCE_WEB, 'canceled_jobs' => 2, 'disabled_schedules' => 1],
            json_decode((string)$entry->metadata, true)
        );
    }

    public function testATemplateDeletedMeanwhileIsNotFound(): void
    {
        $template = $this->template();
        $this->loginAs($this->admin);
        $original = \Yii::$app->getComponents(true)['jobTemplateDeletionService'] ?? null;
        \Yii::$app->set('jobTemplateDeletionService', new class extends JobTemplateDeletionService {
            public function delete(JobTemplate $template, ?int $actorId, string $source): array
            {
                return ['deleted' => false, 'canceled_jobs' => 0, 'disabled_schedules' => 0];
            }
        });

        try {
            $this->makeController()->actionDelete((int)$template->id);
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame("Job template #{$template->id} not found.", $e->getMessage());
        } finally {
            \Yii::$app->set('jobTemplateDeletionService', $original);
        }
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $this->assertSame([], $session->getAllFlashes());
    }

    /**
     * Operator access to the template's project is checked before anything
     * is deleted or canceled.
     */
    public function testAUserWhoMayNotOperateTheProjectDeletesNothing(): void
    {
        $scope = $this->teamScope();
        $job = $this->createJob((int)$scope['foreign']->id, (int)$scope['admin']->id, Job::STATUS_QUEUED);
        $this->loginAs($scope['member']);

        try {
            $this->makeController()->actionDelete((int)$scope['foreign']->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException) {
            // expected
        }

        $this->assertNotNull(JobTemplate::findOne($scope['foreign']->id));
        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
    }

    private function template(): JobTemplate
    {
        $ownerId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    private function schedule(JobTemplate $template): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'jt-delete-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->admin->id;
        $schedule->computeNextRunAt();
        $schedule->save(false);

        return $schedule;
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    /**
     * @return JobTemplateController&object{capturedRedirect: mixed}
     */
    private function makeController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            /**
             * @param array<mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                return new Response();
            }
        };
    }
}
