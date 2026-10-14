import { test, expect, Browser, Locator, Page, TestInfo } from '@playwright/test';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  LAUNCHER_REFUSED_RUN,
  asOperator,
  decide,
  decisionForms,
  flash,
  requestStatus,
} from '../../lib/approvals';
import { AWAITING_APPROVAL, cancelRunIfActive, stepBadge, stepRequestId } from '../../lib/runs';

/**
 * Workflow approval steps: the run waits at the step for an approval
 * request, and the request's outcome decides where the run goes (regression
 * tests for issue #14 and for gates that fail closed).
 *
 * e2e-admin starts the runs. The launcher never decides on their own run, so
 * e2e-operator, the rules' other approver, decides. Every test cancels the
 * run it started unless it has finished: no run is left waiting.
 * - e2e-approval-workflow (commands/E2eController.php): the approval step
 *   e2e-step-approve on e2e-approval-rule, On Failure: end the workflow, then
 *   the job step e2e-step-job-after-approval.
 * - e2e-gate-continue-workflow (commands/E2eWorkflowGateSeeder.php): the
 *   approval step e2e-gate-continue-approve on e2e-gate-rule WITHOUT an On
 *   Failure branch, then the job step e2e-gate-continue-job.
 */

/** Launches the workflow template `name`; the ID of the run, whose page is open then. */
async function launchWorkflow(page: Page, name: string): Promise<string> {
  await openWorkflowTemplate(page, name);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-job\/view\?id=\d+/),
    page.locator('#page-content form[action*="/workflow-template/launch"] button[type="submit"]').click(),
  ]);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** The execution rows of the open run page. */
function stepRows(page: Page): Locator {
  return page.locator('#wj-steps-table tbody tr[data-wjs-id]');
}

/** The execution row of step `name` on the open run page. */
function stepRow(page: Page, name: string): Locator {
  return stepRows(page).filter({ has: page.locator('[data-wjs-name-cell]', { hasText: name }) });
}

/**
 * Opens run #`runId`, whose approval step `step` waits; the ID of the step's
 * approval request, which the step names instead of its placeholder job.
 */
async function waitingRequest(page: Page, runId: string, step: string): Promise<string> {
  await page.goto(`/workflow-job/view?id=${runId}`);
  const row = stepRow(page, step);
  await expect(row.locator('[data-wjs-type-cell]')).toHaveText('Approval');
  await expect(stepBadge(row)).toHaveText(AWAITING_APPROVAL);
  await expect(row.locator('[data-wjs-job-link]'), 'the placeholder job is not linked').toHaveCount(0);
  return stepRequestId(row);
}

/** e2e-operator approves or rejects request #`requestId`. */
async function operatorDecides(browser: Browser, testInfo: TestInfo, requestId: string, decision: 'approve' | 'reject'): Promise<void> {
  await asOperator(browser, testInfo, async (operator) => {
    await operator.goto(`/approval/view?id=${requestId}`);
    await decide(operator, decision);
    await expect(flash(operator, 'success')).toHaveText(decision === 'approve' ? 'Approval recorded.' : 'Rejection recorded.');
    await expect(requestStatus(operator)).toHaveText(decision === 'approve' ? 'Approved' : 'Rejected');
  });
}

/** GET /workflow-job/status of run #`runId`, as the page polls it. */
async function runStatus(page: Page, runId: string): Promise<{ status: string; is_finished: boolean; steps: { step_name: string; status: string }[] }> {
  const response = await page.request.get(`/workflow-job/status?id=${runId}`);
  expect(response.status()).toBe(200);
  return response.json();
}

test.describe('Workflow Approval Flow', () => {
  /** The run the test started; the test after it does not find it waiting. */
  let runId = '';

  test.afterEach(async ({ page }) => {
    await cancelRunIfActive(page, runId);
    runId = '';
  });

  test('a run waits at its approval step for a request its launcher cannot decide', async ({ page }) => {
    runId = await launchWorkflow(page, 'e2e-approval-workflow');
    await expect(page.locator('#wj-status')).toHaveText(AWAITING_APPROVAL);
    await expect(stepRows(page)).toHaveCount(1);
    const requestId = await waitingRequest(page, runId, 'e2e-step-approve');

    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Pending');
    await expect(page.locator('#approval-launched-by')).toHaveText('e2e-admin');
    // Regression: the launcher of a workflow run read "You launched this job".
    await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED_RUN);
    await expect(decisionForms(page)).toHaveCount(0);

    // Canceling the run closes the request with it.
    await page.goto(`/workflow-job/view?id=${runId}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#page-content form[action*="/workflow-job/cancel"] button[type="submit"]').click();
    await expect(flash(page, 'success')).toHaveText('Workflow canceled.');
    await expect(page.locator('#wj-status')).toHaveText('Canceled');
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Canceled');
    await expect(decisionForms(page)).toHaveCount(0);
  });

  test('approving the request advances the run to the next step', async ({ page, browser }, testInfo) => {
    runId = await launchWorkflow(page, 'e2e-approval-workflow');
    const requestId = await waitingRequest(page, runId, 'e2e-step-approve');

    await operatorDecides(browser, testInfo, requestId, 'approve');

    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(stepRow(page, 'e2e-step-approve').locator('[data-wjs-status-cell] .badge')).toHaveText('Succeeded');
    // The job step runs now (or already ran, if a runner was quick).
    const next = stepRow(page, 'e2e-step-job-after-approval');
    await expect(next).toHaveCount(1);
    await expect(next.locator('[data-wjs-type-cell]')).toHaveText('Job');
    await expect(next.locator('[data-wjs-job-link]')).toHaveText(/^#\d+$/);
  });

  test('rejecting the request fails the run, and the step names the request', async ({ page, browser }, testInfo) => {
    runId = await launchWorkflow(page, 'e2e-approval-workflow');
    const requestId = await waitingRequest(page, runId, 'e2e-step-approve');

    await operatorDecides(browser, testInfo, requestId, 'reject');

    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Failed');
    const approval = stepRow(page, 'e2e-step-approve');
    await expect(approval.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
    await expect(approval.locator('[data-wjs-error]')).toHaveText(`Approval request #${requestId} was rejected.`);
    await expect(stepRows(page)).toHaveCount(1);
  });

  // Regression: a rejected approval step continued the workflow with the next step.
  test('a rejection without an On Failure branch ends the run as failed, and the next step never starts', async ({ page, browser }, testInfo) => {
    runId = await launchWorkflow(page, 'e2e-gate-continue-workflow');
    const requestId = await waitingRequest(page, runId, 'e2e-gate-continue-approve');

    await operatorDecides(browser, testInfo, requestId, 'reject');

    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Failed');
    await expect(page.locator('#wj-finished')).not.toHaveText('—');
    const approval = stepRow(page, 'e2e-gate-continue-approve');
    await expect(approval.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
    await expect(approval.locator('[data-wjs-error]')).toHaveText(`Approval request #${requestId} was rejected.`);
    await expect(stepRows(page)).toHaveCount(1);
    await expect(stepRow(page, 'e2e-gate-continue-job')).toHaveCount(0);

    // The run is over: nothing starts the job step later either.
    const status = await runStatus(page, runId);
    expect(status.status).toBe('failed');
    expect(status.is_finished).toBe(true);
    expect(status.steps.map((step) => [step.step_name, step.status])).toEqual([['e2e-gate-continue-approve', 'failed']]);
  });
});
