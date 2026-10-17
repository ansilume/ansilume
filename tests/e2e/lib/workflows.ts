import { Locator, Page, expect } from '@playwright/test';
import { expectFlash, submitForm } from './helpers';
import { indexRows, rowsWhere } from './scoping';

/**
 * Fixtures of commands/E2eWorkflowGateSeeder.php and
 * commands/E2eWorkflowUxSeeder.php, restored by every seed, and helpers for
 * the workflow template and run pages.
 */

/** Never launched: the job steps e2e-steps-build and e2e-steps-cleanup; the build step's On Failure leads to the cleanup step. */
export const STEPS_WORKFLOW = 'e2e-steps-workflow';
export const STEPS_BUILD = 'e2e-steps-build';
export const STEPS_CLEANUP = 'e2e-steps-cleanup';

/** Never launched: the job step e2e-approval-steps-job; an admin adds steps of every type through the form and removes them. */
export const APPROVAL_STEPS_WORKFLOW = 'e2e-approval-steps-workflow';
export const APPROVAL_STEPS_JOB = 'e2e-approval-steps-job';

/** Never launched: the job step e2e-operator-steps-job; e2e-operator adds job and pause steps through the form and removes them. */
export const OPERATOR_STEPS_WORKFLOW = 'e2e-operator-steps-workflow';
export const OPERATOR_STEPS_JOB = 'e2e-operator-steps-job';

/** A pause step and a job step, with a run paused at the pause step: its steps are locked. */
export const LOCKED_WORKFLOW = 'e2e-locked-workflow';
export const LOCKED_PAUSE = 'e2e-locked-pause';

/** The same shape, for the spec that deletes the template. */
export const DELETE_WORKFLOW = 'e2e-delete-workflow';
export const DELETE_PAUSE = 'e2e-delete-pause';

/** No steps: a launch is refused. */
export const EMPTY_WORKFLOW = 'e2e-empty-workflow';

/** Two job steps on e2e-secret-template, whose jobs stay queued: no runner serves its group. */
export const TRIGGER_VARS_WORKFLOW = 'e2e-trigger-vars-workflow';
export const TRIGGER_VARS_FIRST = 'e2e-trigger-vars-first';
export const TRIGGER_VARS_SECOND = 'e2e-trigger-vars-second';

/** e2e-admin and e2e-operator approve; one approval; no timeout. */
export const GATE_RULE = 'e2e-gate-rule';

/**
 * An approval step on e2e-gate-rule WITHOUT an On Failure branch, then a job
 * step: a rejection ends the run as failed, the job step never starts
 * (workflow-jobs/approval-flow.spec.ts).
 */
export const GATE_CONTINUE_WORKFLOW = 'e2e-gate-continue-workflow';
export const GATE_CONTINUE_APPROVE = 'e2e-gate-continue-approve';
export const GATE_CONTINUE_JOB = 'e2e-gate-continue-job';

/** The same shape, for the spec that cancels a run while it waits for its approval. */
export const CANCEL_WORKFLOW = 'e2e-cancel-workflow';
export const CANCEL_APPROVE = 'e2e-cancel-approve';
export const CANCEL_JOB = 'e2e-cancel-job';

/**
 * The job step e2e-gated-job-step on e2e-gated-job-template, which e2e-gate-rule
 * gates and whose runner group no runner serves, then the pause step
 * e2e-gated-job-after. Only admins may change its steps.
 */
export const GATED_JOB_WORKFLOW = 'e2e-gated-job-workflow';
export const GATED_JOB_TEMPLATE = 'e2e-gated-job-template';
export const GATED_JOB_STEP = 'e2e-gated-job-step';
export const GATED_JOB_AFTER = 'e2e-gated-job-after';

/** An approval step on e2e-solo-gate-rule, whose only approver is e2e-admin, then a job step: e2e-admin's launch is refused. */
export const SOLO_GATE_WORKFLOW = 'e2e-solo-gate-workflow';

/** Why e2e-admin's launch of e2e-solo-gate-workflow is refused (ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH). */
export const SOLO_GATE_REFUSAL = 'Step "e2e-solo-gate-approve": approval rule "e2e-solo-gate-rule" needs 1 approval(s) from users '
  + 'other than the one who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.';

/** commands/E2eController.php: an approval step on e2e-approval-rule, then the job step e2e-step-job-after-approval. */
export const APPROVAL_WORKFLOW = 'e2e-approval-workflow';

/**
 * The job step e2e-late-gate-job on e2e-late-gate-template, which has no
 * approval rule after a seed: a spec gives it one, which locks the steps of
 * the workflow for operators, and takes it away again.
 */
