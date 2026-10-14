<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\services\WorkflowEngineFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * Where a job comes from, through the API (contract C13): the JobDetail of
 * GET /api/v1/jobs/{id}, of the launch (POST /api/v1/jobs) and of the
 * cancel (POST /api/v1/jobs/{id}/cancel) links the job to its approval
 * request (approval_request_id) and to the workflow run and step execution
 * it was created for (workflow_job_id, workflow_job_step_id), each null
 * when there is none or the caller may not see it. The list's Job items
 * stay as they were.
 */
class JobsWorkflowContextApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WorkflowEngineFixtures;
    use RunContextApiFixtures;

    private const LINK_FIELDS = ['approval_request_id', 'workflow_job_id', 'workflow_job_step_id'];

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->namedUser('jwc-launcher', 'operator');
        $this->approver = $this->namedUser('jwc-approver', 'operator');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workflowStepProvider(): array
    {
        return [
            'the placeholder of an approval step' => [WorkflowStep::TYPE_APPROVAL],
            'the job of a gated job step' => [WorkflowStep::TYPE_JOB],
        ];
    }

    /**
     * The job a workflow step created links its approval request, its run
     * and its step execution.
     *
     * @dataProvider workflowStepProvider
     */
    public function testTheJobOfAWorkflowStepLinksItsRequestAndItsRun(string $stepType): void
    {
        $templateId = (int)$this->openTemplate((int)$this->launcher->id)->id;
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver]);
        $launched = $stepType === WorkflowStep::TYPE_APPROVAL
            ? $this->launchedAtApprovalStep($this->launcher, [$this->approver], $templateId)
            : $this->launchedAtGatedJobStep($this->launcher, $this->gatedTemplate((int)$this->launcher->id, $rule));
        $this->authenticate($this->approver);

        $data = $this->data($this->controller()->actionView((int)$launched['job']->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([
            'approval_request_id' => (int)$launched['request']->id,
            'workflow_job_id' => (int)$launched['run']->id,
            'workflow_job_step_id' => (int)$launched['execution']->id,
        ], $this->linksOf($data));
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $data['status']);
        $this->assertArrayHasKey('credentials', $data, 'the other JobDetail fields stay');
    }

    /**
     * A job launched outside workflows has no run; one of a gated template
     * names the request it waits for already in the launch's answer, and a
     * plain one has none.
     */
    public function testALaunchedJobLinksOnlyItsRequest(): void
    {
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver]);
        $gated = $this->gatedTemplate((int)$this->launcher->id, $rule);
        $plain = $this->openTemplate((int)$this->launcher->id);
        $this->authenticate($this->launcher);

        $launchedGated = $this->launch($gated);
        $request = ApprovalRequest::findOne(['job_id' => $launchedGated['id']]);
        $this->assertNotNull($request);
        $launchedPlain = $this->launch($plain);

        $this->assertSame(['approval_request_id' => (int)$request->id, 'workflow_job_id' => null, 'workflow_job_step_id' => null], $this->linksOf($launchedGated));
        $this->assertSame(array_fill_keys(self::LINK_FIELDS, null), $this->linksOf($launchedPlain));
        $this->assertSame($this->linksOf($launchedGated), $this->linksOf($this->data($this->controller()->actionView((int)$launchedGated['id']))));
        $this->assertSame($this->linksOf($launchedPlain), $this->linksOf($this->data($this->controller()->actionView((int)$launchedPlain['id']))));
    }

    /**
     * The cancel's answer still links the request, now canceled with the
     * job, and the run.
     */
    public function testTheCancelAnswerKeepsTheLinks(): void
    {
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver]);
        $launched = $this->launchedAtGatedJobStep($this->launcher, $this->gatedTemplate((int)$this->launcher->id, $rule));
        $this->authenticate($this->launcher);

        $data = $this->data($this->controller()->actionCancel((int)$launched['job']->id));

        $this->assertSame(Job::STATUS_CANCELED, $data['status']);
        $this->assertSame([
            'approval_request_id' => (int)$launched['request']->id,
            'workflow_job_id' => (int)$launched['run']->id,
            'workflow_job_step_id' => (int)$launched['execution']->id,
        ], $this->linksOf($data));
        $launched['request']->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $launched['request']->status);
    }

    /**
     * Team scoping: a member may read the job of a gated job step through
     * its job template's project while its workflow stays hidden from them
     * (another of its steps belongs to another team). The job links its
     * request, which the member may see, but not the run.
     */
    public function testACallerWhoMayNotViewTheWorkflowGetsNoRun(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $rule = $this->ruleOf($adminId, [$s['member']]);
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $s['own']->id]);
        $workflow = $this->createWorkflowTemplate($adminId);
        $deploy = $this->namedStep($workflow, 10, 'deploy', WorkflowStep::TYPE_JOB, (int)$s['own']->id);
        $this->namedStep($workflow, 20, 'foreign rollout', WorkflowStep::TYPE_JOB, (int)$s['foreign']->id);
        $run = $this->engine()->launch($workflow, $adminId);
        $job = $this->jobOfStep($run, $deploy);
        $request = $this->requestOfJob($job);

        $this->authenticate($s['member']);
        $asMember = $this->data($this->controller()->actionView((int)$job->id));
        $this->authenticate($s['admin']);
        $asAdmin = $this->data($this->controller()->actionView((int)$job->id));

        $this->assertSame(['approval_request_id' => (int)$request->id, 'workflow_job_id' => null, 'workflow_job_step_id' => null], $this->linksOf($asMember));
        $this->assertSame((int)$run->id, $asAdmin['workflow_job_id']);
        $this->assertSame((int)$this->executionOfStep($run, $deploy)->id, $asAdmin['workflow_job_step_id']);
    }

    /**
     * The list's items are Job, not JobDetail: no links, so the list reads
     * nothing per job for them.
     */
    public function testTheListItemsCarryNoLinks(): void
    {
        $templateId = (int)$this->openTemplate((int)$this->launcher->id)->id;
        $launched = $this->launchedAtApprovalStep($this->launcher, [$this->approver], $templateId);
        $this->authenticate($this->approver);

        $items = $this->itemsById($this->controller()->actionIndex());

        $item = $items[(int)$launched['job']->id];
        foreach (self::LINK_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $item);
        }
        $this->assertArrayNotHasKey('credentials', $item);
    }

    // -- Helpers ----------------------------------------------------------------------

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }

    /**
     * POST /api/v1/jobs of $template: 201 with the new job.
     *
     * @return array<string, mixed>
     */
    private function launch(JobTemplate $template): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_id' => (int)$template->id]);
        $result = $this->controller()->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));

        return $this->data($result);
    }

    /**
     * The link fields of a JobDetail, in their order.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function linksOf(array $data): array
    {
        foreach (self::LINK_FIELDS as $field) {
            $this->assertArrayHasKey($field, $data);
        }

        return array_intersect_key($data, array_flip(self::LINK_FIELDS));
    }
}
