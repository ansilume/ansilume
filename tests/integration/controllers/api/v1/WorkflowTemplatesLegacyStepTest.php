<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * API v1 workflow templates whose approval and pause steps still carry a job
 * template ID, as older versions saved them (written here with save(false)).
 *
 * Regression: such a leftover restricted the workflow as if the step ran
 * that job template. A member of the team that operates every job step did
 * not find the workflow in the list, got 403 on it and could not launch it.
 */
class WorkflowTemplatesLegacyStepTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testAMemberListsReadsAndLaunchesAWorkflowWhoseOtherStepsCarryLeftovers(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE, $s['foreign']->id);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, 987654321, $this->createApprovalRule($s['admin']->id)->id);
        $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_JOB, $s['own']->id);
        $this->authenticate($s['member']);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);

        $this->assertContains($workflow->id, array_column($ctrl->actionIndex()['data'], 'id'), 'listed');

        $view = $ctrl->actionView($workflow->id);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($view));
        $this->assertSame(['step-0', 'step-1', 'step-2'], array_column($view['data']['steps'] ?? [], 'name'));

        $launched = $ctrl->actionLaunch($workflow->id);
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($launched));
        $run = WorkflowJob::findOne($launched['data']['workflow_job_id'] ?? 0);
        $this->assertNotNull($run);
        $this->assertSame($s['member']->id, (int)$run->launched_by);
    }

    /**
     * Regression: GET returned every stored target, so a member who now sees
     * the workflow read the ID of another team's job template off its pause
     * step. A step returns only the target of its type, as the workflow page
     * shows it.
     */
    public function testAStepReturnsOnlyTheTargetOfItsType(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE, $s['foreign']->id, $rule->id);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, $s['foreign']->id, $rule->id);
        $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_JOB, $s['own']->id, $rule->id);
        $this->authenticate($s['member']);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);

        $view = $ctrl->actionView($workflow->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($view));
        $steps = $view['data']['steps'] ?? [];
        $this->assertSame(['pause', 'approval', 'job'], array_column($steps, 'step_type'));
        $this->assertSame([null, null, $s['own']->id], array_column($steps, 'job_template_id'));
        $this->assertSame([null, $rule->id, null], array_column($steps, 'approval_rule_id'));
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-legacy-step-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}
