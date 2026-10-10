<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalService;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: older versions saved a job template ID on approval and pause
 * steps (the add-step form posted the hidden job template dropdown, the API
 * stored job_template_id for every step type). Team scoping counted it as if
 * the step ran that job template, although dispatch never uses it. A
 * pause-only or approval-only workflow carrying another team's or a purged
 * template was hidden from the team and could not be launched, and the
 * approvers of such an approval step could no longer decide, so a run waited
 * at that step for ever. Only job steps decide now.
 *
 * The legacy rows are written with save(false), as the old code did.
 */
class WorkflowLegacyStepTargetTest extends DbTestCase
{
    use TeamScopeFixtures;

    /** A job template ID whose template was purged with its project. */
    private const PURGED = 987654321;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function leftoverProvider(): array
    {
        return [
            'pause step, another team\'s template' => [WorkflowStep::TYPE_PAUSE, 'foreign'],
            'pause step, purged template' => [WorkflowStep::TYPE_PAUSE, 'purged'],
            'approval step, another team\'s template' => [WorkflowStep::TYPE_APPROVAL, 'foreign'],
            'approval step, purged template' => [WorkflowStep::TYPE_APPROVAL, 'purged'],
        ];
    }

    /**
     * @dataProvider leftoverProvider
     */
    public function testALeftoverTemplateLeavesTheWorkflowListedViewableAndLaunchable(string $stepType, string $leftover): void
    {
        $s = $this->teamScope();
        $member = (int)$s['member']->id;
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $ruleId = $stepType === WorkflowStep::TYPE_APPROVAL ? (int)$this->createApprovalRule((int)$s['admin']->id)->id : null;
        $this->createWorkflowStep($workflow->id, 0, $stepType, $this->leftoverId($s, $leftover), $ruleId);

        $this->assertSame([$workflow->id], $this->listedIds($member, $workflow, false), 'listed');
        $this->assertSame([$workflow->id], $this->listedIds($member, $workflow, true), 'listed as launchable');
        $this->assertTrue($this->checker()->canViewWorkflowTemplate($member, $workflow->id));
        $this->assertTrue($this->checker()->canOperateWorkflowTemplate($member, $workflow->id));
        $this->assertSame([], $this->checker()->deniedJobTemplateIds($member, $workflow->id, false));

        $run = $this->executor()->launch($workflow, $member, [], 'api');

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame($member, (int)$run->launched_by);
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id])->exists()
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function leftoverTemplateProvider(): array
    {
        return ['another team\'s template' => ['foreign'], 'purged template' => ['purged']];
    }

    /**
     * The approver can decide, and the decision moves the run on: it does
     * not wait at the approval step for ever.
     *
     * @dataProvider leftoverTemplateProvider
     */
    public function testTheApproversOfALegacyApprovalStepStayEligible(string $leftover): void
    {
        $s = $this->teamScope();
        $member = (int)$s['member']->id;
        $rule = $this->createApprovalRule(
            (int)$s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$member]])
        );
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_APPROVAL, $this->leftoverId($s, $leftover), (int)$rule->id);
        $run = $this->executor()->launch($workflow, (int)$s['admin']->id);
        $request = $this->requestOf($run);

        $this->assertTrue($this->approvals()->canUserApprove($request, $member));
        $this->assertSame([$member], $this->approvals()->eligibleApproverIds($request));
        $this->assertTrue($this->checker()->canViewApprovalRequest($member, $request));
        $placeholder = Job::findOne($request->job_id);
        $this->assertNotNull($placeholder);
        $this->assertTrue($this->checker()->canAccessJob($member, $placeholder, true), 'the placeholder job follows the workflow');

        $this->approvals()->recordDecision($request, $member, 'approved');

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_SUCCEEDED, $run->status);
    }

    /**
     * Control: a job step on such a template still fails closed.
     *
     * @dataProvider leftoverTemplateProvider
     */
    public function testAJobStepOnSuchATemplateStillFailsClosed(string $target): void
    {
        $s = $this->teamScope();
        $member = (int)$s['member']->id;
        $templateId = $this->leftoverId($s, $target);
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_JOB, $templateId);

        $this->assertSame([], $this->listedIds($member, $workflow, false));
        $this->assertFalse($this->checker()->canViewWorkflowTemplate($member, $workflow->id));
        $this->assertSame([$templateId], $this->checker()->deniedJobTemplateIds($member, $workflow->id, false));
        try {
            $this->executor()->launch($workflow, $member);
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException) {
            $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
        }
    }

    /**
     * Next to job steps, leftovers neither restrict nor show up in a
     * refusal: only the job steps' templates are named.
     */
    public function testOnlyTheJobStepsDecideAndAreNamed(): void
    {
        $s = $this->teamScope();
        $member = (int)$s['member']->id;
        $workflow = $this->createWorkflowTemplate((int)$s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$s['viewed']->id);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_PAUSE, (int)$s['foreign']->id);
        $this->createWorkflowStep(
            $workflow->id,
            2,
            WorkflowStep::TYPE_APPROVAL,
            self::PURGED,
            (int)$this->createApprovalRule((int)$s['admin']->id)->id
        );

        $this->assertSame([$workflow->id], $this->listedIds($member, $workflow, false));
        $this->assertSame([], $this->listedIds($member, $workflow, true), 'the viewed job step needs the operator role');
        $this->assertSame([(int)$s['viewed']->id], $this->checker()->deniedJobTemplateIds($member, $workflow->id));

        $this->expectException(WorkflowAccessDeniedException::class);
        $this->expectExceptionMessage("You may not launch job template(s) #{$s['viewed']->id} of this workflow.");
        $this->checker()->assertMayLaunch($member, $workflow, 'web');
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @param array{member: \app\models\User, admin: \app\models\User, outsider: \app\models\User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     */
    private function leftoverId(array $s, string $leftover): int
    {
        return $leftover === 'purged' ? self::PURGED : (int)$s['foreign']->id;
    }

    /**
     * @return list<int> the workflow's ID when the list filter for $userId keeps it
     */
    private function listedIds(int $userId, WorkflowTemplate $workflow, bool $operate): array
    {
        $query = WorkflowTemplate::find()->select('workflow_template.id')->andWhere(['workflow_template.id' => $workflow->id]);
        $filter = $this->checker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id', $operate);
        $this->assertNotNull($filter, 'team-restricted projects exist, so the list is filtered');
        $query->andWhere($filter);

        return array_map('intval', $query->column());
    }

    private function requestOf(WorkflowJob $run): ApprovalRequest
    {
        /** @var WorkflowJobStep|null $step */
        $step = WorkflowJobStep::findOne(['workflow_job_id' => $run->id]);
        $this->assertNotNull($step);
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::findOne(['job_id' => $step->job_id]);
        $this->assertNotNull($request, 'the approval step asked for a decision');

        return $request;
    }

    private function checker(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    private function executor(): WorkflowExecutionService
    {
        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');
        return $service;
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
    }
}
