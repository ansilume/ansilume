<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\WorkflowTemplateController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * A launch of a workflow template without steps is refused with the reason
 * (WorkflowExecutionService::NO_STEPS, a WorkflowLaunchRejectedException), as
 * the other launch refusals are: the web launch shows it as a danger flash on
 * the template's page, the API answers 422 with it, and nothing is launched.
 * Only a user who may launch workflows learns it. The trigger's 422 is in
 * TriggerControllerTeamScopeTest.
 */
class WorkflowLaunchNoStepsTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    // -- Web -------------------------------------------------------------------------------

    public function testTheWebLaunchShowsTheReasonAsADangerFlash(): void
    {
        $operator = $this->createUserWithRole('wf-nosteps-web', 'operator');
        $workflow = $this->createWorkflowTemplate((int)$operator->id);
        $this->loginAs($operator);
        $this->setQueryParams(['id' => (string)$workflow->id]);
        $this->setPost([]);
        $controller = $this->webController();

        $controller->runAction('launch');

        $this->assertSame(['/workflow-template/view', 'id' => (int)$workflow->id], $controller->capturedRedirect);
        $this->assertSame(
            ['danger' => 'Launch failed: ' . sprintf(WorkflowExecutionService::NO_STEPS, $workflow->id)],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame(
            "Launch failed: Workflow template #{$workflow->id} has no steps.",
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertNothingWasLaunched($workflow);
    }

    public function testAWebUserWhoMayNotLaunchWorkflowsLearnsNothing(): void
    {
        $viewer = $this->createUserWithRole('wf-nosteps-web-viewer', 'viewer');
        $workflow = $this->createWorkflowTemplate((int)$viewer->id);
        $this->loginAs($viewer);
        $this->setQueryParams(['id' => (string)$workflow->id]);
        $this->setPost([]);

        try {
            $this->webController()->runAction('launch');
            $this->fail('expected the access filter to refuse the launch');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not allowed to perform this action.', $e->getMessage());
        }

        $this->assertSame([], \Yii::$app->session->getAllFlashes());
        $this->assertNothingWasLaunched($workflow);
    }

    // -- API -------------------------------------------------------------------------------

    public function testTheApiLaunchAnswers422WithTheReason(): void
    {
        $operator = $this->createUserWithRole('wf-nosteps-api', 'operator');
        $workflow = $this->createWorkflowTemplate((int)$operator->id);
        $this->authenticate($operator);
        $this->setPost(['extra_vars' => ['env' => 'prod']]);

        $result = $this->apiController()->runAction('launch', ['id' => $workflow->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(WorkflowExecutionService::NO_STEPS, $workflow->id)]], $result);
        $this->assertSame(['error' => ['message' => "Workflow template #{$workflow->id} has no steps."]], $result);
        $this->assertNothingWasLaunched($workflow);
    }

    public function testAnApiCallerWhoMayNotLaunchWorkflowsLearnsNothing(): void
    {
        $viewer = $this->createUserWithRole('wf-nosteps-api-viewer', 'viewer');
        $workflow = $this->createWorkflowTemplate((int)$viewer->id);
        $this->authenticate($viewer);
        $this->setPost([]);

        $result = $this->apiController()->runAction('launch', ['id' => $workflow->id]);

        $this->assertNull($result, 'the action did not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
        $this->assertNothingWasLaunched($workflow);
    }

    // -- Helpers ---------------------------------------------------------------------------

    /**
     * @return WorkflowTemplateController&object{capturedRedirect: array<int|string, mixed>}
     */
    private function webController(): WorkflowTemplateController
    {
        return new class ('workflow-template', $this->app()) extends WorkflowTemplateController {
            /** @var array<int|string, mixed> */
            public array $capturedRedirect = [];

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = (array)$url;

                return new Response();
            }
        };
    }

    private function apiController(): WorkflowTemplatesController
    {
        return new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-no-steps-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * No run, and no workflow.launched entry.
     */
    private function assertNothingWasLaunched(WorkflowTemplate $workflow): void
    {
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'no run');
        $this->assertFalse(
            AuditLog::find()
                ->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED])
                ->andWhere(['like', 'metadata', '"template_id":' . $workflow->id . ','])
                ->exists(),
            'no workflow.launched entry'
        );
    }
}