export const LATE_GATE_WORKFLOW = 'e2e-late-gate-workflow';
export const LATE_GATE_TEMPLATE = 'e2e-late-gate-template';
export const LATE_GATE_JOB = 'e2e-late-gate-job';

/**
 * commands/E2eWorkflowUxSeeder.php: every kind of route cell. In this order:
 * build (job; On Failure → cleanup), approve (approval step on
 * e2e-gate-rule), deploy (job on e2e-gated-job-template: a gated job step),
 * verify (job; On Failure ↺ deploy), wait (pause), cleanup (job; both
 * branches end the workflow), spare (job; no branch leads to it).
 */
export const ROUTES_WORKFLOW = 'e2e-routes-workflow';
export const ROUTES_STEPS = {
  build: 'e2e-routes-build',
  approve: 'e2e-routes-approve',
  deploy: 'e2e-routes-deploy',
  verify: 'e2e-routes-verify',
  wait: 'e2e-routes-wait',
  cleanup: 'e2e-routes-cleanup',
  spare: 'e2e-routes-spare',
} as const;

/**
 * commands/E2eWorkflowUxSeeder.php: steps older versions could save, in this
 * order: start (job; On Success to step ID LEGACY_MISSING_STEP_ID, which
 * does not exist), gone (job on the soft-deleted e2e-routes-gone-template),
 * pause (pause with an Always branch to end), end (job).
 */
export const LEGACY_WORKFLOW = 'e2e-routes-legacy-workflow';
export const LEGACY_STEPS = {
  start: 'e2e-legacy-start',
  gone: 'e2e-legacy-gone',
  pause: 'e2e-legacy-pause',
  end: 'e2e-legacy-end',
} as const;
export const LEGACY_MISSING_STEP_ID = 999999999;

/**
 * commands/E2eWorkflowUxSeeder.php: check (job; On Failure ↺ itself), then
 * retry (job; On Success ↺ check, On Failure ends the workflow).
 */
export const LOOP_WORKFLOW = 'e2e-routes-loop-workflow';
export const LOOP_CHECK = 'e2e-loop-check';
export const LOOP_RETRY = 'e2e-loop-retry';

/** commands/E2eWorkflowUxSeeder.php: build (job; On Failure → deploy), test (job), deploy (job). */
export const EDIT_STEPS_WORKFLOW = 'e2e-edit-steps-workflow';
export const EDIT_BUILD = 'e2e-edit-build';
export const EDIT_TEST = 'e2e-edit-test';
export const EDIT_DEPLOY = 'e2e-edit-deploy';

/** commands/E2eWorkflowUxSeeder.php: two job steps, which e2e-operator edits, moves and inserts below. */
export const EDIT_OPERATOR_WORKFLOW = 'e2e-edit-operator-workflow';
export const EDIT_OPERATOR_FIRST = 'e2e-edit-operator-first';
export const EDIT_OPERATOR_SECOND = 'e2e-edit-operator-second';

/** The job template of every other job step of the fixtures. */
export const JOB_TEMPLATE = 'e2e-template';

/**
 * Only admins (approval-rule.update) add approval steps and job steps whose
 * job template has an approval rule, its own or its inventory's ("gated job
 * steps"), and change the steps of a workflow that has either
 * (services/ApprovalRuleAssignmentPolicy.php). The refusal names the job
 * template's own rule first (GATED_JOB_*), else its inventory's
 * (GATED_INVENTORY_JOB_*).
 */
export const APPROVAL_STEP_ADD_REFUSED = 'Only admins may add approval steps: that needs approval-rule.update.';
export const APPROVAL_WORKFLOW_STEPS_REFUSED = 'This workflow has an approval step: only admins may change its steps (approval-rule.update).';
export const GATED_JOB_STEP_ADD_REFUSED = 'Only admins may add a job step whose job template has an approval rule: '
  + 'that needs approval-rule.update.';
export const GATED_JOB_WORKFLOW_STEPS_REFUSED = 'This workflow has a job step whose job template has an approval rule: '
  + 'only admins may change its steps (approval-rule.update).';
/** ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED: also for an edit that gives a job step such a job template. */
export const GATED_INVENTORY_JOB_STEP_ADD_REFUSED = 'Only admins may add a job step whose job template\'s inventory has an '
  + 'approval rule: that needs approval-rule.update.';
/** ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED */
export const GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED = 'This workflow has a job step whose job template\'s inventory has an '
  + 'approval rule: only admins may change its steps (approval-rule.update).';

