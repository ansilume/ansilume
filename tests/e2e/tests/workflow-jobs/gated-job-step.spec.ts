import { test, expect, Browser, Page, TestInfo } from '@playwright/test';
import { newestJobId, templateId } from '../../lib/helpers';
import { idWhere, indexRows } from '../../lib/scoping';
import { E2E_OPERATOR, asUser } from '../../lib/session';
import { openWorkflowTemplate } from '../../lib/triggers';
import {
  GATED_JOB_AFTER,
  GATED_JOB_STEP,
  GATED_JOB_TEMPLATE,
  GATED_JOB_WORKFLOW,
  runStepRow,
  stepJobId,
} from '../../lib/workflows';

/**
 * A job step whose job template has an approval rule waits for the approval,
 * then for its job: once approved, the job is queued and the step keeps
 * running until the job ends; it does not succeed before the job ran. A
 * rejection fails closed: the job never runs, the step fails with why, and
 * the run ends as failed instead of going on with the next step.
 *
 * Fixture: e2e-gated-job-workflow (commands/E2eWorkflowGateSeeder.php), the
 * job step e2e-gated-job-step on e2e-gated-job-template, which e2e-gate-rule
 * gates, then the pause step e2e-gated-job-after. e2e-admin launches;
 * e2e-operator, the rule's other approver, decides. No runner serves the
 * template's runner group, so an approved job stays queued.
 */
const LAUNCH_BUTTON = '#page-content form[action*="/workflow-template/launch"] button';

/** Launches the workflow as e2e-admin; the run and the job of its gated job step, which waits for approval. */
async function launch(page: Page): Promise<{ runId: string; jobId: string }> {
  await openWorkflowTemplate(page, GATED_JOB_WORKFLOW);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
    page.locator(LAUNCH_BUTTON).click(),
  ]);
  const runId = new URL(page.url()).searchParams.get('id') ?? '';
  const step = runStepRow(page, GATED_JOB_STEP);
  await expect(step.locator('[data-wjs-type-cell]')).toHaveText('Job');
  await expect(step.locator('[data-wjs-status-cell] .badge')).toHaveText('Running');
  const jobId = await stepJobId(step);
  expect(await newestJobId(page, await templateId(page, GATED_JOB_TEMPLATE), 'pending_approval')).toBe(jobId);
  return { runId, jobId };
}

/** e2e-operator decides on the approval request of the job; the request's ID. */
async function decide(browser: Browser, testInfo: TestInfo, page: Page, jobId: string, decision: 'approve' | 'reject'): Promise<string> {
  // Columns of the request index: #, Job, Rule, Status, ...
  const requestId = idWhere(await indexRows(page, '/approval/index'), 1, `#${jobId}`);
  await asUser(browser, testInfo, E2E_OPERATOR, async (operator) => {
    await operator.goto(`/approval/view?id=${requestId}`);
    await expect(operator.locator('#approval-rule')).toHaveText('e2e-gate-rule');
    operator.once('dialog', (dialog) => dialog.accept());
    await operator.locator(`#approval-${decision}-form button[type="submit"]`).click();
    await expect(operator.locator('.alert-success').first()).toBeVisible();
  });
  return requestId;
}

test.describe('Gated job step', () => {
  // Regression: approving the job of a gated job step marked it succeeded
  // without running it, and the run went on.
  test('an approved job is queued, and its step waits for it', async ({ page, browser }, testInfo) => {
    const { runId, jobId } = await launch(page);

    await decide(browser, testInfo, page, jobId, 'approve');

    expect(await newestJobId(page, await templateId(page, GATED_JOB_TEMPLATE), 'queued')).toBe(jobId);
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Running');
    const step = runStepRow(page, GATED_JOB_STEP);
    await expect(step.locator('[data-wjs-status-cell] .badge')).toHaveText('Running');
    await expect(step.locator('[data-wjs-error]')).toHaveCount(0);
    await expect(step.locator('[data-wjs-job-cell]')).toHaveText(`#${jobId}`);
    await expect(runStepRow(page, GATED_JOB_AFTER), 'the next step waits for the job').toHaveCount(0);
  });

  // Regression: a gated job step whose approval was rejected continued the workflow.
  test('a rejected job never runs, and the run ends as failed', async ({ page, browser }, testInfo) => {
    const { runId, jobId } = await launch(page);

    const requestId = await decide(browser, testInfo, page, jobId, 'reject');

    expect(await newestJobId(page, await templateId(page, GATED_JOB_TEMPLATE), 'rejected')).toBe(jobId);
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(page.locator('#wj-status')).toHaveText('Failed');
    await expect(page.locator('#wj-live')).toHaveCount(0);
    const step = runStepRow(page, GATED_JOB_STEP);
    await expect(step.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
    await expect(step.locator('[data-wjs-error]')).toHaveText(`Not run: approval request #${requestId} was rejected.`);
    await expect(runStepRow(page, GATED_JOB_AFTER), 'the next step never starts').toHaveCount(0);
  });
});
