<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\TriggerController;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Inbound-trigger endpoint coverage. The job-side {@see TriggerController::actionFire()}
 * is exercised end-to-end by the e2e suite (tests/e2e/tests/trigger/fire.spec.ts);
 * this PHPUnit class focuses on the workflow-side {@see actionFireWorkflow()} since
 * the e2e suite can't easily stub WorkflowExecutionService.
 */
class TriggerControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component|null}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Stub WorkflowExecutionService so launch() doesn't actually push into
        // the queue or persist child WorkflowJobSteps. Keeps the test
        // hermetic — we only verify the controller's wiring.
        $this->swapService('workflowExecutionService', new class extends WorkflowExecutionService {
            public int $launchCalls = 0;
            /** @var array<string, mixed>|null */
            public ?array $lastOverrides = null;
            public bool $throwOnLaunch = false;
            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = []): WorkflowJob
            {
                $this->launchCalls++;
                $this->lastOverrides = $overrides;
                if ($this->throwOnLaunch) {
                    throw new \RuntimeException('Workflow has no steps.');
                }
                $j = new WorkflowJob();
                $j->workflow_template_id = $template->id;
                $j->launched_by = $launchedBy;
                $j->status = WorkflowJob::STATUS_RUNNING;
                $j->started_at = time();
                $j->created_at = time();
                $j->updated_at = time();
                $j->save(false);
                return $j;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->swappedServices as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
        parent::tearDown();
    }

    public function testFireWorkflowLaunchesAndReturns201WithId(): void
    {
        $owner = $this->createUser();
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken();

        $this->setQueryParams(['token' => $raw]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionFireWorkflow($raw);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $body = $this->decodeJson($result);
        $this->assertArrayHasKey('workflow_job_id', $body);
        $this->assertIsInt($body['workflow_job_id']);

        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(1, $svc->launchCalls);
    }

    public function testFireWorkflowForwardsExtraVarsButDropsJobOnlyKeys(): void
    {
        // limit + verbosity are job-template-scoped concepts. The workflow
        // launcher only honours extra_vars; the others must NOT leak into
        // launchOverrides so callers don't accidentally rely on them.
        $owner = $this->createUser();
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken();

        $body = json_encode([
            'extra_vars' => ['env' => 'staging'],
            'limit' => 'web1',
            'verbosity' => 3,
        ]);
        \Yii::$app->request->setRawBody((string)$body);

        $this->setQueryParams(['token' => $raw]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $ctrl->actionFireWorkflow($raw);

        /** @var object{lastOverrides: array<string, mixed>} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(['extra_vars' => ['env' => 'staging']], $svc->lastOverrides);
    }

    public function testFireWorkflowThrowsNotFoundForUnknownToken(): void
    {
        $this->setQueryParams(['token' => 'this-token-does-not-exist']);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionFireWorkflow('this-token-does-not-exist');
    }

    public function testFireWorkflowReturns500OnLaunchException(): void
    {
        $owner = $this->createUser();
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken();

        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->throwOnLaunch = true;

        $this->setQueryParams(['token' => $raw]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionFireWorkflow($raw);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, $result->statusCode);
        $body = $this->decodeJson($result);
        $this->assertArrayHasKey('error', $body);
    }

    private function makeController(): TriggerController
    {
        return new TriggerController('trigger', \Yii::$app);
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        $original = \Yii::$app->has($id) ? \Yii::$app->get($id) : null;
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $r): array
    {
        // After Controller::asJson(...), Response->data holds the unencoded
        // value. We don't need to re-render the JSON for assertions.
        $data = $r->data;
        return is_array($data) ? $data : [];
    }
}
