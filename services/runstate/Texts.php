<?php

declare(strict_types=1);

namespace app\services\runstate;

/**
 * The texts that say what a workflow run waits for, where an approval
 * request comes from and how it was decided: the run page's banner and its
 * facts, the personal line, the decision notes of the steps, the launch
 * flash, the For column of the approval lists, the job page's workflow
 * context, the template page's active runs, and the notes of the run and
 * job pages once their live updates stopped.
 *
 * Every text is plain text: whoever renders it escapes it (Html::encode(),
 * textContent). Names are inserted between the quotes as they are. Times
 * inside the texts are TimeHelper::stamp() values, so they stay correct
 * while a page is open.
 */
final class Texts
{
    /** Banner of a run waiting at an approval step; sprintf(): the step name. */
    public const BANNER_APPROVAL_STEP = 'Waiting for approval at step "%s".';

    /** Banner of a run whose gated job step waits for approval; sprintf(): the job ID, the step name. */
    public const BANNER_GATED_JOB = 'Job #%d of step "%s" waits for approval.';

    /** Banner of a run whose job step waits for a runner; sprintf(): the job ID, the step name. */
    public const BANNER_QUEUED = 'Job #%d of step "%s" is queued: it waits for a runner.';

    /** Banner of a run at a pause step; sprintf(): the step name. */
    public const BANNER_PAUSED = 'Paused at step "%s" until someone resumes the run.';

    /** Banner fact of a wait whose approval request no longer exists. */
    public const BANNER_REQUEST_MISSING = 'Its approval request no longer exists: the next maintenance run cancels '
        . 'the wait, and the step fails.';

    /** sprintf(): the request ID. */
    public const FACT_REQUEST = 'Approval Request #%d';

    /** sprintf(): the rule name. */
    public const FACT_RULE = 'Rule "%s"';

    /** The request's rule no longer exists. */
    public const FACT_RULE_DELETED = 'Rule deleted';

    /** sprintf(): the approvals that count, the approvals the rule requires. */
    public const FACT_APPROVALS = '%d of %d approval(s)';

    /** sprintf(): the eligible approvers who have not voted yet. */
    public const FACT_OPEN_APPROVERS = '%d approver(s) can still decide';

    /** sprintf(): since when the step waits (TimeHelper::stamp()). */
    public const FACT_SINCE = 'waiting since %s';

    /** sprintf(): when the request expires (TimeHelper::stamp()), THEN_REJECTED or THEN_APPROVED. */
    public const FACT_EXPIRES = 'expires %s (then %s)';

    /** The request has no timeout. */
    public const FACT_NEVER_EXPIRES = 'never expires';

    /** sprintf(): when the request expired (TimeHelper::stamp()), THEN_REJECTED or THEN_APPROVED. */
    public const FACT_EXPIRED = 'expired %s; the next maintenance run applies its timeout (then %s)';

    /** What the timeout does: it rejects. */
    public const THEN_REJECTED = 'rejected';

    /** What the timeout does: it approves. */
    public const THEN_APPROVED = 'approved';

    /** The viewer may vote on the request now. */
    public const PERSONAL_MAY_DECIDE = 'You can decide on this request.';

    /** The viewer launched the run; nobody decides on their own launch. */
    public const PERSONAL_LAUNCHER = 'You launched this run, so another approver must decide.';

    /** The viewer has voted, and the request still waits. */
    public const PERSONAL_VOTED = 'You have voted on this request; it waits for more approvals.';

    /** The viewer is not on the rule's approver list. */
    public const PERSONAL_NOT_APPROVER = 'You are not an approver of this request.';

    /** The viewer is on the rule's list, but may not decide (no approval.decide, no access, a disabled account). */
    public const PERSONAL_NOT_ELIGIBLE = 'You are on the rule\'s approver list, but may not decide on this request.';

    /** The link to the request page, for a viewer who may decide. */
    public const REVIEW_BUTTON = 'Review & decide';

    /** The approvals still possible cannot reach the rule's threshold. */
    public const WARNING_UNDECIDABLE = 'Too few of the rule\'s approvers can still decide, so this request can no '
        . 'longer be approved. An admin can add approvers to the rule, or cancel the run.';

