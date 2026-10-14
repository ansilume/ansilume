<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\WorkflowJobsController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\UnauthorizedHttpException;

/**
 * The workflow jobs API through the controller's pipeline
 * (BaseApiController::beforeAction(), as a request reaches an action): the
 * token first (401), then the permission (403), then the list's filters
 * (422), so a caller without access learns nothing from a filter. Every
 * action answers a run with what it waits for (waiting_for, current_step).
 */
class WorkflowJobsApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testControllerExtendsBaseApiController(): void
    {
        $ref = new \ReflectionClass(WorkflowJobsController::class);
        $this->assertTrue(
            $ref->isSubclassOf(\app\controllers\api\v1\BaseApiController::class),
            'WorkflowJobsController must extend BaseApiController'
        );
    }

    public function testWithoutATokenTheListAnswers401BeforeItsFilters(): void
    {
        $this->setQueryParams(['waiting_for' => 'paused']);

        $this->expectException(UnauthorizedHttpException::class);
        $this->controller()->runAction('index');
    }

    public function testWithoutWorkflowViewTheListAnswers403BeforeItsFilters(): void
    {
        $this->authenticate($this->createUser('wj-api-no-role'));
        $this->setQueryParams(['waiting_for' => 'paused']);

        $this->assertNull($this->controller()->runAction('index'));

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    public function testAViewerGetsTheFilteredListOrTheFiltersError(): void
    {
        ['run' => $run] = $this->pausedRun();
        $this->authenticate($this->createUserWithRole('wj-api-viewer', 'viewer'));

        $this->setQueryParams(['waiting_for' => 'paused']);
        $refused = $this->controller()->runAction('index');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'waiting_for must be one of: approval, resume, runner.']], $refused);

        // A new request: its response starts at 200, as every request's does.
        \Yii::$app->response->setStatusCode(200);
        $this->setQueryParams(['waiting_for' => 'resume']);
        $listed = $this->controller()->runAction('index');
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertIsArray($listed);
        $this->assertSame([(int)$run->id], array_column($listed['data'], 'id'));
        $this->assertSame(['resume'], array_column($listed['data'], 'waiting_for'));
    }

    /**
     * view, resume and cancel answer the run with what it waits for, each
     * through its permission.
     */
    public function testEachRunActionAnswersWhatTheRunWaitsFor(): void
    {
        ['run' => $run, 'pause' => $pause] = $this->pausedRun();
        $this->authenticate($this->createUserWithRole('wj-api-viewer', 'viewer'));

        $viewed = $this->controller()->runAction('view', ['id' => (int)$run->id]);
        $this->assertIsArray($viewed);
        $this->assertSame('resume', $viewed['data']['waiting_for']);
        $this->assertSame((int)$pause->id, $viewed['data']['current_step']['id']);
        $this->assertNull($this->controller()->runAction('cancel', ['id' => (int)$run->id]));
        $this->assertSame(403, \Yii::$app->response->statusCode, 'a viewer may not cancel');

        $this->authenticate($this->createUserWithRole('wj-api-operator', 'operator'));
        $resumed = $this->controller()->runAction('resume', ['id' => (int)$run->id]);
        $this->assertIsArray($resumed);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $resumed['data']['status'], 'its only step resumed');
        $this->assertNull($resumed['data']['waiting_for']);

        ['run' => $second] = $this->pausedRun();
        $canceled = $this->controller()->runAction('cancel', ['id' => (int)$second->id]);
        $this->assertIsArray($canceled);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $canceled['data']['status']);
        $this->assertNull($canceled['data']['current_step']);
    }

    // -- Helpers ------------------------------------------------------------------------

    private function controller(): WorkflowJobsController
    {
        return new WorkflowJobsController('api/v1/workflow-jobs', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-jobs-pipeline');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * A running run of a workflow with one pause step, paused there.
     *
     * @return array{run: WorkflowJob, pause: WorkflowJobStep}
     */
    private function pausedRun(): array
    {
        $owner = $this->createUser('wj-api-owner');
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$owner->id);
        $pause = new WorkflowJobStep();
        $pause->workflow_job_id = (int)$run->id;
        $pause->workflow_step_id = (int)$step->id;
        $pause->snapshotStep($step);
        $pause->status = WorkflowJobStep::STATUS_RUNNING;
        $pause->started_at = time();
        $pause->save(false);

        return ['run' => $run, 'pause' => $pause];
    }
}
