<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ApprovalRuleUsage;
use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\controllers\api\v1\ApprovalRulesController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApprovalRuleWriteService;
use app\services\ApprovalService;
use app\services\ApprovalSettlement;
use app\services\ApproverEligibility;
use app\services\JobCancellationService;
use app\services\JobLaunchSecrets;
use app\services\JobLaunchService;
use app\services\ScheduleEnablement;
use app\services\ScheduleService;
use app\services\StrandedJobSweep;
use app\services\WorkflowCancellationService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowRunVars;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepDispatcher;
use app\services\WorkflowStepRouter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What web/openapi.yaml says about approvals, approval rules, the deletes of
 * job and workflow templates, and workflow launches, steps and runs is what
 * the code answers, word for word where the spec quotes a message: the
 * messages come from the code's constants and from its validation, the
 * status codes from the code that answers them.
 *
 * Regressions from step 3 (approvals and workflows), each test fails on the
 * spec as it was before:
 * - the votes on approval requests answered 403 for a resolved request and
 *   nothing documented 409, the launcher's refusal or the status canceled;
 * - the spec said a timeout never applies on the stock deployments, and
 *   that a canceled job's request stays pending;
 * - approval rules had no schema, no 409 for a rule in use and no 422 for
 *   job_template_id; the operators' 403 on rule writes was undocumented;
 * - the launch refusals of approval rules, the template deletes, the step
 *   locks and the workflow launch refusals (fire-workflow answered 201 and
 *   its run failed later) were missing;
 * - workflow routing said an empty On Failure branch always goes on with
 *   the next step, and trigger extra vars reached the start step only.
 *
 * Regressions from the review of step 3, each fails on the spec as it was
 * before it: the Always branch of a gated job step after a closed gate, the
 * answer order of the workflow template writes, what failed cancels leave,
 * the timeout grace and average decision time, approver_config as an
 * object, and the step errors of the maintenance run's sweep.
 *
 * Regressions from 2.11.0, each fails on the spec of 2.10.1: a branch to a
 * removed step names its step ID ("step ID 7"), no longer a "#7" that read
 * like a run; the user who started a workflow run is refused with
 * LAUNCHER_REFUSED_RUN, which the spec did not quote.
 *
 * From 2.12.0 (approval rules on inventories), each fails on the spec of
 * 2.11.1: the launch refusals of an inventory's rule and of one rule per
 * launch, the workflow pre-flight's REFUSAL_WORKFLOW_RULES_DIFFER, the
 * refusals of job steps gated through their job template's inventory, and
 * the inventories of an approval rule's usage were not quoted.
 */
class OpenApiApprovalWorkflowContractTest extends TestCase
{
    private const SCHEMAS = ['components', 'schemas'];
    private const RESPONSES = ['components', 'responses'];

    /** @var array<mixed>|null */
    private ?array $spec = null;

    public function testTheApprovalStatusesAreTheOnesTheModelsKnow(): void
    {
        $this->assertSame(ApprovalRequest::statuses(), $this->at([...self::SCHEMAS, 'ApprovalRequest', 'properties', 'status', 'enum']));
        $this->assertSame(
            ApprovalRequest::statuses(),
            $this->at([...self::SCHEMAS, 'ApprovalDecisionConflictError', 'properties', 'error', 'properties', 'status', 'enum'])
        );
        $this->assertSame(Job::statuses(), $this->at([...self::SCHEMAS, 'JobCancelConflictError', 'properties', 'error', 'properties', 'status', 'enum']));

        $list = $this->textAt(['paths', '/api/v1/approvals', 'get', 'description']);
        foreach (ApprovalRequest::statuses() as $status) {
            $this->assertStringContainsString(strtolower(ApprovalRequest::statusLabel($status)), $list, "The list names the status {$status}.");
        }
    }

