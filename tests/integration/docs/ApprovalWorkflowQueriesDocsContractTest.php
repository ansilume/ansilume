<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\Schedule;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only queries that docs/updating.md gives for the update to
 * approvals and workflows run on the schema of this release and list what
 * their text says. The tests run each ```sql block of the section in the
 * test's transaction, on fixtures of the cases each query is about.
 *
 * Regressions from the review of step 3, each test fails on the docs as
 * they were before it: the fifth query missed the gated job steps with an
 * Always branch (contract-1); none listed the approval steps without a rule,
 * the jobs that wait for approval without a request, or the gated workflow
 * job steps that 2.9.0 marked succeeded without running them (the
 * operations gaps); and the third stopped MySQL 8 at an approver list that
 * is no valid JSON and missed team approvers kept as text.
 *
 * Regressions from the last review round of step 3, each fails on the docs
 * as they were before it: no query listed the approval rules whose approver
 * list an older version stored as no JSON object, which an API update now
 * refuses until it sends an object; and none listed the schedules of gated
 * job templates that users other than the one they run as had changed,
 * whose jobs these users may still approve, as the update changes no
 * schedule's user.
 */
class ApprovalWorkflowQueriesDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use UpdateSectionQueries;
    use TeamScopeFixtures;
    use CancellationFixtures;

    /**
     * The queries of the section, by their place: the third, fifth, ninth,
     * tenth and eleventh before the update, then the schedules that others
     * changed, then the two of gated job templates
     * (GatedTemplateBehaviourDocsContractTest), then the history, the last.
     */
    private const RULES = 3;
    private const ROUTE_CHANGES = 5;
    private const STEPS_WITHOUT_RULE = 9;
    private const JOBS_WITHOUT_REQUEST = 10;
    private const APPROVER_LISTS_NO_OBJECT = 11;
    private const SCHEDULES_CHANGED_BY_OTHERS = 12;
    private const MARKED_SUCCEEDED = 15;

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Every query of the section is a read-only SELECT that runs on the
     * schema of this release: none rots when a column changes.
     */
    public function testEveryQueryOfTheSectionIsAReadOnlySelectThatRuns(): void
    {
        $queries = $this->queries();
        $this->assertCount(self::MARKED_SUCCEEDED, $queries);
        foreach ($queries as $index => $sql) {
            $this->assertSame(1, preg_match('/^\s*SELECT\b/i', self::withoutComments($sql)), 'Query ' . ($index + 1) . ' is a SELECT.');
            $this->assertIsArray($this->rowsOf($index + 1), 'Query ' . ($index + 1) . ' runs.');
        }
    }

    /**
     * Regression (review of step 3, operations gap and MySQL 8): the third
     * query reads an approver list that is no valid JSON as {} (MySQL 8
     * stops at it with an error), and counts a team approver also when the
     * rule keeps the team ID as text, as the code does.
     */
    public function testTheThirdQueryCountsTheApproversAsTheCodeDoes(): void
    {
        $member = $this->createUser('docs-rule-team-member');
        $team = $this->createTeam((int)$member->id);
        \Yii::$app->db->createCommand()->insert('{{%team_member}}', [
            'team_id' => (int)$team->id,
            'user_id' => (int)$member->id,
            'created_at' => time(),
        ])->execute();
        $text = $this->createApprovalRule((int)$member->id, 'team', (string)json_encode(['team_id' => (string)$team->id]));
        $empty = $this->createApprovalRule((int)$member->id, 'users', '');
        $this->assertSame([(int)$member->id], $text->getApproverUserIds(), 'The code counts the member.');

        $rows = $this->rowsByColumn(self::RULES, 'id', [(int)$text->id, (int)$empty->id]);

        $this->assertSame(1, (int)$rows[(int)$text->id]['active_approvers']);
        $this->assertSame(0, (int)$rows[(int)$empty->id]['active_approvers']);
    }

    /**
     * Regression (review of step 3, contract-1): the fifth query listed a
     * gated job step only without an On Failure branch. One with an Always
     * branch changes its route as well: after a rejection 2.9.0 took the
     * Always branch, and the router now takes On Failure. The query lists
     * exactly the approval gates whose route after a closed gate changes.
     */
    public function testTheFifthQueryListsEveryApprovalGateWhoseRouteChanges(): void
    {
        $userId = (int)$this->createUser('docs-route-changes')->id;
        $rule = $this->usersRule($userId, $userId);
        $gated = $this->cancelFixtureTemplate($userId);
        $gated->approval_rule_id = (int)$rule->id;
        $this->assertTrue($gated->save(false));
        $open = (int)$this->cancelFixtureTemplate($userId)->id;
        $workflowId = (int)$this->createWorkflowTemplate($userId)->id;
        $target = (int)$this->createWorkflowStep($workflowId, 20, WorkflowStep::TYPE_PAUSE)->id;
        $ruleId = (int)$rule->id;
        $gatedId = (int)$gated->id;

        $listed = [
            $this->routedStep($workflowId, 1, WorkflowStep::TYPE_APPROVAL, null, $ruleId, null, null),
            $this->routedStep($workflowId, 2, WorkflowStep::TYPE_APPROVAL, null, $ruleId, $target, $target),
            $this->routedStep($workflowId, 3, WorkflowStep::TYPE_JOB, $gatedId, null, null, null),
            $this->routedStep($workflowId, 4, WorkflowStep::TYPE_JOB, $gatedId, null, $target, $target),
            $this->routedStep($workflowId, 5, WorkflowStep::TYPE_JOB, $gatedId, null, WorkflowStep::END_WORKFLOW, $target),
        ];
        // Their route stays: On Failure only, or no gate at all.
        $this->routedStep($workflowId, 6, WorkflowStep::TYPE_APPROVAL, null, $ruleId, $target, null);
        $this->routedStep($workflowId, 7, WorkflowStep::TYPE_JOB, $gatedId, null, $target, null);
        $this->routedStep($workflowId, 8, WorkflowStep::TYPE_JOB, $open, null, null, $target);

        $this->assertSame($listed, $this->stepIdsOf(self::ROUTE_CHANGES, $workflowId));
    }

    /**
     * Regression (review of step 3): the ninth query lists the approval
     * steps of live workflows without an approval rule that exists, which
     * the update refuses at launch: without a rule, and with the ID of a
     * rule that is gone.
     */
    public function testTheNinthQueryListsTheApprovalStepsWithoutARule(): void
    {
        $userId = (int)$this->createUser('docs-steps-without-rule')->id;
        $workflowId = (int)$this->createWorkflowTemplate($userId)->id;
        $withoutRule = (int)$this->createWorkflowStep($workflowId, 1, WorkflowStep::TYPE_APPROVAL)->id;
        $db = \Yii::$app->db;
        // A step whose rule was deleted before the foreign key existed.
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $ruleGone = (int)$this->createWorkflowStep($workflowId, 2, WorkflowStep::TYPE_APPROVAL, null, 987654321)->id;
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
        $this->createWorkflowStep($workflowId, 3, WorkflowStep::TYPE_APPROVAL, null, (int)$this->usersRule($userId, $userId)->id);
        $this->createWorkflowStep($workflowId, 4, WorkflowStep::TYPE_PAUSE);

        $this->assertSame([$withoutRule, $ruleGone], $this->stepIdsOf(self::STEPS_WITHOUT_RULE, $workflowId));
    }

    /**
     * Regression (review of step 3): the tenth query lists the jobs that
     * wait for approval without any approval request, and the workflow run
     * that waits for such a job; a job with its request is not listed.
     */
    public function testTheTenthQueryListsTheJobsThatWaitForApprovalWithoutARequest(): void
    {
        $launcher = $this->createUserWithRole('docs-no-request', 'operator');
        $approver = $this->createUserWithRole('docs-no-request-approver', 'operator');
        $template = $this->cancelFixtureTemplate((int)$launcher->id);
        $orphan = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $workflowId = (int)$this->createWorkflowTemplate((int)$launcher->id)->id;
        $step = $this->createWorkflowStep($workflowId, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob($workflowId, (int)$launcher->id);
        $this->linkExecution($run, $step, (int)$orphan->id);
        $this->recordNotifications();
        ['job' => $decidable] = $this->jobWaitingForApproval($template, (int)$launcher->id, (int)$approver->id);

        $rows = $this->rowsByColumn(self::JOBS_WITHOUT_REQUEST, 'id', [(int)$orphan->id, (int)$decidable->id]);

        $this->assertSame([(int)$orphan->id], array_keys($rows));
        $this->assertSame((int)$run->id, (int)$rows[(int)$orphan->id]['workflow_run']);
    }

    /**
     * Regression (last review round of step 3, legacy approver lists): the
     * eleventh query lists exactly the rules whose approver list the model
     * refuses as no JSON object, a list or a single value an older version
     * stored; not an object, an empty list or a list that is no valid JSON,
     * which the model refuses for other reasons.
     */
    public function testTheEleventhQueryListsTheRulesWhoseApproverListIsNoObject(): void
    {
        $userId = (int)$this->createUser('docs-approver-lists')->id;
        $configs = [
            'list' => '[7, 12]',
            'empty list' => '[]',
            'number' => '5',
            'text' => '"operator"',
            'object' => '{"user_ids": [7]}',
            'empty object' => '{}',
            'no JSON' => '{not json',
            'empty' => '',
        ];
        $rules = [];
        $refused = [];
        foreach ($configs as $what => $config) {
            $rule = $this->createApprovalRule($userId, ApprovalRule::APPROVER_TYPE_USERS, $config);
            $rules[$what] = (int)$rule->id;
            $rule->validate(['approver_config']);
            $label = $rule->getAttributeLabel('approver_config');
            if ($rule->getFirstError('approver_config') === sprintf(ApprovalRule::NOT_A_JSON_OBJECT, $label)) {
                $refused[] = (int)$rule->id;
            }
        }

        $listed = array_keys($this->rowsByColumn(self::APPROVER_LISTS_NO_OBJECT, 'id', array_values($rules)));

        $this->assertSame([$rules['list'], $rules['empty list'], $rules['number'], $rules['text']], $listed);
        $this->assertSame($refused, $listed, 'The query lists the rules the model refuses as no JSON object.');
    }

    /**
     * Regression (last review round of step 3, decision 4 on schedules): the
     * update changes no schedule's user. The twelfth query lists the
     * schedules of job templates with an approval rule that users other than
     * the one they run as changed or toggled, with these users; not those
     * only their own user changed, a schedule of a template without a rule
     * or of a deleted one, nor a toggle by the system.
     */
    public function testTheTwelfthQueryListsTheGatedSchedulesThatOtherUsersChanged(): void
    {
        $owner = (int)$this->createUser('docs-schedule-owner')->id;
        $editor = (int)$this->createUser('docs-schedule-editor')->id;
        $other = (int)$this->createUser('docs-schedule-other')->id;
        $rule = (int)$this->usersRule($owner, $owner, $editor)->id;
        $gated = $this->cancelFixtureTemplate($owner);
        $gated->approval_rule_id = $rule;
        $this->assertTrue($gated->save(false));
        $deleted = $this->cancelFixtureTemplate($owner);
        $deleted->approval_rule_id = $rule;
        $this->assertTrue($deleted->save(false));
        $open = (int)$this->cancelFixtureTemplate($owner)->id;

        $changed = $this->scheduleChangedBy((int)$gated->id, $owner, [$other, $editor, $owner, $editor]);
        $this->scheduleChangedBy((int)$gated->id, $owner, [$owner]);
        $this->scheduleChangedBy((int)$gated->id, $owner, [null]);
        $this->scheduleChangedBy($open, $owner, [$editor]);
        $this->scheduleChangedBy((int)$deleted->id, $owner, [$editor]);
        $this->assertTrue($deleted->softDelete());

        $rows = $this->rowsOf(self::SCHEDULES_CHANGED_BY_OTHERS);

        $this->assertSame([$changed], array_map(static fn (array $row): int => (int)$row['id'], $rows));
        $this->assertSame($owner, (int)$rows[0]['runs_as']);
        $this->assertSame(implode(',', [min($editor, $other), max($editor, $other)]), (string)$rows[0]['changed_by']);
    }

    /**
     * Regression (review of step 3, operations gap 3): the history query
     * lists the jobs of gated workflow job steps that an approval, or a
     * timeout that approved, marked succeeded without running them; not a
     * job that ran, nor an approval step's placeholder.
     */
    public function testTheHistoryQueryListsTheGatedJobsMarkedSucceededWithoutRunning(): void
    {
        $userId = (int)$this->createUser('docs-marked-succeeded')->id;
        $template = $this->cancelFixtureTemplate($userId);
        $rule = $this->usersRule($userId, $userId);
        $approved = $this->decidedJob((int)$template->id, $userId, (int)$rule->id, ApprovalRequest::STATUS_APPROVED, null);
        $timedOut = $this->decidedJob((int)$template->id, $userId, (int)$rule->id, ApprovalRequest::STATUS_TIMED_OUT, null);
        $ran = $this->decidedJob((int)$template->id, $userId, (int)$rule->id, ApprovalRequest::STATUS_APPROVED, time());
        $placeholder = $this->decidedJob(null, $userId, (int)$rule->id, ApprovalRequest::STATUS_APPROVED, null);
        $rejected = $this->decidedJob((int)$template->id, $userId, (int)$rule->id, ApprovalRequest::STATUS_REJECTED, null);
        $workflowId = (int)$this->createWorkflowTemplate($userId)->id;
        $run = $this->createWorkflowJob($workflowId, $userId, WorkflowJob::STATUS_SUCCEEDED);
        $this->linkExecution($run, $this->createWorkflowStep($workflowId, 1, WorkflowStep::TYPE_JOB, (int)$template->id), $approved);

        $rows = $this->rowsByColumn(self::MARKED_SUCCEEDED, 'job_id', [$approved, $timedOut, $ran, $placeholder, $rejected]);

        $this->assertSame([$approved, $timedOut], array_keys($rows));
        $this->assertSame((int)$run->id, (int)$rows[$approved]['workflow_job_id']);
        $this->assertNull($rows[$timedOut]['workflow_job_id'], 'Its step record is gone.');
    }

    /**
     * The step IDs query $number lists for the workflow $workflowId, in its order.
     *
     * @return list<int>
     */
    private function stepIdsOf(int $number, int $workflowId): array
    {
        $ids = [];
        foreach ($this->rowsOf($number) as $row) {
            if ((int)$row['workflow_id'] === $workflowId) {
                $ids[] = (int)$row['step_id'];
            }
        }

        return $ids;
    }

    /**
     * A step of $workflowId with these branches; its ID.
     */
    private function routedStep(
        int $workflowId,
        int $order,
        string $type,
        ?int $jobTemplateId,
        ?int $ruleId,
        ?int $onFailure,
        ?int $onAlways
    ): int {
        $step = $this->createWorkflowStep($workflowId, $order, $type, $jobTemplateId, $ruleId);
        $step->on_failure_step_id = $onFailure;
        $step->on_always_step_id = $onAlways;
        $this->assertTrue($step->save(false));

        return (int)$step->id;
    }

    /**
     * An enabled schedule of template #$templateId that runs as $runsAs,
     * with one schedule.updated entry (schedule.toggled for the last) by
     * each of $changedBy (null: the system); its ID.
     *
     * @param list<int|null> $changedBy
     */
    private function scheduleChangedBy(int $templateId, int $runsAs, array $changedBy): int
    {
        $schedule = new Schedule();
        $schedule->name = 'docs-schedule-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $runsAs;
        $this->assertTrue($schedule->save(false));
        $last = count($changedBy) - 1;
        foreach ($changedBy as $index => $userId) {
            \Yii::$app->db->createCommand()->insert('{{%audit_log}}', [
                'action' => $index === $last ? AuditLog::ACTION_SCHEDULE_TOGGLED : AuditLog::ACTION_SCHEDULE_UPDATED,
                'object_type' => 'schedule',
                'object_id' => (int)$schedule->id,
                'user_id' => $userId,
                'created_at' => time(),
            ])->execute();
        }

        return (int)$schedule->id;
    }

    /**
     * $run's execution of $step, linked to job #$jobId.
     */
    private function linkExecution(WorkflowJob $run, WorkflowStep $step, int $jobId): void
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->snapshotStep($step);
        $execution->job_id = $jobId;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->started_at = time();
        $execution->created_at = time();
        $execution->updated_at = time();
        $this->assertTrue($execution->save(false));
    }

    /**
     * A job of template #$templateId (null: an approval step's placeholder)
     * whose approval request has $requestStatus, marked as 2.9.0 marked it
     * (succeeded, or rejected after a rejection), started at $startedAt
     * (null: it never ran); its ID.
     */
    private function decidedJob(?int $templateId, int $userId, int $ruleId, string $requestStatus, ?int $startedAt): int
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = $requestStatus === ApprovalRequest::STATUS_REJECTED ? Job::STATUS_REJECTED : Job::STATUS_SUCCEEDED;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->started_at = $startedAt;
        $job->finished_at = time();
        $job->created_at = time();
        $job->updated_at = time();
        $this->assertTrue($job->save(false));
        \Yii::$app->db->createCommand()->insert('{{%approval_request}}', [
            'job_id' => (int)$job->id,
            'approval_rule_id' => $ruleId,
            'status' => $requestStatus,
            'requested_at' => time() - 60,
            'resolved_at' => time(),
        ])->execute();

        return (int)$job->id;
    }
}
