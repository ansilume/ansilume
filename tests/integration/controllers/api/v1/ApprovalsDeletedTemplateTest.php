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
 * template was deleted: 409 with the request's status (canceled), the job
 * canceled, nothing recorded.
 *
 * Regression: an approval of a waiting job of a deleted job template queued
 * it (200), although no runner takes a job of a deleted template.
 */
class ApprovalsDeletedTemplateTest extends WebControllerTestCase
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
    public function testAVoteOnAWaitingJobOfADeletedTemplateIs409AndCancelsTheJob(string $action): void
    {
        $s = $this->teamScope();
        $request = $this->request($s['own'], [$s['member']]);
        $this->assertTrue($s['own']->softDelete());
        $this->authenticate($s['member']);

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
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());
        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_JOB_CANCELED, 'object_type' => 'job', 'object_id' => $job->id])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertSame((int)$s['member']->id, (int)$entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], json_decode((string)$entry->metadata, true));
    }

    /**
     * A request whose job's template was deleted is still read, on its own
     * and in the list: the job of a gated job step still names its workflow
     * run, step execution and step; a job outside workflows names none.
     */
    public function testARequestOfADeletedTemplatesJobIsStillReadWithItsWorkflowRun(): void
    {
        $s = $this->teamScope();
        $plain = $this->request($s['own'], [$s['admin']]);
        $gated = $this->request($s['open'], [$s['admin']]);
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, (int)$s['open']->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$s['admin']->id);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$gated->job_id;
        $execution->snapshotStep($step);
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->save(false);
        $this->assertTrue($s['own']->softDelete());
        $this->assertTrue($s['open']->softDelete());
        $this->authenticate($s['admin']);

        $listed = array_column($this->controller()->actionIndex()['data'], null, 'id');
        $plainView = $this->controller()->actionView((int)$plain->id);
        $gatedView = $this->controller()->actionView((int)$gated->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
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
     * A job of $template waiting for $approvers, launched by a user who is
     * no approver.
     *
     * @param list<User> $approvers
     */
    private function request(JobTemplate $template, array $approvers): ApprovalRequest
    {
        $job = $this->createJob((int)$template->id, (int)$this->createUser('launcher')->id, Job::STATUS_PENDING_APPROVAL);
        $rule = $this->createApprovalRule(
            (int)$template->created_by,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $u): int => (int)$u->id, $approvers)])
        );

        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }
}
