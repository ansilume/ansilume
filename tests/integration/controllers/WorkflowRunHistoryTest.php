<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\WorkflowJobsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\WorkflowJobController;
use app\controllers\WorkflowTemplateController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\Response;
use yii\web\View;

/**
 * regression: removing a step deleted the run history of past runs. The
 * executions of a removed or replaced step were deleted with it (ON DELETE
 * CASCADE), and once the schema kept them, the run page, its status poll and
 * GET /api/v1/workflow-jobs/{id} still named them "—": they read the name
 * from the live step. An execution now keeps the name and type its step had.
 */
class WorkflowRunHistoryTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testARunStillNamesAStepThatTheWebRemoved(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $wait = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $wait->name = 'Wait for <the> window';
        $wait->save(false);
        $run = $this->finishedRunOf($workflow, $s['admin']->id, $wait);
        $this->loginAs($s['admin']);
        $this->setPost(['step_id' => (string)$wait->id]);

        $this->templateController()->actionRemoveStep((int)$workflow->id);

        $this->assertNull(WorkflowStep::findOne($wait->id), 'the step is removed');
        $this->assertSame([[
            'workflow_step_id' => null,
            'step_name' => 'Wait for <the> window',
            'step_type' => WorkflowStep::TYPE_PAUSE,
        ]], $this->statusSteps($run));
        $this->assertSame([[
            'workflow_step_id' => null,
            'step_name' => 'Wait for <the> window',
            'step_type' => WorkflowStep::TYPE_PAUSE,
        ]], $this->apiSteps($run, $s['admin']));
        $html = $this->runPage($run);
        $this->assertStringContainsString('<td data-wjs-name-cell>Wait for &lt;the&gt; window</td>', $html);
        $this->assertStringContainsString('<td data-wjs-type-cell>Pause</td>', $html);
    }

    public function testARunStillNamesTheStepsThatTheApiReplaced(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $old = WorkflowStep::findOne(['workflow_template_id' => $workflow->id]);
        $this->assertNotNull($old);
        $run = $this->finishedRunOf($workflow, $s['member']->id, $old);
        $this->authenticate($s['member']);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['steps' => [['name' => 'new', 'job_template_id' => $s['own']->id]]]);

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->actionUpdate((int)$workflow->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertNull(WorkflowStep::findOne($old->id));
        $this->assertSame([[
            'workflow_step_id' => null,
            'step_name' => 'step-0',
            'step_type' => WorkflowStep::TYPE_JOB,
        ]], $this->apiSteps($run, $s['member']));
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A succeeded run that executed $step.
     */
    private function finishedRunOf(WorkflowTemplate $workflow, int $launchedBy, WorkflowStep $step): WorkflowJob
    {
        $run = $this->createWorkflowJob((int)$workflow->id, $launchedBy, WorkflowJob::STATUS_SUCCEEDED);
        $run->current_step_id = (int)$step->id;
        $run->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->status = WorkflowJobStep::STATUS_SUCCEEDED;
        $execution->started_at = time() - 5;
        $execution->finished_at = time();
        $execution->save(false);

        return $run;
    }

    /**
     * The steps of the run's status poll: step ID, name and type.
     *
     * @return list<array{workflow_step_id: mixed, step_name: mixed, step_type: mixed}>
     */
    private function statusSteps(WorkflowJob $run): array
    {
        $payload = (new WorkflowJobController('workflow-job', \Yii::$app))->actionStatus((int)$run->id);
        /** @var list<array<string, mixed>> $steps */
        $steps = $payload['steps'];

        return array_map(self::identity(...), $steps);
    }

    /**
     * The steps of GET /api/v1/workflow-jobs/{id}: step ID, name and type.
     *
     * @return list<array{workflow_step_id: mixed, step_name: mixed, step_type: mixed}>
     */
    private function apiSteps(WorkflowJob $run, User $caller): array
    {
        $this->authenticate($caller);
        $result = (new WorkflowJobsController('api/v1/workflow-jobs', \Yii::$app))->actionView((int)$run->id);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        /** @var array{steps: list<array<string, mixed>>} $data */
        $data = $result['data'] ?? ['steps' => []];
        $steps = $data['steps'];

        return array_map(self::identity(...), $steps);
    }

    /**
     * Step ID, name and type of a serialized execution; "missing" for a key
     * it does not have.
     *
     * @param array<string, mixed> $step
     * @return array{workflow_step_id: mixed, step_name: mixed, step_type: mixed}
     */
    private static function identity(array $step): array
    {
        $field = static fn (string $key): mixed => array_key_exists($key, $step) ? $step[$key] : 'missing';

        return [
            'workflow_step_id' => $field('workflow_step_id'),
            'step_name' => $field('step_name'),
            'step_type' => $field('step_type'),
        ];
    }

    /**
     * The run page, rendered with what actionView passes to it.
     */
    private function runPage(WorkflowJob $run): string
    {
        $ctrl = new class ('workflow-job', \Yii::$app) extends WorkflowJobController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }
        };
        $ctrl->actionView((int)$run->id);
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $renderer = new WorkflowJobController('workflow-job', \Yii::$app);
        \Yii::$app->controller = $renderer;
        try {
            return $renderer->renderPartial('view', $ctrl->capturedParams);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function templateController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-run-history-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}