    /**
     * Every refusal of a vote is documented under the status code the API
     * answers it with, with the message and request status the code gives.
     */
    public function testTheRefusedVotesAnswerAsTheSpecSays(): void
    {
        $request = new ApprovalRequest();
        $request->id = 12;
        $request->job_id = 34;
        $responses = ['403' => 'ApprovalDecisionForbidden', '409' => 'ApprovalDecisionConflict', '422' => 'ApprovalDecisionRejected'];
        foreach (['/api/v1/approvals/{id}/approve', '/api/v1/approvals/{id}/reject'] as $path) {
            foreach ($responses as $code => $response) {
                $this->assertSame("#/components/responses/{$response}", $this->at(['paths', $path, 'post', 'responses', (string)$code, '$ref']), "{$path} {$code}");
            }
        }

        $refusals = [
            [ApprovalDecisionRefusedException::launcher($this->withStatus($request, ApprovalRequest::STATUS_PENDING)), null],
            // Regression (2.11.0): the user who started a workflow run reads
            // LAUNCHER_REFUSED_RUN for the job of its step, which the spec
            // did not quote.
            [ApprovalDecisionRefusedException::launcher($this->withStatus($request, ApprovalRequest::STATUS_PENDING), true), null],
            [ApprovalDecisionRefusedException::notEligible($this->withStatus($request, ApprovalRequest::STATUS_PENDING)), null],
            [ApprovalDecisionRefusedException::resolved($this->withStatus($request, ApprovalRequest::STATUS_APPROVED)), ApprovalRequest::STATUS_APPROVED],
            [ApprovalDecisionRefusedException::expired($this->withStatus($request, ApprovalRequest::STATUS_PENDING)), ApprovalRequest::STATUS_PENDING],
            // The vote closes the request before it answers.
            [ApprovalDecisionRefusedException::jobNotWaiting($this->withStatus($request, ApprovalRequest::STATUS_CANCELED)), ApprovalRequest::STATUS_CANCELED],
            [ApprovalDecisionRefusedException::alreadyVoted($this->withStatus($request, ApprovalRequest::STATUS_PENDING)), null],
            [ApprovalDecisionRefusedException::invalid($request, $this->commentTooLong()), null],
            [ApprovalDecisionRefusedException::invalid($request, ApprovalService::COMMENT_NOT_A_STRING), null],
        ];
        foreach ($refusals as [$refusal, $status]) {
            $response = $responses[(string)$refusal->httpStatus()] ?? null;
            $this->assertIsString($response, 'Status ' . $refusal->httpStatus() . ' is documented for votes.');
            $examples = $this->examples($response);
            $this->assertContains($refusal->getMessage(), array_column($examples, 'message'), "{$response} quotes it.");
            if ($status !== null) {
                $this->assertContains(['message' => $refusal->getMessage(), 'status' => $status], $examples, "{$response} names the request's status.");
            }
        }
        $this->assertContains('Forbidden.', array_column($this->examples('ApprovalDecisionForbidden'), 'message'));
        $approve = $this->textAt(['paths', '/api/v1/approvals/{id}/approve', 'post', 'description']);
        foreach ([ApprovalDecisionRefusedException::LAUNCHER_REFUSED, ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN] as $launcher) {
            $this->assertStringContainsString('403 `' . $launcher . '`', $approve);
        }
    }

    public function testTheDecisionCommentIsDocumentedWithItsLimit(): void
    {
        $comment = $this->at([...self::SCHEMAS, 'ApprovalDecisionInput', 'properties', 'comment']);
        $this->assertIsArray($comment);
        $limit = $comment['maxLength'] ?? null;
        $this->assertIsInt($limit);
        $this->assertTrue($this->decisionWithComment(str_repeat('x', $limit))->validate(['comment']), 'A comment of the documented length is taken.');
        $this->assertFalse($this->decisionWithComment(str_repeat('x', $limit + 1))->validate(['comment']), 'A longer one is refused.');
    }

    /**
     * Expired requests: the maintenance run applies their timeout, an
     * approve only within the grace period, and the audit entry says when it
     * rejected instead.
     */
    public function testTheTimeoutsAreDocumentedAsTheSettlementAppliesThem(): void
    {
        $expires = $this->textAt([...self::SCHEMAS, 'ApprovalRequest', 'properties', 'expires_at', 'description']);
        $this->assertStringContainsString('maintenance run', $expires);
        $this->assertStringContainsString(sprintf('(%d seconds)', ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS), $expires);
        $this->assertStringContainsString(sprintf('%d minutes', ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS / 60), $expires);
        $this->assertStringContainsString('`reason: ' . ApprovalSettlement::REASON_GRACE_PERIOD_EXCEEDED . '`', $expires);
        $this->assertStringNotContainsString('stock deployments do not run', $expires, 'Timeouts apply on every installation now.');
        foreach (['/api/v1/approvals/{id}/approve', '/api/v1/approvals/{id}/reject'] as $path) {
            $this->assertStringNotContainsString('stays pending', $this->textAt(['paths', $path, 'post', 'description']));
        }

        // Regression (review of step 3, operations-2 and approvals-4): the
        // grace is counted when the timeout is applied, and the average
        // decision time counts a timed-out request until it expired.
        $this->assertStringContainsString('after `expires_at`, counted when the timeout is applied;', $expires);
        $average = $this->textAt([...self::SCHEMAS, 'ApprovalSummary', 'properties', 'avg_decision_seconds', 'description']);
        $this->assertStringContainsString('A timed-out request counts until its `expires_at`', $average);
    }

    public function testTheLaunchRefusalsOfApprovalRulesAreQuoted(): void
    {
        $refusals = [
            sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote('deploy-prod'), 1, 0),
            sprintf(JobLaunchService::RULE_MISSING, 7),
            sprintf(JobLaunchService::TEMPLATE_DELETED, 7),
            sprintf(JobLaunchService::TEMPLATE_CHANGED, 7),
        ];
        $api = array_column($this->examples('JobLaunchRejected'), 'message');
        foreach ($refusals as $refusal) {
            $this->assertContains($refusal, $api, 'JobLaunchRejected');
        }
        $trigger = $this->examples('TriggerLaunchRejected', false);
        $this->assertContains($refusals[0], $trigger, 'TriggerLaunchRejected');
        $this->assertContains($refusals[1], $trigger, 'TriggerLaunchRejected');