/**
 * services/WorkflowTemplatePage.php GATED_TEMPLATES_HINT: the hint under the
 * job template select of the add-step form, for users who may not add gated
 * job steps: job templates with a rule of their own, or on an inventory with
 * one, are left out of the select.
 */
export const GATED_TEMPLATES_HINT = 'Job templates with an approval rule, or on an inventory with one, are not listed: '
  + 'only admins may add a job step for them.';

/**
 * The notice of a workflow whose steps the user may not change
 * (views/workflow-template/view.php): why (`reason`), then what is left
 * (services/WorkflowTemplatePage.php STEPS_LOCKED_NOTE).
 */
export const STEPS_LOCKED_NOTICE = '#wf-approval-steps-locked-notice';
export const STEPS_LOCKED_NOTE = 'You may still rename the workflow, launch it and manage its trigger token.';
export function stepsLockedNotice(reason: string): string {
  return `${reason} ${STEPS_LOCKED_NOTE}`;
}

/**
 * services/WorkflowStepChangeGuard.php RUNS_ACTIVE, for the runs `runs`
 * ("#12, #15"): why the steps cannot change.
 */
export function runsActive(runs: string): string {
  return `Steps cannot change while run(s) ${runs} of this workflow are active: wait for them to finish or cancel them.`;
}

/**
 * The texts of the steps table (services/WorkflowStepsTable.php). A step is
 * named by its position in the run order: "3. Deploy" (STEP_LABEL), 'step 3
 * "Deploy"' (STEP_REFERENCE).
 */
export const ROUTE_END_SUCCEEDS = 'end · run succeeds';
export const ROUTE_END_FAILS = 'end · run fails';
/** A branch the step never takes, and an empty Always branch. */
export const ROUTE_UNUSED = '—';
export const ROUTE_ALSO_AFTER_FAILURE = ', also after a failure';
export const ROUTE_ALSO_AFTER_FAILED_JOB = ', also after a failed job';
export const ROUTE_AFTER_REFUSED_APPROVAL = ' after a refused approval';
export const PART_SEPARATOR = '; ';
export const APPROVAL_STEP_SUFFIX = ' (approval step)';
export const PAUSE_ALWAYS_NOTE = '(on Resume)';
export const START_BADGE = 'Start';
export const START_TITLE = 'Runs start with this step.';
export const UNREACHABLE_BADGE = 'unreachable';
/** No branch a run takes leads to the step: not from a reachable step, nor from a step that cannot run, where runs fail. */
export const UNREACHABLE_TITLE = 'No branch that a run takes leads to this step, so runs never start it.';
export const GATED_BADGE = 'needs approval';
export const POSITION_TITLE = 'Position: runs start with step 1.';
export const NEXT_TITLE = 'This branch is empty: the run goes on with the next step in the list, so where it leads changes when steps move.';
export const LOOP_TITLE = 'Leads back to this step or an earlier one: the run repeats steps until a branch leads elsewhere.';
export const ALWAYS_WINS_TITLE = 'Not used: the Always branch is taken instead.';
export const PAUSE_FAILURE_TITLE = 'Not used: a pause step does not fail; Resume continues with On Success.';
export const CANNOT_RUN_TITLE = 'Not used: this step cannot run, so a run fails when it reaches it.';
export const MISSING_TITLE = 'The step this branch leads to no longer exists: the run fails here. Edit the step to choose another one.';
export const REFUSED_APPROVAL_TITLE = 'A refused approval was rejected, timed out or canceled: the job never ran, '
  + 'only On Failure is followed, and Always is not.';
export const SUCCESS_PATH_LOOP_NOTE = 'When every step succeeds, the run repeats these steps until one fails or the run is canceled.';
export const LEGEND = 'A run starts with step 1. An empty branch goes on with the next step in the list, marked (next), '
  + 'and changes when steps move. ↺ marks a branch back to the same or an earlier step.';
export const MOVE_TO_START = 'To the start';
export const MOVE_TO_END = 'To the end';
/** MOVE_MENU_LIMIT: above this many steps, Move to… links to the step's edit page, at its Position field, instead of a menu. */
export const MOVE_MENU_LIMIT = 20;
/** The id of the Position field of the step form (WorkflowStepFormView::POSITION_FIELD_ID), where that link leads. */
export const POSITION_FIELD_ID = 'ws-position-field';
/** The column headings of the steps table; the Actions column only for users who may change the steps. */
export const STEPS_COLUMNS = ['#', 'Name', 'Type', 'Target', 'On Success', 'On Failure', 'Always'];

