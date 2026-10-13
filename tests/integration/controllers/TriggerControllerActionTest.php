<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\TriggerTokenResolver;
use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\services\WorkflowRunVars;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Wiring of the workflow trigger {@see TriggerController::actionFireWorkflow()}
 * against a stubbed WorkflowExecutionService. Team scoping of both triggers,
 * with the real services, is covered by {@see TriggerControllerTeamScopeTest}.
 * The controller checks with the real access checker that the user a trigger
 * runs as may launch the workflow before it calls the service, so that user
 * is an operator here.
 */
class TriggerControllerActionTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{string, \yii\base\Component|null}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Stub WorkflowExecutionService so launch() doesn't actually push into
        // the queue or persist child WorkflowJobSteps. Keeps the test
        // hermetic — we only verify the controller's wiring.
        $this->swapService('workflowExecutionService', new class () extends WorkflowExecutionService {
            public int $launchCalls = 0;
            /** @var array<string, mixed>|null */
            public ?array $lastOverrides = null;
            public ?int $lastLaunchedBy = null;
            public ?string $lastSource = null;
            public bool $throwOnLaunch = false;
            public bool $denyLaunch = false;
            public ?string $rejectWith = null;
            public function launch(WorkflowTemplate $template, int $launchedBy, array $overrides = [], string $source = 'web'): WorkflowJob
            {
                $this->launchCalls++;
                $this->lastOverrides = $overrides;
                $this->lastLaunchedBy = $launchedBy;
                $this->lastSource = $source;
                if ($this->denyLaunch) {
                    throw new WorkflowAccessDeniedException('You may not launch job template(s) #1 of this workflow.', [1]);
                }
                if ($this->rejectWith !== null) {
                    throw new WorkflowLaunchRejectedException($this->rejectWith);
                }
                if ($this->throwOnLaunch) {
                    // A server-side failure, not a refusal the caller can fix.
                    throw new \RuntimeException('Failed to create workflow job: []');
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
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);

        $this->setPost([]);
        $this->presentTriggerToken($raw);

        $ctrl = $this->makeController();
        $result = $ctrl->actionFireWorkflow();

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
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);

        $body = json_encode([
            'extra_vars' => ['env' => 'staging'],
            'limit' => 'web1',
            'verbosity' => 3,
        ]);
        \Yii::$app->request->setRawBody((string)$body);

        $this->setPost([]);
        $this->presentTriggerToken($raw);

        $ctrl = $this->makeController();
        $ctrl->actionFireWorkflow();

        /** @var object{lastOverrides: array<string, mixed>} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame(['extra_vars' => ['env' => 'staging']], $svc->lastOverrides);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function workflowBodyProvider(): array
    {
        return [
            'an object' => ['{"extra_vars": {"env": "prod"}}', ['extra_vars' => ['env' => 'prod']]],
            'null' => ['{"extra_vars": null}', ['extra_vars' => null]],
            'false' => ['{"extra_vars": false}', ['extra_vars' => false]],
            'an empty list' => ['{"extra_vars": []}', ['extra_vars' => []]],
            'a text' => ['{"extra_vars": "env=prod"}', ['extra_vars' => 'env=prod']],
            'no extra_vars' => ['{"limit": "web1"}', []],
            'no JSON object' => ['["extra_vars"]', []],
            'no JSON' => ['extra_vars=1', []],
            'no body' => ['', []],
        ];
    }

    /**
     * The body's extra_vars reach the launch as sent whenever the body has
     * the key, also when they are empty or no object: the launch refuses
     * what it cannot take (422) instead of the trigger dropping it.
     *
     * @dataProvider workflowBodyProvider
     * @param array<string, mixed> $expected
     */
    public function testFireWorkflowForwardsTheExtraVarsOfTheBodyAsSent(string $body, array $expected): void
    {
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody($body);
        $this->presentTriggerToken($raw);

        $this->makeController()->actionFireWorkflow();

        /** @var object{lastOverrides: array<string, mixed>} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame($expected, $svc->lastOverrides);
    }

    /**
     * A launch refused for a reason the caller can fix answers 422 with the
     * reason, as a job trigger does, and records no trigger.fired entry.
     */
    public function testFireWorkflowAnswers422WithTheReasonWhenTheLaunchIsRejected(): void
    {
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);
        /** @var object{rejectWith: string|null} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->rejectWith = WorkflowRunVars::NOT_AN_OBJECT;

        $this->presentTriggerToken($raw);
        $result = $this->makeController()->actionFireWorkflow();

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => WorkflowRunVars::NOT_AN_OBJECT], $this->decodeJson($result));
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $template->id])->exists());
    }

    public function testFireWorkflowThrowsNotFoundForUnknownToken(): void
    {
        $this->setQueryParams(['token' => 'this-token-does-not-exist']);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionFireWorkflow();
    }

    public function testFireWorkflowReturns500OnLaunchException(): void
    {
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);

        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->throwOnLaunch = true;

        $this->setQueryParams(['token' => $raw]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionFireWorkflow();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, $result->statusCode);
        $body = $this->decodeJson($result);
        $this->assertSame(['error' => 'Launch failed.'], $body, 'a server error keeps its details in the log');
    }

    public function testFireWorkflowRunsAsTheUserWhoGeneratedTheToken(): void
    {
        $owner = $this->createUser('wf_owner');
        $generator = $this->createUserWithRole('wf_generator', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$generator->id);

        $this->presentTriggerToken($raw);
        $this->makeController()->actionFireWorkflow();

        /** @var object{lastLaunchedBy: int|null, lastSource: string|null} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame((int)$generator->id, $svc->lastLaunchedBy);
        $this->assertSame('trigger', $svc->lastSource);
    }

    public function testFireWorkflowWithALegacyTokenRunsAsTheWorkflowCreator(): void
    {
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$this->createUser('wf_generator')->id);
        WorkflowTemplate::updateAll(['trigger_token_created_by' => null], ['id' => $template->id]);

        $this->presentTriggerToken($raw, TriggerTokenResolver::TRANSPORT_BEARER);
        $this->makeController()->actionFireWorkflow();

        /** @var object{lastLaunchedBy: int|null} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $this->assertSame((int)$owner->id, $svc->lastLaunchedBy);
    }

    public function testFireWorkflowAnswers403WithoutDetailsWhenTheLaunchIsDenied(): void
    {
        $owner = $this->createUserWithRole('wf_owner', 'operator');
        $template = $this->createWorkflowTemplate($owner->id);
        $raw = $template->generateTriggerToken((int)$template->created_by);

        /** @var object{denyLaunch: bool} $svc */
        $svc = \Yii::$app->get('workflowExecutionService');
        $svc->denyLaunch = true;

        $this->presentTriggerToken($raw);
        $result = $this->makeController()->actionFireWorkflow();

        $this->assertSame(403, $result->statusCode);
        $this->assertSame(['error' => 'Launch refused.'], $this->decodeJson($result));
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