    /** sprintf(): the approvers' names (joined with ', '), when (TimeHelper::stamp()). */
    public const DECIDED_APPROVED_BY = 'Approved by %s at %s.';

    /** sprintf(): when (TimeHelper::stamp()); for a viewer who may not see who approved. */
    public const DECIDED_APPROVED = 'Approved at %s.';

    /** sprintf(): the names of those who rejected (joined with ', '), when (TimeHelper::stamp()). */
    public const DECIDED_REJECTED_BY = 'Rejected by %s at %s.';

    /** sprintf(): when (TimeHelper::stamp()). */
    public const DECIDED_TIMEOUT_APPROVED = 'Approved by its timeout at %s, without a decision.';

    /** Appended to a decision note after a space; sprintf(): the comment. */
    public const DECIDED_COMMENT = 'Comment: "%s"';

    /** The launch flash; sprintf(): the workflow name. */
    public const LAUNCHED = 'Workflow "%s" launched.';

    /** sprintf(): the workflow name, the step name, the request ID. */
    public const LAUNCHED_AWAITING_APPROVAL = 'Workflow "%s" launched. Step "%s" waits for approval (Approval Request #%d).';

    /** sprintf(): the workflow name, the step name. */
    public const LAUNCHED_PAUSED = 'Workflow "%s" launched. It is paused at step "%s".';

    /** What a request is for; sprintf(): the workflow name, the step name, the run ID. */
    public const FOR_WORKFLOW_STEP = '%s › %s (Workflow Job #%d)';

    /** sprintf(): the job template name. */
    public const FOR_JOB_TEMPLATE = '%s (job template)';

    /** sprintf(): the name of the deleted job template. */
    public const FOR_DELETED_JOB_TEMPLATE = '%s (deleted job template)';

    /**
     * After the name of a workflow template that was deleted, separated by a
     * space, wherever a run, an approval request or a job names it: its page
     * answers 404, so the name is not linked, and the marker is muted, as the
     * jobs list marks a deleted job template.
     */
    public const WORKFLOW_DELETED = '(deleted)';

    /** sprintf(): the request ID. */
    public const JOB_APPROVAL_NOTE = 'This job waits for Approval Request #%d.';

    /** sprintf(): the run ID, the step name. */
    public const JOB_WORKFLOW_ORIGIN = '#%d, step "%s"';

    /** sprintf(): the step name, the run ID. */
    public const PLACEHOLDER_SUBTITLE = 'Approval step "%s" of Workflow Job #%d';

    /** The template of a placeholder job. */
    public const PLACEHOLDER_TEMPLATE = 'none (placeholder of an approval step)';

    /** sprintf(): the step name, the run ID. */
    public const PLACEHOLDER_LABEL = 'Approval step "%s" (Workflow Job #%d)';

    /** sprintf(): the step name, the run ID. */
    public const PLACEHOLDER_NOTE = 'This job only holds the approval request of step "%s" in Workflow Job #%d and '
        . 'never runs: approved, the run goes on; rejected, timed out or canceled, the step fails.';

    public const ACTIVE_RUNS_TITLE = 'Active runs';

    /** sprintf(): the step name, since when (TimeHelper::stamp()). */
    public const ACTIVE_RUN_AT = 'at step "%s" since %s';

    /** sprintf(): the launcher's name. */
    public const ACTIVE_RUN_BY = 'launched by %s';

    /** sprintf(): the active runs not listed. */
    public const ACTIVE_RUNS_MORE = 'and %d more';

    public const ACTIVE_RUNS_ALL = 'All runs of this workflow';

    /** sprintf(): the active runs that wait at the step. */
    public const STEP_WAITING_MARKER = '%d run(s) waiting';

    /**
     * The run page's note in place of its Live badge once its poll was
     * refused (401 or 403: signed out, or no access any more; 404: the run
     * is gone): the page no longer follows the run.
     */
    public const LIVE_STOPPED_RUN = 'Live updates stopped. Reload the page to keep following the run.';

    /** The job page's note in place of its Live badge once its poll was refused, as LIVE_STOPPED_RUN. */
    public const LIVE_STOPPED_JOB = 'Live updates stopped. Reload the page to keep following the job.';
}
