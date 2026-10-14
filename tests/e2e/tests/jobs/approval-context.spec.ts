import { test, expect, Page } from '@playwright/test';
import { asOperator, decide, flash, launchTemplate, requestJobId, waitingForApproval } from '../../lib/approvals';
import {
  BETA_APPROVAL_RULE,
  BETA_APPROVAL_STEP,
  BETA_APPROVAL_WORKFLOW,
  MIXED_GATED_STEP,
  MIXED_WORKFLOW,
  NEVER_EXPIRES,
  PLACEHOLDER_TEMPLATE,
  WAITING_SINCE,
  cancelJobIfActive,
  expectNotReloaded,
  factApprovals,
  factRule,
  jobApprovalNote,
  jobWorkflowOrigin,
  markPage,
  onlyRunId,
  placeholderLabel,
  placeholderNote,
  placeholderSubtitle,
  requestLabel,
  stepRequestId,
} from '../../lib/runs';
import { GATED_JOB_TEMPLATE, GATE_RULE, runStepRow } from '../../lib/workflows';

/**
 * The job page's workflow and approval context (views/job/view.php,
 * _workflow-context.php): the approval request a job waits for, the run and
 * step it was created for, and the placeholder that holds an approval step's
 * request.
 *
 * e2e-admin launches e2e-gated-job-template, which e2e-gate-rule gates
 * (e2e-admin and e2e-operator approve) and whose runner group no runner
 * serves, so the approved job stays queued; e2e-operator decides, and the
 * job is canceled afterwards. The seeded waiting runs of e2e-mixed-gated-wf
 * (commands/E2eWorkflowWaitSeeder.php) and e2e-beta-approval-wf
 * (E2eWorkflowScopingSeeder.php) are only looked at.
 */

/**
 * The row of job #`jobId` on the job list `url`, newest first: the page that
 * lists it is open then.
 */
async function jobListRow(page: Page, url: string, jobId: string, maxPages = 10) {
  for (let pageNo = 1; pageNo <= maxPages; pageNo++) {
    await page.goto(`${url}&page=${pageNo}`);
    const row = page.locator('#page-content table.table tbody tr').filter({ has: page.locator(`a[href$="/job/view?id=${jobId}"]`) });
    if (await row.count() === 1) {
      return row;
    }
  }
  throw new Error(`Job #${jobId} is not on the first ${maxPages} pages of ${url}`);
}

/** The job the running test launched; afterEach cancels it unless it has finished. */
let launched = '';

/** The Details rows of the open job page: Template, Status, Approval Request, Workflow Job. */
function detail(page: Page, id: 'template' | 'status' | 'approval' | 'workflow-job') {
  return id === 'template'
    ? page.locator('dt', { hasText: /^Template$/ }).locator('xpath=following-sibling::dd[1]')
    : page.locator(`#detail-${id}`);
}

