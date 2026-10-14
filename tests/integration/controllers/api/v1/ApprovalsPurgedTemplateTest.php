<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\models\ApiToken;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\JobCancellationService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * POST /api/v1/approvals/{id}/approve and /reject on a request whose job's
 * template was purged (deleting a project purges its deleted templates, and
 * the foreign key sets job.job_template_id to NULL): 409 with the request's
 * status (canceled), the job canceled, nothing recorded.
 *
 * Regression: an approval of such a job answered 200 and queued it, and no
 * runner claims a job without a template.
 */
class ApprovalsPurgedTemplateTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string}>
     */
    public static function actionProvider(): array
    {
        return [
            'approve' => ['actionApprove'],
            'reject' => ['actionReject'],
        ];
    }

    /**
     * @dataProvider actionProvider
     */
    public function testRegressionAVoteOnAWaitingJobWhoseTemplateWasPurgedIs409AndCancelsTheJob(string $action): void
    {
        $approver = $this->createUserWithRole('purged-api-approver', 'admin');
        $request = $this->request([$approver]);
        $this->authenticate($approver);

        $result = $this->controller()->$action((int)$request->id);

        $this->assertSame(['error' => [
            'message' => sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, (int)$request->id, (int)$request->job_id),
            'status' => ApprovalRequest::STATUS_CANCELED,
        ]], $result);
        $this->assertSame(409, \Yii::$app->response->statusCode);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $job = Job::findOne($request->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'never queued');
        $this->assertNull($job->queued_at);
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());
        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_CANCELED, 'object_type' => 'job', 'object_id' => $job->id])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertSame((int)$approver->id, (int)$entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], json_decode((string)$entry->metadata, true));
    }

    /**
     * A request whose job's template was purged is still read, on its own
     * and in the list, with its job and launcher; a job outside workflows
     * names no workflow run. The job of a gated job step still names its
     * run: that comes from the step execution, not from the template.
     */
    public function testARequestOfAPurgedTemplatesJobIsStillRead(): void
    {
        $approver = $this->createUserWithRole('purged-api-reader', 'admin');
        $plain = $this->request([$approver]);
        $gated = $this->request([$approver]);
        $workflow = $this->createWorkflowTemplate((int)$approver->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$approver->id);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$gated->job_id;
        $execution->snapshotStep($step);
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);
        $this->authenticate($approver);

        $listed = array_column($this->controller()->actionIndex()['data'], null, 'id');
        $plainView = $this->controller()->actionView((int)$plain->id);
        $gatedView = $this->controller()->actionView((int)$gated->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame((int)$plain->job_id, $plainView['data']['job_id']);
        $this->assertSame($plain->launcherId(), $plainView['data']['launched_by']);
        $none = ['workflow_job_id' => null, 'workflow_job_step_id' => null, 'workflow_template_id' => null, 'workflow_step_name' => null];
        $ofRun = [
            'workflow_job_id' => (int)$run->id,
            'workflow_job_step_id' => (int)$execution->id,
            'workflow_template_id' => (int)$workflow->id,
            'workflow_step_name' => $step->name,
        ];
        $this->assertSame($none, array_intersect_key($plainView['data'], $none));
        $this->assertSame($ofRun, array_intersect_key($gatedView['data'], $ofRun));
        $this->assertSame($none, array_intersect_key($listed[(int)$plain->id], $none));
        $this->assertSame($ofRun, array_intersect_key($listed[(int)$gated->id], $ofRun));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): ApprovalsController
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;

        return new ApprovalsController('api/v1/approvals', $app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'approvals-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
    }

    /**
     * A job waiting for $approvers, launched by a user who is no approver,
     * whose template was then purged as a project delete purged it before
     * its waiting jobs were canceled first.
     *
     * @param list<User> $approvers
     */
    private function request(array $approvers): ApprovalRequest
    {
        $owner = $this->createUser('purged-api-owner');
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
        $job = $this->createJob((int)$template->id, (int)$this->createUser('purged-api-launcher')->id, Job::STATUS_PENDING_APPROVAL);
        Job::updateAll(['queued_at' => null], ['id' => $job->id]);
        $rule = $this->createApprovalRule(
            (int)$owner->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $u): int => (int)$u->id, $approvers)])
        );

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        $this->assertTrue($template->softDelete());
        $this->assertSame(1, JobTemplate::deleteAll(['id' => $template->id]));
        $job->refresh();
        $this->assertNull($job->job_template_id);

        return $request;
    }
}