/** GATED_TITLE: the hover text of the "needs approval" badge of a step whose job template has the rule `rule` of its own. */
export function gatedTitle(rule: string): string {
  return `Its job template has the approval rule "${rule}": the job waits for an approval before it runs.`;
}

/**
 * GATED_INVENTORY_TITLE: the hover text of the "needs approval" badge of a
 * step whose job template has no rule of its own, but whose inventory has
 * the rule `rule`.
 */
export function gatedInventoryTitle(rule: string): string {
  return `Its job template's inventory has the approval rule "${rule}": the job waits for an approval before it runs.`;
}

/** STEP_LABEL: the step at `position` named `name`, "3. Deploy". */
export function stepLabel(position: number, name: string): string {
  return `${position}. ${name}`;
}

/** STEP_REFERENCE: 'step 3 "Deploy"', as the action labels and refusals name a step. */
export function stepReference(position: number, name: string): string {
  return `step ${position} "${name}"`;
}

/** ROUTE_TO: an explicit branch to a later step. */
export function routeTo(position: number, name: string): string {
  return `→ ${stepLabel(position, name)}`;
}

/** ROUTE_TO_NEXT: an empty branch, which goes on with the next step in the list. */
export function routeNext(position: number, name: string): string {
  return `→ ${stepLabel(position, name)} (next)`;
}

/** ROUTE_LOOP: a branch back to the step itself or an earlier one. */
export function routeLoop(position: number, name: string): string {
  return `↺ ${stepLabel(position, name)}`;
}

/** ROUTE_MISSING: a branch to a step that no longer exists. */
export function routeMissing(stepId: number | string): string {
  return `missing step (ID ${stepId}) · run fails`;
}

/** The success path above the steps table: its parts joined by " → " (SUCCESS_PATH_SEPARATOR). */
export function successPath(...parts: string[]): string {
  return parts.join(' → ');
}

/**
 * The texts of the step form (services/WorkflowStepFormView.php): the
 * Position select, its preview and warnings, and the branch choices.
 */
export const POSITION_SELECT = '#ws-position';
export const POSITION_PREVIEW = '#ws-position-preview';
export const POSITION_WARNING = '#ws-position-warning';
export const POSITION_START = 'At the start (runs will start with this step)';
export const END_SUCCEEDS_OPTION = 'End the workflow: the run succeeds';
export const END_FAILS_OPTION = 'End the workflow: the run fails';
export const NEXT_OPTION_NONE_SUCCEEDS = 'Next in the list: none, the run succeeds';
export const NEXT_OPTION_NONE_FAILS = 'Next in the list: none, the run fails';
export const ALWAYS_NONE_OPTION = 'Not set: On Success or On Failure decides';
export const SUCCESS_LABEL = 'On Success → go to';
export const SUCCESS_LABEL_PAUSE = 'After Resume → go to';
export const FAILURE_DEFAULT_NOTE = 'A new job step ends the workflow when it fails. Choose "Next in the list" to go on instead.';
export const TYPE_LOCKED = "A step's type cannot change: add a new step and remove this one.";
export const LEGACY_ALWAYS_NOTE = 'This step has an Always branch from an older version, which runs follow when it succeeds. '
  + 'It shows under On Success now and is saved there.';
export const TEMPLATE_GONE_HINT = 'The job template of this step no longer exists: choose another one.';
export const ROUTED_BY_NONE = 'No other step routes here.';
/** The notes under a step of "Steps that route here" whose branch to the step no run takes. */
export const ROUTED_BY_ALWAYS_WINS = 'Not used: its Always branch is taken instead.';
export const ROUTED_BY_PAUSE_FAILURE = 'Not used: a pause step does not fail. Saving that step clears its On Failure.';
/** THIS_APPROVAL_OFF_PATH: the Position warning of an approval step that no success would lead to. */
export const THIS_APPROVAL_OFF_PATH = 'This approval step will not be on the success path: '
  + 'runs whose steps all succeed will not wait for it.';
/**
 * HELP_JOB_TITLE and HELP_JOB: the routing help of a job step
 * (#ws-routing-help-job), the title, then the text. A job step waits for an
 * approval when its job template or that template's inventory has an
 * approval rule; a closed gate follows only On Failure.
 */
export const HELP_JOB_TITLE = 'Job step:';
export const HELP_JOB = 'success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always '
  + 'overrides both. If the job needs approval (an approval rule of its job template or of its inventory) and the '
  + 'approval is rejected, times out or is canceled, the job does not run: then only On Failure is followed (left '
  + 'empty, the run ends as failed), and Always is not.';

