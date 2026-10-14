<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Fixtures of the API tests that read what runs wait for and what approval
 * requests and jobs belong to (WorkflowJobsStateApiTest,
 * ApprovalsContextApiTest, JobsWorkflowContextApiTest). Use in
 * WebControllerTestCase subclasses together with TeamScopeFixtures,
 * RunStateFixtures and WorkflowEngineFixtures.
 *
 * Runs are launched through the workflow engine, as a launch starts them;
 * approvers hold the operator role (approval.decide).
 */
trait RunContextApiFixtures
{
    /**
     * Sign $user in with a new API token, as BaseApiController::beforeAction()
     * does with the Authorization header it sets too.
     */
    protected function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'run-context-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }

    /**
     * The data of an answer that has one.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];

        return $data;
    }

    /**
     * The items of a list answer, by ID.
     *
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    protected function itemsById(array $result): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $this->data($result);
        $byId = [];
        foreach ($items as $item) {
            $byId[(int)$item['id']] = $item;
        }

        return $byId;
    }

    /**
     * A run of a new workflow that $launcher launched through the engine,
     * waiting at its first step: the approval step "sign off", whose rule
     * lists $approvers (one approval needed), followed by the job step
     * "deploy" of $jobTemplateId.
     *
     * @param list<User> $approvers
     * @return array{run: WorkflowJob, workflow: WorkflowTemplate, gate: WorkflowStep, deploy: WorkflowStep, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    protected function launchedAtApprovalStep(User $launcher, array $approvers, int $jobTemplateId): array
    {
        $rule = $this->ruleOf((int)$launcher->id, $approvers);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $gate = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $deploy = $this->namedStep($workflow, 20, 'deploy', WorkflowStep::TYPE_JOB, $jobTemplateId);
        $run = $this->engine()->launch($workflow, (int)$launcher->id);
        $job = $this->jobOfStep($run, $gate);

        return [
            'run' => $run,
            'workflow' => $workflow,
            'gate' => $gate,
            'deploy' => $deploy,
            'execution' => $this->executionOfStep($run, $gate),
            'job' => $job,
            'request' => $this->requestOfJob($job),
        ];
    }

    /**
     * A run of a new workflow that $launcher launched through the engine,
     * waiting at its first step: the job step "deploy" of $gatedTemplate,
     * whose job waits for its approval rule, followed by the pause step
     * "hold".
     *
     * @return array{run: WorkflowJob, workflow: WorkflowTemplate, deploy: WorkflowStep, hold: WorkflowStep, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    protected function launchedAtGatedJobStep(User $launcher, JobTemplate $gatedTemplate): array
    {
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $deploy = $this->namedStep($workflow, 10, 'deploy', WorkflowStep::TYPE_JOB, (int)$gatedTemplate->id);
        $hold = $this->namedStep($workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$launcher->id);
        $job = $this->jobOfStep($run, $deploy);

        return [
            'run' => $run,
            'workflow' => $workflow,
            'deploy' => $deploy,
            'hold' => $hold,
            'execution' => $this->executionOfStep($run, $deploy),
            'job' => $job,
            'request' => $this->requestOfJob($job),
        ];
    }
}
