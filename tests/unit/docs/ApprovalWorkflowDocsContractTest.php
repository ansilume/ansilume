<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\ApprovalController;
use app\components\DbErrorSummary;
use app\components\ErrorText;
use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\controllers\JobTemplateController;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApprovalService;
use app\services\ApprovalSettlement;
use app\services\ApproverEligibility;
use app\services\JobCancellationService;
use app\services\JobCompletionService;
use app\services\JobLaunchSecrets;
use app\services\JobLaunchService;
use app\services\JobTemplateDeletionService;
use app\services\MaintenanceService;
use app\services\ScheduleEnablement;
use app\services\ScheduleService;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowRunVars;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepDispatcher;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepRouter;
use app\services\WorkflowStepsTable;
use app\services\WorkflowTemplateDeletionService;
use app\tests\unit\services\WorkflowStepModels;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What the operator docs say about approvals and workflows is what the code
 * does: docs/updating.md's section for the update from 2.9.0 and
 * docs/troubleshooting.md quote the messages as the code words them, the
 * queries to run before the update use the values the code uses, and the
 * section's pointers lead to its own paragraphs.
 *
 * Regression from step 3 (approvals and workflows): the docs said approval
 * timeouts never apply on the stock deployments, that a canceled job's
 * request stays pending, that trigger extra vars reach the start step only
 * and that a workflow trigger answers 201 for a refused launch, and named
 * none of the new refusals. These tests fail on the docs from before.
 *
 * Regressions from the review of step 3, each test fails on the docs as
 * they were before it: the docs did not say that an approval step gates
 * only its own workflow, nor that a gated job step loses its Always branch
 * after a closed gate; they quoted no log line of a failing maintenance run
 * and did not say where those lines are; and they named neither the time
 * budget of a maintenance run nor the cancel reasons and queries that came
 * with the sweep of jobs that ended workflow runs left waiting.
 *
 * Since 2.11.0 the section is history where 2.11.0 reworded what it quotes:
 * the routed-to refusal, the error of a branch to a removed step and the
 * route labels of the steps table read as up to 2.10.1, and these tests
 * quote the same literals; troubleshooting.md quotes the new texts, and the
 * old error beside its new one, as runs keep what they stored
 * (WorkflowStepsUpdateDocsContractTest).
 */
class ApprovalWorkflowDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use WorkflowStepModels;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const MAINTENANCE_ENTRY = '## Approval timeouts or the deleted-template sweep keep failing';

    /** The error the docs show in the log lines they quote: a deadlock. */
    private const DEADLOCK = ['yii\db\Exception', '40001', '1213'];

    /** The approval step's sentence of security-2 (review of step 3). */
    private const STEP_GATES_ITS_RUNS = 'An approval step gates the runs of its workflow, not the job templates in it';

    /** WorkflowStepChangeGuard::STEP_ROUTED_TO as 2.9.0 to 2.10.1 worded it, which the section quotes. */
    private const ROUTED_TO_2101 = 'Step "Build" was not removed: other steps route to it (#10 "Retry"). Remove those steps '
        . 'first, and add them again with other routes if you still need them.';

    /** WorkflowStepRouter::ROUTE_TARGET_MISSING before 2.11.0; steps that ran then keep it. */
    private const ROUTE_TARGET_MISSING_2101 = 'The next step #7 of this workflow no longer exists.';

    /** The label of an approval step's empty On Failure branch up to 2.10.1. */
    private const END_FAILED_2101 = 'end workflow (failed)';

    /** The label of a gated job step's empty On Failure branch up to 2.10.1. */
    private const GATED_JOB_FAILURE_2101 = '→ next step; end workflow (failed) after a refused approval';

    /**
     * The section follows those of the releases after it (the update that
     * gives inventories approval rules, the newest, the one that leaves
     * runner management to admins, 2.11.0 and the patch 2.10.1), and the
     * one before it keeps its heading, which the contract tests of 2.9.0
     * read.
     */
    public function testTheSectionFollowsThePatchAndTheOlderOnesStay(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read('docs/updating.md'), $headings);
        $this->assertSame(InventoryApprovalDocsContractTest::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(RunnerManagementDocsContractTest::UPDATE_SECTION, $headings[0][1] ?? null, 'The section of 2.11.1 follows.');
        $this->assertSame(WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION, $headings[0][2] ?? null, 'The section of 2.11.0 follows.');
        $this->assertSame(WorkflowStepOrderDocsContractTest::UPDATE_SECTION, $headings[0][3] ?? null, 'The patch 2.10.1 follows.');
        $this->assertSame(self::UPDATE_SECTION, $headings[0][4] ?? null, 'This section comes next.');
        $this->assertStringStartsWith('## After updating from 2.8.1 or older: encrypted password answers', $headings[0][5] ?? '');
    }

    public function testTheChecklistStartsWithTheChecksBeforeTheUpdate(): void
    {
        $section = $this->section('docs/updating.md', self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/^1\. (.*?)\R(?:\S|\R)/ms', $section, $first));
        $this->assertStringStartsWith(
            'Before the update, run the read-only queries under **Check before the update**',
            (string)preg_replace('/\s+/', ' ', $first[1] ?? '')
        );
        $this->assertMentions('**Check before the update.**', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
    }

    /**
     * Every "(see **...**)" of the section leads to a paragraph of it.
     */
    public function testEveryPointerOfTheSectionLeadsToOneOfItsParagraphs(): void
    {
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        preg_match_all('/see \*\*([^*]+)\*\*/', $section, $pointers);
        $this->assertGreaterThan(10, count($pointers[1]));
        foreach (array_unique($pointers[1]) as $title) {
            $this->assertTrue(
                str_contains($section, '**' . $title . '.**') || str_contains($section, '**' . $title . ':**'),
                "updating.md points to **{$title}**, which the section does not have."
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function messageProvider(): array
    {
        $rule = SurveyDefinition::quote('deploy-prod');
        $step = SurveyDefinition::quote('Release approval');
        $both = ['updating', 'troubleshooting'];

        $messages = [
            'the launcher votes' => [ApprovalDecisionRefusedException::LAUNCHER_REFUSED, $both],
            'not eligible' => [ApprovalDecisionRefusedException::NOT_ELIGIBLE, $both],
            'already voted' => [ApprovalDecisionRefusedException::ALREADY_VOTED, $both],
            'resolved' => [sprintf(ApprovalDecisionRefusedException::RESOLVED, 12, 'approved'), $both],
            'expired' => [sprintf(ApprovalDecisionRefusedException::EXPIRED, 12), $both],
            'job no longer waits' => [sprintf(ApprovalDecisionRefusedException::JOB_NOT_WAITING, 12, 34), $both],
            'comment too long' => [sprintf(ApprovalDecisionRefusedException::INVALID_DECISION, 'Comment should contain at most 1,000 characters.'), $both],
            'comment no string' => [sprintf(ApprovalDecisionRefusedException::INVALID_DECISION, ApprovalService::COMMENT_NOT_A_STRING), $both],
            'request gone' => [sprintf(ApprovalDecisionRefusedException::GONE, 12), ['troubleshooting']],
            'too few approvers' => [sprintf(ApproverEligibility::REFUSAL_JOB, $rule, 1, 0), $both],
            'workflow: too few approvers' => [sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, $step, $rule, 1, 0), $both],
            'workflow: rule missing' => [sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULE_MISSING, $step), $both],
            'step: too few approvers' => [sprintf(ApproverEligibility::REFUSAL_WORKFLOW_STEP, $rule, 1, 7, 0), $both],
            'template rule missing' => [sprintf(JobLaunchService::RULE_MISSING, 7), $both],
            'template deleted' => [sprintf(JobLaunchService::TEMPLATE_DELETED, 7), ['troubleshooting']],
            'template rule changed' => [sprintf(JobLaunchService::TEMPLATE_CHANGED, 7), $both],
            // 2.12.0: the refusals of a job template gated through its inventory
            // (the section of 2.12.0 quotes them, InventoryApprovalDocsContractTest).
            'inventory rule missing' => [sprintf(JobLaunchService::INVENTORY_RULE_MISSING, 7, 3), ['troubleshooting']],
            'two rules meet' => [sprintf(JobLaunchService::RULES_DIFFER, 7, 3), ['troubleshooting']],
            'template inventory changed' => [sprintf(JobLaunchService::TEMPLATE_INVENTORY_CHANGED, 7), ['troubleshooting']],
            'inventory rule changed' => [sprintf(JobLaunchService::INVENTORY_CHANGED, 7, 3), ['troubleshooting']],
            'inventory missing' => [sprintf(JobLaunchService::INVENTORY_MISSING, 7, 3), ['troubleshooting']],
            'workflow: two rules meet' => [sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, SurveyDefinition::quote('Deploy')), ['troubleshooting']],
            'assigning a rule' => [ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, ['updating']],
            'adding an approval step' => [ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $both],
            'steps of an approval workflow' => [ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $both],
            'runs active' => [WorkflowStepChangeGuard::runsActiveMessage([12]), $both],
            // 2.11.0 names the steps by position and advises to edit them;
            // the section quotes the refusal as it read up to 2.10.1.
            'routed to, up to 2.10.1' => [self::ROUTED_TO_2101, $both],
            'routed to' => [
                sprintf(
                    WorkflowStepChangeGuard::STEP_ROUTED_TO,
                    SurveyDefinition::quote('Build'),
                    sprintf(WorkflowStepChangeGuard::ROUTING_STEP_LABEL, 3, SurveyDefinition::quote('Retry'))
                ),
                ['troubleshooting'],
            ],
            'step: no rule' => [WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::APPROVAL_RULE_REQUIRED, ['troubleshooting']],
            'step: unknown rule' => [WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::APPROVAL_RULE_NOT_FOUND, ['troubleshooting']],
            'step: always' => [WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::APPROVAL_ALWAYS_REFUSED, ['troubleshooting']],
            'step: other workflow' => [WorkflowTemplateController::FLASH_STEP_NOT_ADDED . WorkflowStep::ROUTE_TARGET_OUTSIDE, ['troubleshooting']],
            'always refused' => [WorkflowStep::APPROVAL_ALWAYS_REFUSED, ['updating']],
            'gate: rejected' => [sprintf(WorkflowStepRouter::APPROVAL_REJECTED, 12), $both],
            'gate: timed out' => [sprintf(WorkflowStepRouter::APPROVAL_TIMED_OUT, 12), ['updating']],
            'gate: canceled' => [sprintf(WorkflowStepRouter::APPROVAL_CANCELED, 12), ['updating']],
            'gate of a job step' => [sprintf(WorkflowStepRouter::JOB_APPROVAL_REJECTED, 12), $both],
            // Steps that ran before 2.11.0 keep the error they stored.
            'route to a removed step, before 2.11.0' => [self::ROUTE_TARGET_MISSING_2101, $both],
            'route to a removed step' => [sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, 7), ['troubleshooting']],
            'step removed while it ran' => [WorkflowExecutionService::STEP_REMOVED, ['troubleshooting']],
            'request not created' => [WorkflowStepDispatcher::REQUEST_FAILED, ['troubleshooting']],
            'rule of a step gone' => [WorkflowStepDispatcher::RULE_GONE, $both],
            'job of a step not launched' => [WorkflowStepDispatcher::LAUNCH_FAILED, $both],
            'run canceled' => [WorkflowCancellationService::STEP_RUN_CANCELED, ['troubleshooting']],
            'run of a deleted workflow' => [WorkflowCancellationService::STEP_TEMPLATE_DELETED, $both],
            'extra vars no object' => [WorkflowRunVars::NOT_AN_OBJECT, $both],
            'extra vars too large' => [sprintf(WorkflowRunVars::TOO_LARGE, WorkflowRunVars::MAX_EXTRA_VARS_BYTES), $both],
            'placeholder' => [sprintf(JobLaunchSecrets::PLACEHOLDER_SENT, SurveyDefinition::quote('db_password'), SurveySecrets::PLACEHOLDER), $both],
            'no steps' => [sprintf(WorkflowExecutionService::NO_STEPS, 5), $both],
            'workflow deleted' => [sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, 5), $both],
            'schedule not enabled' => [sprintf(ScheduleEnablement::ENABLE_REFUSED, 'Nightly deploy', 15), $both],
            'schedule disabled' => [sprintf(ScheduleService::TEMPLATE_DELETED_DISABLED, 15), $both],
            'job not canceled' => [sprintf(JobCancellationService::NOT_CANCELED, 42, 'succeeded'), $both],
            'template deleted, counts' => [sprintf(JobTemplateController::FLASH_DELETED, 'Deploy web', 2, 1), ['updating']],
            'workflow deleted, runs' => [sprintf(WorkflowTemplateController::FLASH_DELETED_RUNS_CANCELED, 'Release web', 1), ['updating']],
            'workflow deleted, failures' => [sprintf(WorkflowTemplateController::FLASH_DELETED_RUNS_FAILED, 'Release web', 1, 1), $both],
            'late completion' => [sprintf(JobCompletionService::LATE_COMPLETION, 0, 'canceled'), $both],
            'late timeout' => [sprintf(JobCompletionService::LATE_TIMEOUT, 'canceled'), $both],
            'effects of a vote failed' => [self::upTo(ApprovalService::VOTE_EFFECTS_FAILED, 12, 7), $both],
            'timeouts during an update' => [str_replace('%d', 'N', ApprovalController::MIGRATIONS_PENDING), ['updating']],
            // Review of step 3 (approvals-5, contract-3): the two failures of a timeout are told apart.
            'a timeout failed' => [self::upTo(ApprovalService::TIMEOUT_FAILED, 12), ['updating']],
            'the effects of a timeout failed' => [self::upTo(ApprovalService::TIMEOUT_EFFECTS_FAILED, 12), ['updating']],
            'a cancel of a run failed' => [self::upTo(WorkflowTemplateDeletionService::CANCEL_FAILED, 12, 5), ['troubleshooting']],
            // Review of step 3 (workflows-1 to -3, security-3): what a step that could not start or end says.
            'the log of a request not created' => [self::upTo(WorkflowStepDispatcher::REQUEST_FAILED_LOG, 12, 31), ['troubleshooting']],
            'the log of a job not launched' => [self::upTo(WorkflowStepDispatcher::LAUNCH_FAILED_LOG, 12, 15, 31), ['troubleshooting']],
            'the effects of a launch failed' => [self::upTo(JobLaunchService::EFFECTS_FAILED, 42), ['updating']],
            'the announcement of a step request failed' => [self::upTo(WorkflowStepDispatcher::REQUEST_ANNOUNCE_FAILED_LOG, 12, 34, 31), ['updating']],
            'gate: request gone' => [WorkflowStepRouter::APPROVAL_MISSING, $both],
            'gate of a job step: request gone' => [WorkflowStepRouter::JOB_APPROVAL_MISSING, $both],
            'step of an ended run' => [StrandedJobSweep::STEP_RUN_ENDED, $both],
        ];
        foreach (self::maintenanceLogLines() as $what => $line) {
            $messages['logged: ' . $what] = [$line, ['troubleshooting']];
        }

        return $messages;
    }

    /**
     * @dataProvider messageProvider
     * @param list<string> $docs
     */
    public function testTheMessagesAreQuotedAsTheCodeWordsThem(string $message, array $docs): void
    {
        $texts = [
            'updating' => $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'troubleshooting' => $this->flat('docs/troubleshooting.md'),
        ];
        foreach ($docs as $doc) {
            // Quoted as code, or in bold quotes as in the lists of messages.
            $this->assertTrue(
                str_contains($texts[$doc], '`' . $message . '`') || str_contains($texts[$doc], '**"' . $message . '"**'),
                "{$doc}.md does not quote: {$message}"
            );
        }
    }

    /**
     * An approver config that is no JSON object is refused by the model;
     * the API contract change quotes the refusal as the model words it
     * (review of step 3, contract-2).
     */
    public function testTheRefusalOfAnApproverConfigThatIsNoObjectIsQuoted(): void
    {
        $label = (new ApprovalRule())->getAttributeLabel('approver_config');
        $this->assertMentions(
            '`' . sprintf(ApprovalRule::NOT_A_JSON_OBJECT, $label) . '`',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'updating.md'
        );
        $this->assertMentions('`{}` for a rule without approvers', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
    }

    /**
     * The queries to run before the update use the values the code uses.
     */
    public function testTheQueriesBeforeTheUpdateUseTheCodesValues(): void
    {
        $sql = (string)preg_replace('/\s+/', ' ', implode("\n", $this->queries()));

        $this->assertStringContainsString('u.status = ' . User::STATUS_ACTIVE, $sql, 'Active users.');
        $this->assertStringContainsString("<> '" . Job::STATUS_PENDING_APPROVAL . "'", $sql, 'Jobs that no longer wait for approval.');
        $this->assertStringContainsString('<= ' . ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS, $sql, 'The grace period of an approve-timeout.');
        $waiting = "j.status IN ('" . implode("', '", Job::waitingStatuses()) . "')";
        $this->assertStringContainsString($waiting, $sql, 'The jobs a delete cancels.');
        $this->assertSame([WorkflowJob::STATUS_RUNNING], WorkflowJob::activeStatuses());
        $this->assertStringContainsString("r.status = '" . WorkflowJob::STATUS_RUNNING . "'", $sql, 'The runs that lock the steps.');
        foreach ([WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_JOB] as $type) {
            $this->assertStringContainsString("CAST(s.step_type AS BINARY) = '{$type}'", $sql, 'Types compared exactly, as runs do.');
        }
        foreach (['approval-rule.create', 'approval-rule.update'] as $permission) {
            $this->assertStringContainsString("'{$permission}'", $sql);
        }
        $this->assertStringContainsString("j.status = '" . Job::STATUS_PENDING_APPROVAL . "'", $sql, 'Jobs that wait for approval.');
        $this->assertStringContainsString(
            "r.status IN ('" . ApprovalRequest::STATUS_APPROVED . "', '" . ApprovalRequest::STATUS_TIMED_OUT . "')",
            $sql,
            'The decisions that marked a gated workflow job step succeeded.'
        );
        $this->assertStringContainsString("j.status = '" . Job::STATUS_SUCCEEDED . "'", $sql);
    }

    /**
     * Regression (review of step 3, operations gaps 1 to 3 and the MySQL 8
     * gap): ten queries before the update, among them the approval steps
     * without a rule and the jobs that wait for approval without a request,
     * and one for the history: the jobs of gated workflow job steps that an
     * approval marked succeeded without running them. The third reads an
     * approver list that is no valid JSON as {}, where MySQL 8 stops with an
     * error (MariaDB only warns).
     *
     * Regression (last review round of step 3): an eleventh before the
     * update, for the rules whose approver list is no JSON object, and one
     * for the schedules of gated job templates that other users changed,
     * which the update leaves running as they ran.
     *
     * Regression (wave 8 of step 3, decision 5): one for the job steps of
     * workflows whose job template has an approval rule, and one for the
     * changes to gated job templates and to what they use
     * (GatedTemplateBehaviourDocsContractTest).
     */
    public function testTheQueriesCoverWhatTheUpdateChangesAndTheHistoryItLeft(): void
    {
        $this->assertCount(
            15,
            $this->queries(),
            'Eleven queries before the update, one for schedules others changed, two for gated job templates, one for the history.'
        );
        $sql = (string)preg_replace('/\s+/', ' ', implode("\n", $this->queries()));
        $this->assertStringContainsString("IF(JSON_VALID(x.approver_config), x.approver_config, '{}') AS config", $sql);
        $this->assertStringNotContainsString('JSON_EXTRACT(r.approver_config', $sql, 'Every approver list is read through the guard.');
        $this->assertStringContainsString('AND (s.on_failure_step_id IS NULL OR s.on_always_step_id IS NOT NULL)', $sql, 'The fifth, for both kinds of gates.');
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $leads = [
            'The ninth lists the approval steps without an approval rule',
            'The tenth lists the jobs that wait for approval without an approval request',
            'The eleventh lists the approval rules whose approver list an older version stored as a JSON list or a single value',
        ];
        foreach ($leads as $lead) {
            $this->assertMentions($lead, $section, 'updating.md');
        }
    }

    /**
     * The two maintenance tasks are named wherever the docs list what the
     * schedule-runner does.
     */
    public function testTheMaintenanceTasksAreNamed(): void
    {
        $artifacts = $this->flat('docs/artifacts.md');
        foreach ([MaintenanceService::TASK_APPROVAL_TIMEOUTS, MaintenanceService::TASK_DELETED_TEMPLATE_SWEEP] as $task) {
            $this->assertMentions('`' . $task . '`', $artifacts, 'artifacts.md');
            $this->assertMentions('[maintenance] ran: ' . $task . ' (', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
        }
        $this->assertMentions('approval timeouts', $this->flat('docs/deployment.md'), 'deployment.md, on the schedule-runner,');
        $this->assertMentions('php yii approval/check-timeouts', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
    }

    /**
     * Regression (review of step 3, operations-1): one maintenance run
     * stops starting timeouts and cancels once its time budget is used up;
     * the docs name the budget the code has.
     */
    public function testTheTimeBudgetOfAMaintenanceRunIsTheCodes(): void
    {
        $budget = (new MaintenanceService())->jobTaskBudgetSeconds;
        $this->assertGreaterThan(0, $budget);
        $phrase = sprintf('time budget of %s seconds per run', (string)(int)$budget);
        $this->assertSame((float)(int)$budget, $budget, 'A budget of whole seconds, as the docs say.');
        $this->assertMentions($phrase, $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
        $this->assertMentions($phrase, $this->flat('docs/artifacts.md'), 'artifacts.md');
    }

    /**
     * Regression (review of step 3, contract-3): the failures of the
     * maintenance run are written to the schedule-runner's application log,
     * which `docker compose logs` does not show, and no doc said where. The
     * troubleshooting entry says it, and its commands find every line it
     * quotes: the schedule-runner's all of them, the app container's those
     * of a delete or a cancel in the web UI or the API.
     */
    public function testTheMaintenanceFailuresAreFoundWhereTheDocsSay(): void
    {
        $entry = $this->section('docs/troubleshooting.md', self::MAINTENANCE_ENTRY);
        $flat = $this->flat('docs/troubleshooting.md', self::MAINTENANCE_ENTRY);
        $this->assertMentions('`runtime/logs/app.log`', $flat, 'The entry, for a git checkout,');
        $this->assertMentions('`docker compose logs schedule-runner` does not say why', $flat, 'The entry');

        $schedule = self::grepPatterns($entry, 'schedule-runner');
        $app = self::grepPatterns($entry, 'app');
        foreach (self::maintenanceLogLines() as $what => $line) {
            $this->assertTrue(self::matchesOne($line, $schedule), "The schedule-runner's grep finds the line of {$what}: {$line}");
        }
        foreach (['a job cancel failed', 'a run cancel failed', 'a step cancel failed'] as $what) {
            $line = self::maintenanceLogLines()[$what];
            $this->assertTrue(self::matchesOne($line, $app), "The app container's grep finds the line of {$what}: {$line}");
        }
    }

    /**
     * Regression (review of step 3, approvals-2 and workflows-1): the reasons
     * of the new cancels are named where the docs list the reasons of the
     * audit entries.
     */
    public function testTheReasonsOfTheNewCancelsAreNamed(): void
    {
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $troubleshooting = $this->flat('docs/troubleshooting.md');
        foreach ([ApprovalSettlement::REASON_WORKFLOW_STEP_ENDED, JobCancellationService::REASON_APPROVAL_MISSING] as $reason) {
            $this->assertMentions('`reason: ' . $reason . '`', $updating, 'updating.md');
            $this->assertMentions('`' . $reason . '`', $troubleshooting, 'troubleshooting.md');
        }
        $this->assertMentions('`stranded_jobs_canceled`', $updating, 'updating.md, on the sweep of stranded jobs,');
        $this->assertSame(5 * 60, StrandedJobSweep::ORPHAN_MIN_AGE_SECONDS, 'The docs say five minutes.');
        $this->assertMentions('jobs that have waited for approval for five minutes or more without an approval request', $updating, 'updating.md');
        $this->assertMentions('waited for approval for five minutes or more without an approval request', $troubleshooting, 'troubleshooting.md');
    }

    /**
     * An approval rule gates a job template; the docs say what it does not
     * stop, without a recipe. The section of 2.10.0 keeps its wording; since
     * 2.12.0 a rule gates inventories too, so README.md and the spec's tag
     * say that a rule gates job templates and inventories, not hosts or
     * playbooks (InventoryApprovalDocsContractTest).
     *
     * Regression (review of step 3, security-2): the docs never said that an
     * approval step gates the runs of its own workflow only, so an admin who
     * put one in front of a deploy step believed it protected the deploy.
     * Nor that a runner reads the playbook and the inventory after the
     * approval.
     */
    public function testTheTwinTemplateLimitIsDescribed(): void
    {
        $this->assertMentions('**What an approval rule does not stop.** An approval rule gates a job template, not a playbook or an inventory.', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
        $this->assertMentions('A rule gates job templates and inventories, not hosts or playbooks', $this->flat('README.md'), 'README.md');
        $tag = $this->approvalRulesTag();
        $this->assertStringContainsString('A rule gates job templates and inventories, not hosts or playbooks', $tag);

        $limits = $this->paragraph('**What an approval rule does not stop.**');
        $this->assertMentions(self::STEP_GATES_ITS_RUNS . ':', $limits, 'updating.md, on what a rule does not stop,');
        $this->assertMentions('To gate a job template wherever it runs, give the template an approval rule.', $limits, 'updating.md');
        $this->assertMentions('when it claims the job, after the approval', $limits, 'updating.md, on what an approved job reads later,');
        $this->assertMentions(self::STEP_GATES_ITS_RUNS, $this->paragraph('**Approval steps are for admins.**'), 'updating.md, on approval steps,');
        $this->assertMentions(self::STEP_GATES_ITS_RUNS, $this->flat('README.md'), 'README.md');
        $this->assertStringContainsString(self::STEP_GATES_ITS_RUNS, $tag);
    }

    /**
     * Regression (review of step 3, contract-1): after a closed gate, a job
     * step whose job template has an approval rule no longer takes its
     * Always branch either; the docs named only the approval step's. The
     * steps table labels such a step's empty On Failure branch as an end.
     *
     * The labels of the empty On Failure branches read as up to 2.10.1 (the
     * section of 2.11.0 maps them to the labels of today); the notes of the
     * Always column kept their wording, and the steps table shows them.
     */
    public function testTheClosedGateParagraphCoversTheAlwaysBranchOfGatedJobSteps(): void
    {
        $gate = $this->paragraph('**A closed approval gate ends the run as failed.**');
        $this->assertMentions('the Always branch of an approval step, or of such a job step, was followed after a rejection, a timeout or a cancel', $gate, 'updating.md');
        $this->assertMentions('the Always branch is not taken (a job step still takes it after a job that ran)', $gate, 'updating.md');
        $admins = $this->paragraph('**Approval steps are for admins.**');
        $this->assertMentions('`' . self::END_FAILED_2101 . '` for an approval step without an On Failure branch', $admins, 'updating.md');
        // Last review round of step 3 (open need 1): a gated job step's empty
        // On Failure branch names both of its cases.
        $this->assertMentions(
            '`' . self::GATED_JOB_FAILURE_2101 . '` for a job step whose job template has an approval rule and no On Failure branch',
            $admins,
            'updating.md'
        );
        // The notes as the steps table shows them (the presenter, not a view,
        // has the labels since 2.11.0).
        $approval = self::approval(1, 'Release approval', ['always' => 2]);
        $gated = self::job(2, 'Deploy', ['always' => 0], 'deploy-prod');
        $steps = [$approval, $gated];
        $notes = array_map(
            static fn (array $row): ?string => $row['routes']['always']['note'],
            (new WorkflowStepsTable(WorkflowRouteMap::fromModels($steps), $steps, false, []))->rows()
        );
        $this->assertSame([WorkflowStepsTable::APPROVAL_ALWAYS_NOTE, WorkflowStepsTable::GATED_JOB_ALWAYS_NOTE], $notes);
        foreach (array_merge([self::END_FAILED_2101, self::GATED_JOB_FAILURE_2101], $notes) as $label) {
            $this->assertMentions('`' . $label . '`', $admins . ' ' . $this->paragraph('**A closed approval gate ends the run as failed.**'), 'updating.md');
        }
        $this->assertMentions(
            '5. Give approval steps, and job steps whose job template has an approval rule, an On Failure branch',
            $this->flat('docs/updating.md', self::UPDATE_SECTION),
            'The checklist of updating.md'
        );
    }

    /**
     * CLAUDE.md names the job statuses the code uses (the map of step 3
     * found it naming waiting, successful and error).
     */
    public function testClaudeMdNamesTheJobStatusesOfTheCode(): void
    {
        $this->assertMentions(
            'Job statuses: `' . implode('`, `', Job::statuses()) . '`',
            $this->flat('CLAUDE.md'),
            'CLAUDE.md'
        );
        foreach (['Approval Rule', 'Approval Request', 'Workflow Template', 'Workflow Job'] as $term) {
            $this->assertMentions('**' . $term . '**', $this->flat('CLAUDE.md'), 'The domain language of CLAUDE.md');
        }
        // Review of step 3 (security-3): how database errors are logged, a
        // rule for all code since the last review round (substantive 3).
        $this->assertTrue(class_exists(DbErrorSummary::class) && class_exists(ErrorText::class), 'The classes CLAUDE.md names exist.');
        $claude = $this->flat('CLAUDE.md');
        $this->assertMentions('Rule for all code, old and new: a database error (`yii\db\Exception`', $claude, 'CLAUDE.md');
        $this->assertMentions(
            'is logged with its class, SQLSTATE and driver error code only (`' . DbErrorSummary::class . '`), never with its message',
            $claude,
            'CLAUDE.md'
        );
        $this->assertMentions('`' . ErrorText::class . '` gives the message of any other error and the summary of a database error', $claude, 'CLAUDE.md');
        $this->assertMentions('User-facing and logged messages live in class constants', $claude, 'CLAUDE.md');
    }

    /**
     * The log lines of a failing maintenance run, and of a delete or cancel
     * whose cancel of a job, run or step failed, filled as an operator finds
     * them in the log.
     *
     * @return array<string, string>
     */
    private static function maintenanceLogLines(): array
    {
        return [
            'a timeout failed' => self::logged(ApprovalService::TIMEOUT_FAILED, [12]),
            'the effects of a timeout failed' => self::logged(ApprovalService::TIMEOUT_EFFECTS_FAILED, [12], ['yii\db\Exception', 'HY000', '2006']),
            'the timeouts stopped' => sprintf(MaintenanceService::TASK_FAILED, 'applying approval timeouts', 'yii\db\Exception'),
            'the sweep stopped' => sprintf(MaintenanceService::TASK_FAILED, 'the sweep of deleted job and workflow templates', 'yii\db\Exception'),
            'a job cancel failed' => self::logged(JobTemplateDeletionService::CANCEL_FAILED, [42, 15]),
            'a run cancel failed' => self::logged(WorkflowTemplateDeletionService::CANCEL_FAILED, [12, 5]),
            'a step cancel failed' => self::logged(WorkflowCancellationService::STEP_CANCEL_FAILED, [12, 31]),
            'a stranded job not canceled' => self::logged(StrandedJobSweep::JOB_FAILED, [42]),
            'a step of an ended run not ended' => self::logged(StrandedJobSweep::STEP_FAILED, [31, 12]),
        ];
    }

    /**
     * $format with its %d filled with $ids and its %s with the parts of
     * $error (class, SQLSTATE, driver error code), each in order, as many as
     * the format has.
     *
     * @param list<int> $ids
     * @param list<string> $error
     */
    private static function logged(string $format, array $ids, array $error = self::DEADLOCK): string
    {
        $filled = preg_replace_callback(
            '/%[ds]/',
            static function (array $match) use (&$ids, &$error): string {
                $value = $match[0] === '%d' ? array_shift($ids) : array_shift($error);
                self::assertNotNull($value, 'The format has more placeholders than the docs fill.');

                return (string)$value;
            },
            $format
        );
        self::assertIsString($filled);

        return $filled;
    }

    /**
     * $format up to its first %s, filled with $values, and "..." for the
     * rest, as the docs show a log line whose end varies.
     */
    private static function upTo(string $format, int ...$values): string
    {
        $end = strpos($format, '%s');
        self::assertIsInt($end, 'The format has a %s.');

        return sprintf(substr($format, 0, $end), ...$values) . '...';
    }

    /**
     * The `-e` patterns of the grep in `$container` that $entry shows.
     *
     * @return list<string>
     */
    private static function grepPatterns(string $entry, string $container): array
    {
        $line = '/^docker compose exec ' . preg_quote($container, '/') . ' grep (.+) \/var\/www\/runtime\/logs\/app\.log$/m';
        self::assertSame(1, preg_match($line, $entry, $grep), "The entry shows the grep in the {$container} container.");
        preg_match_all("/-e '([^']+)'/", $grep[1] ?? '', $patterns);
        self::assertNotEmpty($patterns[1]);

        return $patterns[1];
    }

    /**
     * @param list<string> $patterns fixed strings, as grep -e takes them here
     */
    private static function matchesOne(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($line, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paragraph of the section that starts with the bold title $title.
     */
    private function paragraph(string $title): string
    {
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $title)) {
                return $unit;
            }
        }
        $this->fail("updating.md has no paragraph {$title}");
    }

    /**
     * The ```sql blocks of the section.
     *
     * @return list<string>
     */
    private function queries(): array
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', self::UPDATE_SECTION), $blocks);

        return $blocks[1];
    }

    private function approvalRulesTag(): string
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $tags = array_column(is_array($spec['tags'] ?? null) ? $spec['tags'] : [], 'description', 'name');

        return (string)preg_replace('/\s+/', ' ', (string)($tags['Approval Rules'] ?? ''));
    }
}