/** POSITION_END: the Position choice after the last step, named by its label. */
export function positionEnd(lastLabel: string): string {
  return `At the end (after ${lastLabel})`;
}

/** POSITION_AFTER: the Position choice after a step, named by its label. */
export function positionAfter(label: string): string {
  return `After ${label}`;
}

/** POSITION_KEEP: Edit Step's default Position. */
export function positionKeep(position: number): string {
  return `Keep at position ${position}`;
}

/** NEXT_OPTION: the empty On Success choice, which goes on with `name`. */
export function nextOption(name: string): string {
  return `Next in the list: "${name}"`;
}

/** NEXT_FAILURE_OPTION: the empty On Failure choice of a job step, which goes on with `name`. */
export function nextFailureOption(name: string): string {
  return `Next in the list: "${name}", also after a failure`;
}

/** WILL_START_THIS: a Position warning; `start` is the step runs start with now. */
export function willStartThis(start: string): string {
  return `Runs will start with this step instead of "${start}".`;
}

/** THIS_WILL_PRECEDE_APPROVAL: a Position warning for a job step placed before the approval step `approval`. */
export function thisWillPrecedeApproval(approval: string): string {
  return `This job step will run before the approval step "${approval}", without waiting for it.`;
}

/** WILL_START (WorkflowStepsTable): a Move to… hint. */
export function willStart(newStart: string, oldStart: string): string {
  return `Runs will start with "${newStart}" instead of "${oldStart}".`;
}

/** WILL_PRECEDE_APPROVAL (WorkflowStepsTable): a Move to… hint. */
export function willPrecedeApproval(job: string, approval: string): string {
  return `Job step "${job}" will run before the approval step "${approval}", without waiting for it.`;
}

/** WILL_SKIP_APPROVAL (WorkflowStepsTable): a Move to… hint, the approval step would be off the success path. */
export function willSkipApproval(approval: string): string {
  return `The approval step "${approval}" will not be on the success path: runs whose steps all succeed will not wait for it.`;
}

/** PREVIEW_SKIPPED_TO: the Position preview below `before`, whose success goes on with `next`. */
export function previewSkippedTo(before: string, next: string): string {
  return `Listed after "${before}", but a successful "${before}" goes on with "${next}", skipping this step.`;
}

/**
 * The flashes of a step change (services/WorkflowStepChangeSummary.php): the
 * success flash, then one warning flash per warning.
 */
export function stepAdded(name: string, position: number, count: number, after?: string): string {
  return `Step "${name}" added at position ${position} of ${count}${placedWhere(position, count, after)}.`;
}

export function stepMoved(name: string, position: number, count: number, after?: string): string {
  return `Step "${name}" moved to position ${position} of ${count}${placedWhere(position, count, after)}.`;
}

/** SAVED: `fields` are the labels of the changed fields ("name", "On Failure"). */
export function stepSaved(name: string, ...fields: string[]): string {
  return `Step "${name}" saved: ${fields.join(', ')} changed.`;
}

/** SAVED_MOVED: the changed fields, then where the step is now. */
export function stepSavedMoved(name: string, fields: string[], position: number, count: number, after?: string): string {
  return `Step "${name}" saved: ${fields.join(', ')} changed; it is now at position ${position} of ${count}`
    + `${placedWhere(position, count, after)}.`;
}

export function stepRemoved(name: string, position: number, count: number): string {
  return `Step "${name}" removed; it was at position ${position} of ${count}.`;
}

export function stepStays(name: string, position: number): string {
  return `Step "${name}" stays at position ${position}.`;
}

/** UNCHANGED: an edit that changes nothing (an info flash). */
export function stepUnchanged(name: string): string {
  return `Step "${name}" is unchanged.`;
}

/** NOW_ENDS: an empty branch of `name` now ends the run, which led to `was`. */
export function nowEnds(name: string, was: string): string {
  return `"${name}" now ends the run (was "${was}").`;
}

/** START_CHANGED: a warning flash. */
export function startChanged(now: string, was: string): string {
  return `Runs now start with "${now}" (was "${was}").`;
}

/** JOB_BEFORE_APPROVAL: a warning flash. */
export function jobBeforeApproval(job: string, approval: string): string {
  return `Job step "${job}" now runs before the approval step "${approval}": runs start it without waiting for that approval.`;
}

