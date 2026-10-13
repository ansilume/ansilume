import { Locator, Page, expect } from '@playwright/test';
import { indexRows, rowsWhere } from './scoping';

/**
 * Fixtures of commands/E2eWorkflowGateSeeder.php, restored by every seed,
 * and helpers for the workflow template and run pages.
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
 * Only admins (approval-rule.update) add approval steps and job steps whose
 * job template has an approval rule ("gated job steps"), and change the steps
 * of a workflow that has either (services/ApprovalRuleAssignmentPolicy.php).
 */
export const APPROVAL_STEP_ADD_REFUSED = 'Only admins may add approval steps: that needs approval-rule.update.';
export const APPROVAL_WORKFLOW_STEPS_REFUSED = 'This workflow has an approval step: only admins may change its steps (approval-rule.update).';
export const GATED_JOB_STEP_ADD_REFUSED = 'Only admins may add a job step whose job template has an approval rule: '
  + 'that needs approval-rule.update.';
export const GATED_JOB_WORKFLOW_STEPS_REFUSED = 'This workflow has a job step whose job template has an approval rule: '
  + 'only admins may change its steps (approval-rule.update).';

/**
 * services/WorkflowTemplatePage.php GATED_TEMPLATES_HINT: the hint under the
 * job template select of the add-step form, for users who may not add gated
 * job steps.
 */
export const GATED_TEMPLATES_HINT = 'Job templates with an approval rule are not listed: only admins may add a job step for them.';

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
 * The labels of the routes in the steps table of a workflow template page
 * (services/WorkflowTemplatePage.php): an empty route goes on with the next
 * step (ROUTE_NEXT_STEP); "end" ends the run (ROUTE_END); the empty On
 * Failure route of an approval step ends the run as failed (ROUTE_END_FAILED).
 */
export const ROUTE_NEXT_STEP = '→ next step';
export const ROUTE_END = 'end workflow';
export const ROUTE_END_FAILED = 'end workflow (failed)';

/**
 * services/WorkflowTemplatePage.php GATED_JOB_FAILURE_ROUTE and
 * GATED_JOB_FAILURE_TITLE: the empty On Failure route of a job step whose job
 * template has an approval rule. A job that ran and failed goes on with the
 * next step; after a rejected, timed-out or canceled approval, when the job
 * never ran, the run ends as failed. Its hover text says so in full.
 */
export const GATED_JOB_FAILURE_ROUTE = `${ROUTE_NEXT_STEP}; ${ROUTE_END_FAILED} after a refused approval`;
export const GATED_JOB_FAILURE_TITLE = 'After a rejected, timed-out or canceled approval, when the job never ran. '
  + 'A job that ran and failed goes on with the next step.';

/** Columns of the run index: #, Workflow, Status, Launched By, Started, Finished. */
const RUN_INDEX = '/workflow-job/index';
const RUN_WORKFLOW = 1;

/** A regular expression that matches exactly `text`. */
function exactly(text: string): RegExp {
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

/** The ID of the job a step row of the open run page links to. */
export async function stepJobId(row: Locator): Promise<string> {
  const link = row.locator('[data-wjs-job-cell] a');
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