test.describe('Job pages in their workflow and approval context', () => {
  test.afterEach(async ({ page }) => {
    await cancelJobIfActive(page, launched);
    launched = '';
  });

  // regression: #detail-status kept "Awaiting Approval" after the job was
  // approved: the page's poll wrote only the status badge (git show
  // HEAD:views/job/view.php: #detail-status is rendered once, the script sets
  // badgeEl only; tests/integration/controllers/JobViewStatusPollTest.php
  // testThePollWritesTheDetailsStatusAndHidesTheApprovalNote).
  test('regression: #detail-status kept "Awaiting Approval" after the job was approved', async ({ page, browser }, testInfo) => {
    launched = await launchTemplate(page, GATED_JOB_TEMPLATE);
    await expect(flash(page, 'info')).toHaveText(waitingForApproval(launched));
    await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
    await expect(detail(page, 'status')).toHaveText('Awaiting Approval');

    // The note names the request and its rule, with the Review link.
    const note = page.locator('#job-approval-note');
    const review = note.locator('#job-approval-review');
    await expect(review).toHaveText('Review');
    const requestId = ((await review.getAttribute('href')) ?? '').match(/\/approval\/view\?id=(\d+)$/)?.[1] ?? '';
    expect(requestId, 'the note links the request').toMatch(/^\d+$/);
    await expect(note.locator('.fw-semibold')).toHaveText(jobApprovalNote(requestId));
    await expect(note.locator('li')).toHaveText([factRule(GATE_RULE), factApprovals(0, 1), WAITING_SINCE, NEVER_EXPIRES]);
    await expect(detail(page, 'approval')).toHaveText(`${requestLabel(requestId)} · Pending`);
    await expect(detail(page, 'approval').locator('a')).toHaveAttribute('href', `/approval/view?id=${requestId}`);
    // No workflow run created it.
    await expect(detail(page, 'workflow-job')).toHaveCount(0);
    await markPage(page);

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await decide(operator, 'approve');
      await expect(flash(operator, 'success')).toHaveText('Approval recorded.');
    });

    // The open page follows its poll: approved, the job waits for a runner,
    // which none serves. While the job waits and nothing changes, the page
    // polls less and less often (2.5, 5, 10, 20, then every 30 seconds), so
    // the change can take up to 30 seconds to show.
    testInfo.setTimeout(testInfo.timeout + 35_000);
    await expect(page.locator('#status-badge')).toHaveText('Queued', { timeout: 35_000 });
    await expect(detail(page, 'status')).toHaveText('Queued');
    await expect(note).toBeHidden();
    await expectNotReloaded(page);
  });

  test('a gated job step\'s job names its run and step', async ({ page }) => {
    const runId = await onlyRunId(page, MIXED_WORKFLOW);
    await page.goto(`/workflow-job/view?id=${runId}`);
    const step = runStepRow(page, MIXED_GATED_STEP);
    const requestId = await stepRequestId(step);
    await step.locator('[data-wjs-job-link]').click();
    await expect(page).toHaveURL(/\/job\/view\?id=\d+$/);

    await expect(page.locator('#job-approval-note .fw-semibold')).toHaveText(jobApprovalNote(requestId));
    await expect(detail(page, 'approval')).toHaveText(`${requestLabel(requestId)} · Pending`);
    const origin = detail(page, 'workflow-job');
    await expect(origin).toHaveText(jobWorkflowOrigin(runId, MIXED_GATED_STEP));
    await origin.locator('a').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-job/view\\?id=${runId}$`));
  });

  test('the placeholder of an approval step explains itself instead of an empty log', async ({ page }) => {
    const runId = await onlyRunId(page, BETA_APPROVAL_WORKFLOW);
    await page.goto(`/workflow-job/view?id=${runId}`);
    const requestId = await stepRequestId(runStepRow(page, BETA_APPROVAL_STEP));
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-rule')).toHaveText(BETA_APPROVAL_RULE);
    const jobId = await requestJobId(page);

    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#page-content h2 + .text-muted')).toContainText(placeholderSubtitle(BETA_APPROVAL_STEP, runId));
    await expect(detail(page, 'template')).toHaveText(PLACEHOLDER_TEMPLATE);
    await expect(page.locator('#job-placeholder-note')).toHaveText(placeholderNote(BETA_APPROVAL_STEP, runId));
    // It never runs, so it has no output.
    await expect(page.locator('#job-log')).toHaveCount(0);
    await expect(detail(page, 'approval')).toHaveText(`${requestLabel(requestId)} · Pending`);
    await expect(detail(page, 'workflow-job')).toHaveText(jobWorkflowOrigin(runId, BETA_APPROVAL_STEP));

    // The jobs list names it by its step and run (Template column).
    const row = await jobListRow(page, '/job/index?status=pending_approval', jobId);
    await expect(row.locator('td').nth(2)).toHaveText(placeholderLabel(BETA_APPROVAL_STEP, runId));
  });
});