/** APPROVAL_OFF_SUCCESS_PATH: a warning flash, runs whose steps all succeed do not pass the approval step. */
export function approvalOffSuccessPath(approval: string): string {
  return `The approval step "${approval}" is not on the success path: runs whose steps all succeed do not wait for it.`;
}

/** AT_START or AFTER of a change message; nothing for the only step. */
function placedWhere(position: number, count: number, after?: string): string {
  if (count < 2) {
    return '';
  }
  return position === 1 ? ', at the start' : `, after "${after ?? ''}"`;
}

/**
 * services/WorkflowStepChangeGuard.php STEP_ROUTED_TO: why the step `name`
 * was not removed; `routing` are the steps that route to it, by position
 * ('step 1 "Build"', stepReference()).
 */
export function stepRoutedTo(name: string, ...routing: string[]): string {
  return `Step "${name}" was not removed: other steps route to it (${routing.join(', ')}). `
    + 'Edit those steps to lead elsewhere first, then remove it.';
}

/**
 * services/WorkflowStepReorderService.php AFTER_STEP_MISSING: a Position
 * after a step that was removed meanwhile.
 */
export function afterStepMissing(stepId: number | string): string {
  return `The step to place it after (step ID ${stepId}) is not part of this workflow. `
    + 'Load the workflow again and choose another position.';
}

/** The refusal prefixes of WorkflowTemplateController. */
export const STEP_NOT_ADDED = 'Step not added: ';
export const STEP_NOT_MOVED = 'Step not moved: ';
export const STEP_NOT_SAVED = 'Step not saved: ';

/**
 * The warning flashes of the open page, in the order they were set. A flash
 * is dismissible: the run page's banner and the job page's notes are
 * warning or info alerts too, but no flashes.
 */
export const WARNING_FLASH = '#page-content > .alert-warning.alert-dismissible';

/** Asserts that the open page shows exactly one warning flash `text`. */
export async function expectWarningFlash(page: Page, text: string): Promise<void> {
  const flash = page.locator(WARNING_FLASH).filter({ hasText: text });
  await expect(flash, `a warning flash: ${text}`).toHaveCount(1);
  await expect(flash).toHaveText(text);
}

/** The "#" (position) cells' numbers of the steps table of the open workflow template page, in table order. */
export function templateStepPositions(page: Page): Locator {
  return page.locator('#workflow-steps-table tbody tr [data-position]');
}

/** The Name cells of the steps table of the open workflow template page, in table order. */
export function templateStepNames(page: Page): Locator {
  return page.locator('#workflow-steps-table tbody tr td:nth-child(2)');
}

/**
 * The type badge of a row of the steps table: the Type cell can also hold
 * the "needs approval" badge and how many runs wait at the step.
 */
export function typeBadge(row: Locator): Locator {
  return row.locator('td:nth-child(3) > .badge:first-child');
}

/** The route cell `route` (success, failure, always) of a row of the steps table. */
export function routeCell(row: Locator, route: 'success' | 'failure' | 'always'): Locator {
  return row.locator(`td[data-route="${route}"]`);
}

/** The ID of the step named `name` of the open workflow template page. */
export async function templateStepId(page: Page, name: string): Promise<string> {
  const id = (await templateStepRow(page, name).getAttribute('data-step-id')) ?? '';
  expect(id, `the ID of step ${name}`).toMatch(/^\d+$/);
  return id;
}

/** A step for addStepThroughForm(). */
export interface FormStep {
  name: string;
  type: 'job' | 'approval' | 'pause';
  /** The label of the job template of a job step, or of the approval rule of an approval step. */
  target?: string;
  /** The Position to choose, by its value or label; left out, the form's default (the end). */
  position?: string;
  /** The On Failure choice of a job step, by its label; left out, the form's default (the end of the workflow). */
  onFailure?: string;
}

/**
 * Adds `step` through the add-step form of the open workflow template page,
 * which shows the workflow again afterwards, at the new step, with the
 * success flash. Returns the new step's ID.
 */
export async function addStepThroughForm(page: Page, step: FormStep): Promise<string> {
  await page.locator('#workflowstep-name').fill(step.name);
  await page.locator('#ws-step-type').selectOption(step.type);
  if (step.position !== undefined) {
    await page.locator(POSITION_SELECT).selectOption(step.position);
  }
  if (step.type === 'job') {
    await page.locator('#workflowstep-job_template_id').selectOption({ label: step.target ?? '' });
    if (step.onFailure !== undefined) {
      await page.locator('#workflowstep-on_failure_step_id').selectOption({ label: step.onFailure });
    }
  } else if (step.type === 'approval') {
    await page.locator('#workflowstep-approval_rule_id').selectOption({ label: step.target ?? '' });
  }
  await submitForm(page, 'Add Step');
  // The form was sent: no field, hidden or not, refused it.
  expect(await addStepFormErrors(page)).toEqual([]);
  await expectFlash(page, 'success', `Step "${step.name}" added at position `);
  const id = await templateStepId(page, step.name);
  await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=\\d+#step-${id}$`));
  return id;
}