        // 2.12.0: the rule of the template's inventory, and one rule per
        // launch, refuse the API launch and the job trigger alike (job
        // template #7 on inventory #3).
        foreach (self::inventoryLaunchRefusals() as $refusal) {
            $this->assertContains($refusal, $api, 'JobLaunchRejected');
            $this->assertContains($refusal, $trigger, 'TriggerLaunchRejected');
        }
        foreach (['JobLaunchRejected', 'TriggerLaunchRejected'] as $response) {
            $this->assertStringContainsString('a launch waits for one rule only', $this->textAt([...self::RESPONSES, $response, 'description']), $response);
        }
        $this->assertStringContainsString(
            'A launch waits for one rule only, so a template whose own rule differs from its inventory\'s is refused too',
            $this->textAt(['paths', '/api/v1/jobs', 'post', 'description'])
        );
    }

    /**
     * A workflow launch is refused before its run exists, by the API and
     * the trigger alike, with the engine's messages.
     */
    public function testTheWorkflowLaunchRefusalsAreQuoted(): void
    {
        $this->assertSame('#/components/responses/WorkflowTriggerLaunchRejected', $this->at(['paths', '/trigger/fire-workflow', 'post', 'responses', '422', '$ref']));
        $this->assertSame('#/components/responses/WorkflowLaunchRejected', $this->at(['paths', '/api/v1/workflow-templates/{id}/launch', 'post', 'responses', '422', '$ref']));

        $refusals = [
            WorkflowRunVars::NOT_AN_OBJECT,
            sprintf(JobLaunchSecrets::PLACEHOLDER_SENT, SurveyDefinition::quote('db_password'), SurveySecrets::PLACEHOLDER),
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, SurveyDefinition::quote('Release approval'), SurveyDefinition::quote('deploy-prod'), 1, 0),
            sprintf(WorkflowExecutionService::NO_STEPS, 5),
            sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, 5),
            // 2.12.0: a job step whose job template and inventory have different rules.
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULES_DIFFER, SurveyDefinition::quote('Deploy')),
        ];
        $api = array_column($this->examples('WorkflowLaunchRejected'), 'message');
        $trigger = $this->examples('WorkflowTriggerLaunchRejected', false);
        foreach ($refusals as $refusal) {
            $this->assertContains($refusal, $api, 'WorkflowLaunchRejected');
            $this->assertContains($refusal, $trigger, 'WorkflowTriggerLaunchRejected');
        }
        // A workflow launch never answers JobLaunchService's refusals: they
        // fail the job step after the 201 (decision 13 of 2.12.0).
        foreach (self::inventoryLaunchRefusals() as $refusal) {
            $this->assertNotContains($refusal, $api, 'WorkflowLaunchRejected');
            $this->assertNotContains($refusal, $trigger, 'WorkflowTriggerLaunchRejected');
        }
        $this->assertContains(sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULE_MISSING, SurveyDefinition::quote('Release approval')), $api);
        $this->assertStringContainsString(
            sprintf(WorkflowRunVars::TOO_LARGE, WorkflowRunVars::MAX_EXTRA_VARS_BYTES),
            $this->textAt([...self::RESPONSES, 'WorkflowTriggerLaunchRejected', 'description'])
        );
        foreach (['/trigger/fire-workflow', '/api/v1/workflow-templates/{id}/launch'] as $path) {
            $this->assertStringContainsString((string)WorkflowRunVars::MAX_EXTRA_VARS_BYTES . ' bytes', $this->textAt(['paths', $path, 'post', 'description']), $path);
        }
        $this->assertStringNotContainsString('a workflow without steps', $this->textAt([...self::RESPONSES, 'TriggerLaunchFailed', 'description']), 'It answers 422 now.');
    }

    /**
     * Launch extra vars reach every job step; the spec said the start step
     * only.
     */
    public function testTheLaunchExtraVarsAreDocumentedForEveryJobStep(): void
    {
        $texts = [
            'POST /trigger/fire-workflow' => $this->textAt(['paths', '/trigger/fire-workflow', 'post', 'description']),
            'TriggerWorkflowInput.extra_vars' => $this->textAt([...self::SCHEMAS, 'TriggerWorkflowInput', 'properties', 'extra_vars', 'description']),
            'WorkflowLaunchInput.extra_vars' => $this->textAt([...self::SCHEMAS, 'WorkflowLaunchInput', 'properties', 'extra_vars', 'description']),
        ];
        foreach ($texts as $where => $text) {
            $this->assertStringContainsStringIgnoringCase('every job step', $text, $where);
            $this->assertStringNotContainsString('start step only', $text, $where);
            $this->assertStringNotContainsString('for the start step', $text, $where);
        }
        $this->assertSame(
            '#/components/schemas/WorkflowLaunchInput',
            $this->at(['paths', '/api/v1/workflow-templates/{id}/launch', 'post', 'requestBody', 'content', 'application/json', 'schema', '$ref'])
        );
    }

    /**
     * Every error a workflow step can show, in the words of the engine.
     */
    public function testTheStepErrorsAreQuotedAsTheEngineWordsThem(): void
    {
        $error = $this->textAt([...self::SCHEMAS, 'WorkflowJobStep', 'properties', 'error', 'description']);
        $messages = [
            WorkflowStepDispatcher::TEMPLATE_GONE,
            WorkflowStepDispatcher::RULE_GONE,
            WorkflowStepDispatcher::LAUNCH_FAILED,
            WorkflowStepDispatcher::REQUEST_FAILED,
            sprintf(WorkflowStepDispatcher::UNKNOWN_TYPE, '1'),
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_STEP, SurveyDefinition::quote('deploy-prod'), 1, 7, 0),
            sprintf(WorkflowStepRouter::APPROVAL_REJECTED, 12),
            sprintf(WorkflowStepRouter::JOB_APPROVAL_REJECTED, 12),
            sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, 7),
            WorkflowExecutionService::STEP_REMOVED,
            WorkflowCancellationService::STEP_RUN_CANCELED,
            WorkflowCancellationService::STEP_TEMPLATE_DELETED,
            // Review of step 3: a gate whose request is gone, and a step the maintenance run ended.
            WorkflowStepRouter::APPROVAL_MISSING,
            WorkflowStepRouter::JOB_APPROVAL_MISSING,
            StrandedJobSweep::STEP_RUN_ENDED,
        ];
        foreach ($messages as $message) {
            $this->assertStringContainsString('`' . $message . '`', $error);
        }
        // The other gate messages differ from the quoted ones in their end only.
        foreach ([WorkflowStepRouter::APPROVAL_TIMED_OUT, WorkflowStepRouter::JOB_APPROVAL_TIMED_OUT] as $format) {
            $this->assertStringEndsWith(' timed out.', sprintf($format, 12));
        }
        foreach ([WorkflowStepRouter::APPROVAL_CANCELED, WorkflowStepRouter::JOB_APPROVAL_CANCELED] as $format) {
            $this->assertStringEndsWith(' was canceled.', sprintf($format, 12));
        }
        $this->assertStringContainsString('`... timed out.`', $error);
        $this->assertStringContainsString('`... was canceled.`', $error);
        // Regression (2.11.0): the message named the step ID like a run or a
        // job ("#7"). Steps that failed before keep the text they stored, so
        // the spec quotes it for them, as a literal: the constant changed.
        $this->assertStringContainsString(
            'Steps that ran before 2.11.0 keep the text they stored then: `The next step #7 of this workflow no longer exists.`',
            $error
        );
        $stepId = $this->at([...self::SCHEMAS, 'WorkflowJobStep', 'properties', 'workflow_step_id']);
        $this->assertIsArray($stepId);
        $this->assertTrue($stepId['nullable'] ?? false, 'Executions of removed steps stay, with a null step ID.');
    }

    /**
     * Routing after a closed approval gate: an empty On Failure branch ends
     * the run as failed; the spec said every step goes on with the next one.
     */
    public function testTheRoutingDescribesTheClosedGates(): void
    {
        $failure = $this->textAt([...self::SCHEMAS, 'WorkflowStep', 'properties', 'on_failure_step_id', 'description']);
        $this->assertStringContainsString('null ends the run as failed', $failure);
        $always = $this->textAt([...self::SCHEMAS, 'WorkflowStep', 'properties', 'on_always_step_id', 'description']);
        $this->assertStringContainsString('`' . WorkflowStep::APPROVAL_ALWAYS_REFUSED . '`', $always);
        $this->assertStringContainsString('`' . sprintf(WorkflowStepRouter::ROUTE_TARGET_MISSING, 7) . '`', $this->textAt([...self::SCHEMAS, 'WorkflowStep', 'properties', 'on_success_step_id', 'description']));
        $create = $this->textAt(['paths', '/api/v1/workflow-templates', 'post', 'description']);
        $this->assertStringNotContainsString('whatever its outcome', $create);
        $this->assertStringContainsString('end the run as failed', $create);

        // Regression (review of step 3, contract-1): on_always_step_id said
        // it wins over the other branches, with an exception for approval
        // steps only, but after a closed gate the router takes On Failure on
        // a job step whose job template has an approval rule too.
        $this->assertStringContainsString('except after a closed approval gate (see `on_failure_step_id`): then it is not taken, on job steps too.', $always);
        $this->assertStringContainsString('A job step still takes it after a job that ran.', $always);
        $this->assertStringNotContainsString('wins over the other two. null', $always);
    }

    /**
     * Regression (review of step 3, contract-4): POST and PUT validate the
     * template before they read the steps or check the approval steps, but
     * the spec gave another order. The order sentences start with the
     * template's validation; PUT with the access check before it.
     *
     * Regression (2.11.0): PUT's order left out the 403 `Forbidden.` that
     * the steps' replacement answers, with the workflow locked, to a caller
     * who lost operator access meanwhile
     * (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED): after the 404 for a
     * workflow deleted meanwhile, before the 409.
     *
     * Regression (2.11.0; the spec of 2.10.1 has the gap too): PUT's order
     * left out the 403 of rule 12 that the guard checks again with the
     * workflow locked (ApprovalRuleAssignmentPolicy::
     * workflowStepsChangeRefusal()), for steps locked meanwhile, such as by
     * an approval step that an admin added: after the team scope, before
     * the 409 (WorkflowTemplatesStepGuardTest replays it).
     *
     * Regression (2.11.0, W5c): both orders left out the 403 of the new
     * steps' re-check in the write's transaction
     * (WorkflowTemplatesController::assertMayAddSteps()), which
     * WorkflowTemplatesStepGuardTest::testRegressionAJobTemplateGatedMeanwhileStopsTheStepsList
     * replays: a job template that got an approval rule meanwhile, after the
     * 409 of PUT, before the 422 of a template or step that cannot be saved.
     * The descriptions say that the re-check answers the same 403
     * (ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED).
     */
    public function testTheAnswerOrderOfTheWorkflowTemplateWritesIsTheControllers(): void
    {
        $orders = [
            'POST' => [
                $this->orderSentence(['paths', '/api/v1/workflow-templates', 'post', 'description']),
                '422 for a template that is not valid, such as one without a name (`Name cannot be blank.`); 422 for steps that cannot be read; 403 for approval steps and gated job steps; 422 for unknown approval rules; 422 or 403 for job templates; 403 for a job template that got an approval rule meanwhile; 422 for a template or step that cannot be saved.',
            ],
            'PUT' => [
                $this->orderSentence(['paths', '/api/v1/workflow-templates/{id}', 'put', 'description']),
                '403 `Forbidden.` without access to the workflow; 422 for a template that is not valid, such as an empty name (`Name cannot be blank.`); 422 for steps that cannot be read; 403 for approval steps and gated job steps; 422 for unknown approval rules; 422 or 403 for job templates; 404 for a workflow deleted meanwhile; 403 `Forbidden.` for operator access lost meanwhile; 403 for steps locked meanwhile; 409 for an active run; 403 for a job template that got an approval rule meanwhile; 422 for a template or step that cannot be saved.',
            ],
        ];
        foreach ($orders as $method => [$sentence, $expected]) {
            $this->assertSame($expected, $sentence, "{$method} /api/v1/workflow-templates names the answers in the controller's order.");
        }
        $this->assertSame('Forbidden.', WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, 'the 403 under the lock is the quoted text');
        $gated = '`' . ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED . '`';
        $this->assertStringContainsString(
            'Whether a job step\'s job template is gated is checked again in the transaction that writes the steps: an approval rule assigned to it meanwhile answers the same 403 ' . $gated . ', and nothing is written.',
            $this->textAt(['paths', '/api/v1/workflow-templates', 'post', 'description'])
        );
        $this->assertStringContainsString(
            'The new steps are checked again while the workflow is locked: an approval rule assigned meanwhile to the job template of a new job step answers the same 403 ' . $gated . ', and nothing is written.',
            $this->textAt(['paths', '/api/v1/workflow-templates/{id}', 'put', 'description'])
        );
        foreach (['/api/v1/workflow-templates' => 'post', '/api/v1/workflow-templates/{id}' => 'put'] as $path => $method) {
            $this->assertStringContainsString(
                'gated job steps without `approval-rule.update` (also a job template of the new steps that got an approval rule while they were written)',
                $this->textAt(['paths', $path, $method, 'responses', '403', 'description']),
                "{$method} {$path}: the 403"
            );
            $messages = array_map(static fn (mixed $error): mixed => is_array($error) ? ($error['message'] ?? null) : null, $this->inlineExamples($path, $method, '403'));
            $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $messages, "{$method} {$path}: the 403 examples");
        }
    }

    /**
     * Regression (review of step 3, workflows-1, approvals-2 and the job
     * template delete gap): the spec said the runs whose cancel failed stay
     * running until the maintenance run cancels them, and nothing about
     * the jobs such a cancel leaves. Deletes and cancels that fail part-way
     * leave nothing that runs, and the spec says so.
     */
    public function testWhatFailedCancelsLeaveIsDocumented(): void
    {
        $failedRuns = $this->textAt([...self::SCHEMAS, 'WorkflowTemplateDeletion', 'properties', 'failed_runs', 'description']);
        $this->assertStringContainsString('no runner starts that job, and its approval request takes no more votes', $failedRuns);
        $this->assertStringNotContainsString('they stay running until', $failedRuns);
        $this->assertStringContainsString(
            'A job whose cancel failed is not counted',
            $this->textAt([...self::SCHEMAS, 'JobTemplateDeletion', 'properties', 'canceled_jobs', 'description'])
        );
        $this->assertStringContainsString(
            'no runner starts that job, its approval request takes no more votes, and the maintenance run cancels the job later',
            $this->textAt(['paths', '/api/v1/workflow-jobs/{id}/cancel', 'post', 'description'])
        );
        $this->assertStringContainsString(
            'also when the job still waits but its workflow run has ended, which cancels the job too',
            $this->textAt(['paths', '/api/v1/approvals/{id}/approve', 'post', 'description'])
        );
    }

    /**
     * Approval steps are for admins, and steps wait for active runs.
     *
     * Regression (last review round of step 3, decision 4): gated job steps,
     * job steps whose job template has an approval rule, are for admins too;
     * the spec named the refusals of approval steps only.
     */
    public function testTheStepChangeRefusalsAreQuoted(): void
    {
        $create = array_column($this->inlineExamples('/api/v1/workflow-templates', 'post', '403'), 'message');
        $this->assertContains(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $create);
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $create);
        $update = array_column($this->inlineExamples('/api/v1/workflow-templates/{id}', 'put', '403'), 'message');
        $this->assertContains(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $update);
        $this->assertContains(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $update);
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $update);
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $update);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED . '`', $this->textAt(['paths', '/api/v1/workflow-templates', 'post', 'description']));
        $put = $this->textAt(['paths', '/api/v1/workflow-templates/{id}', 'put', 'description']);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED . '`', $put);
        $this->assertStringContainsString('a job template that gets an approval rule locks the workflows that use it from then on', $put);

        // 2.12.0: a job step whose job template's inventory has an approval
        // rule is a gated job step too, with refusals of its own.
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $create);
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $update);
        $this->assertContains(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $update);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED . '`', $this->textAt(['paths', '/api/v1/workflow-templates', 'post', 'description']));
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED . '`', $put);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED . '`', $put);
        $this->assertStringContainsString('and so does an inventory that gets one', $put);

        $this->assertSame('#/components/responses/WorkflowStepsLocked', $this->at(['paths', '/api/v1/workflow-templates/{id}', 'put', 'responses', '409', '$ref']));
        $locked = $this->at([...self::RESPONSES, 'WorkflowStepsLocked', 'content', 'application/json', 'example', 'error']);
        $this->assertSame(['message' => WorkflowStepChangeGuard::runsActiveMessage([12]), 'workflow_job_ids' => [12]], $locked);
        $this->assertStringContainsString(
            sprintf('first %d runs', WorkflowStepChangeGuard::RUN_LIST_LIMIT),
            $this->textAt([...self::RESPONSES, 'WorkflowStepsLocked', 'description'])
        );
        $this->assertStringContainsString(
            sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, 5),
            $this->textAt(['paths', '/api/v1/workflow-templates/{id}', 'put', 'description'])
        );
    }

    /**
     * Only admins assign a job template's approval rule; the field's
     * description quotes the refusal and the template's validation.
     */
    public function testTheTemplateRuleIsDocumentedAsAdminsOnly(): void
    {
        $field = $this->textAt([...self::SCHEMAS, 'JobTemplateInput', 'properties', 'approval_rule_id', 'description']);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED . '`', $field);
        $this->assertStringContainsString('`' . ApprovalRuleAssignmentPolicy::PERMISSION . '`', $field);
        foreach (['abc', PHP_INT_MAX] as $value) {
            $template = new JobTemplate();
            $template->setAttribute('approval_rule_id', $value);
            $this->assertFalse($template->validate(['approval_rule_id']));
            $this->assertStringContainsString('`' . $template->getFirstError('approval_rule_id') . '`', $field);
        }
        foreach ([['/api/v1/job-templates', 'post'], ['/api/v1/job-templates/{id}', 'put']] as [$path, $method]) {
            $this->assertStringContainsString(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $this->textAt(['paths', $path, $method, 'description']), "{$method} {$path}");
        }
    }

    /**
     * Approval rules: who may write them, what the API refuses and why, in
     * the words of the code.
     */
    public function testTheApprovalRuleAnswersAreQuoted(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $operator = array_keys($auth->getPermissionsByRole('operator'));
        $admin = array_keys($auth->getPermissionsByRole('admin'));
        foreach (['approval-rule.create', 'approval-rule.update'] as $permission) {
            $this->assertNotContains($permission, $operator, 'Operators no longer write rules, as the spec says.');
            $this->assertContains($permission, $admin);
        }
        foreach ([['/api/v1/approval-rules', 'post'], ['/api/v1/approval-rules/{id}', 'put']] as [$path, $method]) {
            $this->assertStringContainsString('only admin holds', $this->textAt(['paths', $path, $method, 'description']), "{$method} {$path}");
            $this->assertSame('#/components/responses/ApprovalRuleRejected', $this->at(['paths', $path, $method, 'responses', '422', '$ref']));
        }

        $rejected = $this->textAt([...self::RESPONSES, 'ApprovalRuleRejected', 'description']);
        foreach ($this->ruleValidationMessages() as $message) {
            $this->assertStringContainsString('`' . $message . '`', $rejected);
        }
        $examples = array_column($this->examples('ApprovalRuleRejected'), 'message');
        $this->assertContains(ApprovalRulesController::JOB_TEMPLATE_ID_REFUSED, $examples);
        $this->assertStringContainsString(
            ApprovalRulesController::JOB_TEMPLATE_ID_REFUSED,
            $this->textAt([...self::SCHEMAS, 'ApprovalRuleInput', 'properties', 'job_template_id', 'description'])
        );
        $this->assertTrue($this->at([...self::SCHEMAS, 'ApprovalRule', 'properties', 'job_template_id', 'deprecated']));

        $this->assertSame('#/components/responses/ApprovalRuleInUse', $this->at(['paths', '/api/v1/approval-rules/{id}', 'delete', 'responses', '409', '$ref']));
        // 2.12.0: an inventory that the rule gates counts, and comes first.
        $usage = new ApprovalRuleUsage('deploy-prod', [], 2, [], 1, 0, [], 1);
        $this->assertSame(
            ApprovalRuleWriteService::refusal($usage),
            $this->at([...self::SCHEMAS, 'ApprovalRuleInUseError', 'properties', 'error', 'properties', 'message', 'example'])
        );
        $this->assertSame(
            array_keys($usage->toArray()),
            $this->at([...self::SCHEMAS, 'ApprovalRuleUsage', 'required']),
            'ApprovalRuleUsage documents what used_by holds.'
        );
        $delete = $this->textAt(['paths', '/api/v1/approval-rules/{id}', 'delete', 'description']);
        $this->assertStringContainsString('A rule is in use while inventories, live job templates, steps of live workflows or pending approval requests', $delete);
        $this->assertStringContainsString('An inventory\'s rule is removed with `PUT /api/v1/inventories/{id}` and `approval_rule_id: null`, by an admin.', $delete);

        // Regression (review of step 3, contract-2): approver_config is
        // always an object, {} for a rule without approvers.
        $config = $this->textAt([...self::SCHEMAS, 'ApprovalRule', 'properties', 'approver_config', 'description']);
        $this->assertStringContainsString('Always a JSON object: `{}` for a rule without approvers.', $config);
        // Last review round of step 3: a list or a single value an older
        // version stored is returned as {}, and an update must replace it.
        $this->assertStringContainsString('stored as a JSON list or a single value names no approvers and is returned with `{}` too.', $config);
        $this->assertStringContainsString(
            'So does an update that leaves the field out, of a rule whose approver list an older version stored as a list or a single value, until the update sends an object',
            $this->textAt([...self::SCHEMAS, 'ApprovalRuleInput', 'properties', 'approver_config', 'description'])
        );
        $this->assertSame('object', $this->at([...self::SCHEMAS, 'ApprovalRule', 'properties', 'approver_config', 'type']));
    }

    public function testTheScheduleAndCancelConflictsAreQuoted(): void
    {
        $toggle = $this->at(['paths', '/api/v1/schedules/{id}/toggle', 'post', 'responses', '422', 'content', 'application/json', 'example', 'error', 'message']);
        $this->assertSame(sprintf(ScheduleEnablement::ENABLE_REFUSED, 'Nightly deploy', 15), $toggle);
        $this->assertStringContainsString(
            sprintf(ScheduleService::TEMPLATE_DELETED_DISABLED, 15),
            $this->textAt(['paths', '/api/v1/schedules/{id}/toggle', 'post', 'description'])
        );

        $job = new Job();
        $job->id = 42;
        $job->status = Job::STATUS_SUCCEEDED;
        $examples = $this->inlineExamples('/api/v1/jobs/{id}/cancel', 'post', '409');
        $this->assertContains(['message' => JobCancellationService::notCanceledMessage($job), 'status' => Job::STATUS_SUCCEEDED], $examples);

        $this->assertStringContainsString(
            '`' . WorkflowCancellationService::STEP_RUN_CANCELED . '`',
            $this->textAt(['paths', '/api/v1/workflow-jobs/{id}/cancel', 'post', 'description'])
        );
        $this->assertStringContainsString(
            '`' . WorkflowExecutionService::RUN_FINISHED . '`',
            $this->textAt(['paths', '/api/v1/workflow-jobs/{id}/cancel', 'post', 'description'])
        );
        $this->assertStringContainsString(
            '`' . WorkflowCancellationService::STEP_TEMPLATE_DELETED . '`',
            $this->textAt(['paths', '/api/v1/workflow-templates/{id}', 'delete', 'description'])
        );
        $this->assertStringContainsString(
            '`' . sprintf(JobLaunchService::TEMPLATE_DELETED, 7) . '`',
            $this->textAt(['paths', '/api/v1/job-templates/{id}', 'delete', 'description'])
        );
    }

    /**
     * The first validation error of each invalid approval rule the spec
     * names, as the model words it.
     *
     * @return list<string>
     */
    private function ruleValidationMessages(): array
    {
        $messages = [];
        $invalid = [
            ['name', ['name' => '']],
            ['name', ['name' => ['a list']]],
            ['approver_type', ['approver_type' => '']],
            ['approver_type', ['approver_type' => 'everyone']],
            ['approver_config', ['approver_config' => '']],
            ['approver_config', ['approver_config' => '{not json']],
            // Review of step 3 (contract-2): JSON, but no object.
            ['approver_config', ['approver_config' => '[7, 12]']],
            ['required_approvals', ['approver_type' => 'users', 'approver_config' => '{"user_ids": [7]}', 'required_approvals' => 3]],
        ];
        foreach ($invalid as [$attribute, $values]) {
            $rule = new ApprovalRule();
            $rule->setAttributes(['name' => 'deploy-prod', 'approver_type' => 'users', 'approver_config' => '{"user_ids": [7, 12]}'], false);
            $rule->setAttributes($values, false);
            $this->assertFalse($rule->validate([$attribute]), $attribute);
            $messages[] = (string)$rule->getFirstError($attribute);
        }

        return $messages;
    }

    /**
     * The launch refusals of 2.12.0 that the rule of a job template's
     * inventory, and one rule per launch, give: job template #7 on inventory
     * #3, as the spec's examples quote them.
     *
     * @return list<string>
     */
    private static function inventoryLaunchRefusals(): array
    {
        return [
            sprintf(JobLaunchService::RULES_DIFFER, 7, 3),
            sprintf(JobLaunchService::INVENTORY_RULE_MISSING, 7, 3),
            sprintf(JobLaunchService::INVENTORY_MISSING, 7, 3),
            sprintf(JobLaunchService::TEMPLATE_INVENTORY_CHANGED, 7, 3),
            sprintf(JobLaunchService::INVENTORY_CHANGED, 7, 3),
        ];
    }

    private function withStatus(ApprovalRequest $request, string $status): ApprovalRequest
    {
        $request->status = $status;

        return $request;
    }

    /**
     * What validation says about a comment one character too long.
     */
    private function commentTooLong(): string
    {
        $decision = $this->decisionWithComment(str_repeat('x', 1001));
        $this->assertFalse($decision->validate(['comment']));

        return (string)$decision->getFirstError('comment');
    }

    private function decisionWithComment(string $comment): ApprovalDecision
    {
        $decision = new ApprovalDecision();
        $decision->comment = $comment;

        return $decision;
    }

    /**
     * The examples of the named response $name: the error objects, or with
     * $objects false the plain `error` strings of the trigger endpoints.
     *
     * @return list<mixed>
     */
    private function examples(string $name, bool $objects = true): array
    {
        $examples = $this->at([...self::RESPONSES, $name, 'content', 'application/json', 'examples']);
        $this->assertIsArray($examples);

        return $this->errorsOf($examples, $objects);
    }

    /**
     * The error objects of the examples of the $code response of $method
     * $path, documented in place.
     *
     * @return list<mixed>
     */
    private function inlineExamples(string $path, string $method, string $code): array
    {
        $examples = $this->at(['paths', $path, $method, 'responses', $code, 'content', 'application/json', 'examples']);
        $this->assertIsArray($examples);

        return $this->errorsOf($examples, true);
    }

    /**
     * @param array<mixed> $examples
     * @return list<mixed>
     */
    private function errorsOf(array $examples, bool $objects): array
    {
        $errors = [];
        foreach ($examples as $example) {
            $this->assertIsArray($example);
            $value = $example['value'] ?? null;
            $this->assertIsArray($value);
            $error = $value['error'] ?? null;
            $this->assertTrue($objects ? is_array($error) : is_string($error), 'An example of the documented error shape.');
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * The part of the text at $keys after "The answers come in this order: ".
     *
     * @param list<string> $keys
     */
    private function orderSentence(array $keys): string
    {
        $text = $this->textAt($keys);
        $this->assertSame(1, preg_match('/The answers come in this order: (.*?\.)(?: [A-Z]|$)/', $text, $match), implode('.', $keys) . ' names an order.');

        return $match[1] ?? '';
    }

    /**
     * The text at $keys, on one line.
     *
     * @param list<string> $keys
     */
    private function textAt(array $keys): string
    {
        $value = $this->at($keys);
        $this->assertIsString($value, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no text.');

        return trim((string)preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param list<string> $keys
     */
    private function at(array $keys): mixed
    {
        $node = $this->spec();
        foreach ($keys as $key) {
            $this->assertIsArray($node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $this->assertArrayHasKey($key, $node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $node = $node[$key];
        }

        return $node;
    }

    /** The spec file under test. */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        if ($this->spec === null) {
            $spec = Yaml::parseFile($this->specPath());
            $this->assertIsArray($spec);
            $this->spec = $spec;
        }

        return $this->spec;
    }
}