/** What editStepThroughForm() changes; every choice by its label. */
export interface StepEdit {
  name?: string;
  /** The job template of a job step, or the approval rule of an approval step. */
  target?: string;
  position?: string;
  onSuccess?: string;
  onFailure?: string;
  always?: string;
}

/**
 * Opens the edit page of the step named `name` of the open workflow template
 * page with its Edit button, changes what `edit` names, and saves. The
 * template page shows the step again afterwards (#step-<id>) with the
 * success flash, which is returned.
 */
export async function editStepThroughForm(page: Page, name: string, edit: StepEdit): Promise<string> {
  const id = await templateStepId(page, name);
  await templateStepRow(page, name).locator('a[data-action="edit"]').click();
  await expect(page).toHaveURL(new RegExp(`/workflow-template/edit-step\\?id=\\d+&step=${id}$`));
  await fillStepForm(page, edit);
  await submitForm(page, 'Save Step');
  await expect(page).toHaveURL(new RegExp(`/workflow-template/view\\?id=\\d+#step-${id}$`));
  const flash = page.locator('#page-content > .alert-success.alert-dismissible, #page-content > .alert-info.alert-dismissible');
  await expect(flash).toHaveCount(1);
  return ((await flash.textContent()) ?? '').trim();
}

/** Fills what `edit` names into the step form of the open page. */
export async function fillStepForm(page: Page, edit: StepEdit): Promise<void> {
  if (edit.name !== undefined) {
    await page.locator('#workflowstep-name').fill(edit.name);
  }
  if (edit.target !== undefined) {
    const target = page.locator('#workflowstep-job_template_id:enabled, #workflowstep-approval_rule_id:enabled');
    await target.selectOption({ label: edit.target });
  }
  if (edit.position !== undefined) {
    await page.locator(POSITION_SELECT).selectOption(edit.position);
  }
  const routes: [string | undefined, string][] = [
    [edit.onSuccess, '#workflowstep-on_success_step_id'],
    [edit.onFailure, '#workflowstep-on_failure_step_id'],
    [edit.always, '#workflowstep-on_always_step_id'],
  ];
  for (const [label, select] of routes) {
    if (label !== undefined) {
      await page.locator(select).selectOption({ label });
    }
  }
}

/**
 * Moves the step named `name` of the open workflow template page with its
 * Move to… menu: opens it and clicks the choice `itemLabel` ("To the start",
 * "After 2. Build", "To the end"). The page shows the step again afterwards
 * (#step-<id>).
 */
export async function moveStepTo(page: Page, name: string, itemLabel: string): Promise<void> {
  const id = await templateStepId(page, name);
  const menu = templateStepRow(page, name).locator('details[data-action="move-to"]');
  // A click on the summary opens the menu, and closes it when it is open.
  if (!(await menu.evaluate((details) => (details as HTMLDetailsElement).open))) {
    await menu.locator('summary').click();
  }
  const item = menu.locator('button[name="position"]', { hasText: exactly(itemLabel) });
  await expect(item).toBeVisible();
  await Promise.all([
    page.waitForURL(new RegExp(`/workflow-template/view\\?id=\\d+#step-${id}$`)),
    item.click(),
  ]);
}

/** Removes the step named `name` with its Remove button on the open workflow template page. */
export async function removeTemplateStep(page: Page, name: string): Promise<void> {
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-template\/view\?id=\d+#workflow-steps$/),
    templateStepRow(page, name).locator('button[data-action="remove"]').click(),
  ]);
  await expectFlash(page, 'success', `Step "${name}" removed; it was at position `);
  await expect(templateStepRow(page, name)).toHaveCount(0);
}

/**
 * Runs `use`, which adds the steps `names` on the open workflow template
 * page, then removes those the page lists, so a seeded workflow is left as
 * it was for the other tests on it. When `use` fails, the removal is tried
 * on the page as it is, and the failure is thrown on.
 */
export async function removingSteps(page: Page, names: string[], use: () => Promise<void>): Promise<void> {
  const removeListed = async (): Promise<void> => {
    for (const name of names) {
      if (await templateStepRow(page, name).count() > 0) {
        await removeTemplateStep(page, name);
      }
    }
  };
  try {
    await use();
  } catch (error) {
    await removeListed().catch(() => undefined);
    throw error;
  }
  await removeListed();
}

/** Creates the workflow template `name` through the form; its ID. Its page, without steps, is open then. */
export async function createWorkflow(page: Page, name: string): Promise<string> {
  await page.goto('/workflow-template/create');
  await page.locator('#workflowtemplate-name').fill(name);
  await submitForm(page);
  await expectFlash(page, 'success', `Workflow template "${name}" created.`);
  await expect(page).toHaveURL(/\/workflow-template\/view\?id=\d+$/);
  await expect(page.locator('#wf-no-steps')).toHaveText('No steps defined yet.');
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** Deletes the workflow template #`id` named `name` from its page; that cancels its active runs. */
export async function deleteWorkflow(page: Page, id: string, name: string): Promise<void> {
  await page.goto(`/workflow-template/view?id=${id}`);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-template\/index/),
    page.locator('#wf-delete-form button[type="submit"]').click(),
  ]);
  await expectFlash(page, 'success', `Workflow template "${name}" deleted.`);
}

/**
 * Runs `use` on the new workflow template `name`, whose page is open then,
 * and deletes it afterwards, which cancels the runs `use` left. When `use`
 * fails, the workflow is still deleted if that works; the E2E teardown
 * removes what is left.
 */
export async function withNewWorkflow(page: Page, name: string, use: (id: string) => Promise<void>): Promise<void> {
  const id = await createWorkflow(page, name);
  try {
    await use(id);
  } catch (error) {
    await deleteWorkflow(page, id, name).catch(() => undefined);
    throw error;
  }
  await deleteWorkflow(page, id, name);
}

/** Columns of the run index: #, Workflow, Status, Current Step, Launched By, Started, Finished. */
const RUN_INDEX = '/workflow-job/index';
const RUN_WORKFLOW = 1;

/** A regular expression that matches exactly `text`. */
export function exactly(text: string): RegExp {
  return new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`);
}

/** The row of the step named `name` in the steps table of the open workflow template page. */
export function templateStepRow(page: Page, name: string): Locator {
  return page.locator('#workflow-steps-table tbody tr').filter({ has: page.locator('td:nth-child(2)', { hasText: exactly(name) }) });
}

/** The row of the step named `name` in the steps table of the open run page. */
export function runStepRow(page: Page, name: string): Locator {
  return page.locator('#wj-steps-table tbody tr').filter({ has: page.locator('[data-wjs-name-cell]', { hasText: exactly(name) }) });
}

/**
 * The validation errors the add-step form of the open page shows, also those
 * of hidden fields, which keep the form from being sent as well.
 */
export async function addStepFormErrors(page: Page): Promise<string[]> {
  const errors = await page.locator('#add-step-form .help-block, #add-step-form .invalid-feedback').allTextContents();
  return errors.map((error) => error.trim()).filter((error) => error !== '');
}

/** The ID of the newest run of the workflow named `workflow`, from the run index. */
export async function newestRunId(page: Page, workflow: string): Promise<string> {
  const rows = rowsWhere(await indexRows(page, RUN_INDEX), RUN_WORKFLOW, workflow);
  expect(rows.length, `a run of ${workflow}`).toBeGreaterThan(0);
  return rows[0].id;
}

/** How many runs of the workflow named `workflow` the run index lists. */
export async function runCount(page: Page, workflow: string): Promise<number> {
  return rowsWhere(await indexRows(page, RUN_INDEX), RUN_WORKFLOW, workflow).length;
}

/**
 * The ID of the job a step row of the open run page links to: the job link
 * only, as the cell also links the approval request of a step that waits
 * for one ("Request #R").
 */
export async function stepJobId(row: Locator): Promise<string> {
  const link = row.locator('[data-wjs-job-link]');
  await expect(link).toHaveText(/^#\d+$/);
  return ((await link.textContent()) ?? '').trim().slice(1);
}

/** The ID of the run the active-runs notice of the open workflow template page links to; the notice names exactly one. */
export async function activeRunId(page: Page): Promise<string> {
  const links = page.locator('#wf-active-runs-notice a');
  await expect(links).toHaveCount(1);
  const href = (await links.getAttribute('href')) ?? '';
  const id = href.match(/\/workflow-job\/view\?id=(\d+)$/)?.[1];
  expect(id, `the notice links the run page: ${href}`).toBeTruthy();
  return id ?? '';
}
