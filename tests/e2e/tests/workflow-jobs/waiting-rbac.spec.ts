import { test, expect, Page } from '@playwright/test';
import { REQUEST_FOR_COLUMN, REQUEST_JOB_COLUMN, REQUEST_RULE_COLUMN, decide, decisionForms, flash } from '../../lib/approvals';
import { expectForbidden } from '../../lib/helpers';
import {
  ALPHA_GATED_TEMPLATE,
  ALPHA_GATE_RULE,
  AWAITING_APPROVAL,
  BETA_APPROVAL_STEP,
  BETA_APPROVAL_WORKFLOW,
  MIXED_WORKFLOW,
  PERSONAL_LAUNCHER,
  PERSONAL_MAY_DECIDE,
  PERSONAL_NOT_ELIGIBLE,
  QUEUED,
  REVIEW_BUTTON,
  RUN_WORKFLOW_COLUMN,
  WARNING_UNDECIDABLE,
  bannerApprovalStep,
  bannerFacts,
  bannerGatedJob,
  bannerPersonal,
  bannerQueued,
  bannerRequest,
  bannerRequestId,
  bannerTitle,
  bannerWarning,
  cancelRunIfActive,
  expectNotReloaded,
  factOpenApprovers,
  factRequest,
  forJobTemplate,
  launchWorkflow,
  markPage,
  onlyRunId,
  requestLabel,
  reviewLink,
  runState,
  stepBadge,
  stepRequestId,
} from '../../lib/runs';
import { IndexRow, asAdmin, indexRows, rowsWhere } from '../../lib/scoping';
import { GATED_JOB_STEP, GATED_JOB_WORKFLOW, runStepRow, stepJobId } from '../../lib/workflows';

/**
 * What waiting runs show to operators and viewers, by what they may do.
 *
 * - e2e-operator approves on e2e-gate-rule, which gates the job step of
 *   e2e-gated-job-workflow (commands/E2eWorkflowGateSeeder.php): on a run
 *   e2e-admin launched, the banner offers them Review & decide, and the
 *   launcher's open page follows the decision. The run is canceled afterwards.
 * - e2e-viewer, the watcher (a viewer of team-beta), is on the approver list
 *   of e2e-beta-approval-rule but may not decide, and e2e-operator, its other
 *   approver, may not see the request (E2eWorkflowScopingSeeder.php): nobody
 *   can decide the request the run of e2e-beta-approval-wf waits for.
 * - e2e-mixed-gated-wf (E2eWorkflowWaitSeeder.php) has a job step on
 *   e2e-alpha-gated-tmpl, which e2e-operator's team operates, and one on
 *   e2e-beta-tmpl: e2e-operator sees the request of its waiting job through
 *   the job template, and nothing of the workflow; e2e-viewer sees neither.
 */

/** The pending requests on the rule `rule` that the user of `page` sees, from every page of the list. */
async function pendingRequestsOn(page: Page, rule: string): Promise<IndexRow[]> {
  return rowsWhere(await indexRows(page, '/approval/index?status=pending'), REQUEST_RULE_COLUMN, rule);
}

/** The IDs of the seeded mixed run, its waiting job and its request, as e2e-admin sees them. */
async function mixedWait(admin: Page): Promise<{ runId: string; jobId: string; requestId: string }> {
  const runId = await onlyRunId(admin, MIXED_WORKFLOW);
  const requests = await pendingRequestsOn(admin, ALPHA_GATE_RULE);
  expect(requests, `one pending request on ${ALPHA_GATE_RULE}`).toHaveLength(1);
  return { runId, jobId: requests[0].cells[REQUEST_JOB_COLUMN].replace('#', ''), requestId: requests[0].id };
}

test.describe('Waiting runs by role', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  // -- Operator --------------------------------------------------------------------

  test('operator decides through Review & decide, and the launcher\'s open page moves on', async ({ page, browser }, testInfo) => {
    await asAdmin(browser, testInfo, async (admin) => {
      let runId = '';
      try {
        runId = await launchWorkflow(admin, GATED_JOB_WORKFLOW);
        const adminStep = runStepRow(admin, GATED_JOB_STEP);
        const jobId = await stepJobId(adminStep);
        await expect(bannerPersonal(admin)).toHaveText(PERSONAL_LAUNCHER);
        await expect(reviewLink(admin)).toHaveCount(0);
        await markPage(admin);

        await page.goto(`/workflow-job/view?id=${runId}`);
        await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
        await expect(bannerTitle(page)).toHaveText(bannerGatedJob(jobId, GATED_JOB_STEP));
        await expect(bannerPersonal(page)).toHaveText(PERSONAL_MAY_DECIDE);
        const requestId = await bannerRequestId(page);
        expect(requestId).toBe(await stepRequestId(runStepRow(page, GATED_JOB_STEP)));
        await expect(reviewLink(page)).toHaveText(REVIEW_BUTTON);
        await reviewLink(page).click();
        await expect(page).toHaveURL(new RegExp(`/approval/view\\?id=${requestId}$`));
        await decide(page, 'approve');
        await expect(flash(page, 'success')).toHaveText('Approval recorded.');

        // The launcher's page, open all along, follows the poll. While nothing
        // changes it polls less and less often (3, 6, 12, 24, then every 30
        // seconds: web/js/workflow-run-live.js), so the change can take up to
        // about 30 seconds to show.
        testInfo.setTimeout(testInfo.timeout + 35_000);
        await expect(stepBadge(adminStep)).toHaveText(QUEUED, { timeout: 35_000 });
        await expect(runState(admin)).toHaveText(QUEUED);
        await expect(bannerTitle(admin)).toHaveText(bannerQueued(jobId, GATED_JOB_STEP));
        await expect(adminStep.locator('[data-wjs-request-link]')).toHaveText(requestLabel(requestId));
        await expectNotReloaded(admin);
      } finally {
        await cancelRunIfActive(admin, runId);
      }
    });
  });

  test('operator learns nothing of a workflow they may not view from its gated job\'s request', async ({ page, browser }, testInfo) => {
    const { runId, jobId, requestId } = await asAdmin(browser, testInfo, mixedWait);

    // The approval list names the job template only.
    const requests = await pendingRequestsOn(page, ALPHA_GATE_RULE);
    expect(requests.map((row) => row.id)).toEqual([requestId]);
    expect(requests[0].cells[REQUEST_FOR_COLUMN]).toBe(forJobTemplate(ALPHA_GATED_TEMPLATE));
    for (const row of await indexRows(page, '/approval/index')) {
      expect(row.cells.join(' '), `request #${row.id}`).not.toContain(MIXED_WORKFLOW);
    }

    // So do the request page and the job page.
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(page.locator('#approval-for')).toHaveText(forJobTemplate(ALPHA_GATED_TEMPLATE));
    await expect(page.locator('#approval-for a')).toHaveCount(0);
    await expect(page.locator('#page-content')).not.toContainText(MIXED_WORKFLOW);
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('#detail-approval')).toContainText(`${requestLabel(requestId)} · Pending`);
    await expect(page.locator('#detail-workflow-job')).toHaveCount(0);
    await expect(page.locator('#page-content')).not.toContainText(MIXED_WORKFLOW);

    // The run itself stays closed to them, on its page, in its poll and in the lists.
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expectForbidden(page);
    expect((await page.request.get(`/workflow-job/status?id=${runId}`)).status()).toBe(403);
    expect(rowsWhere(await indexRows(page, '/workflow-job/index'), RUN_WORKFLOW_COLUMN, MIXED_WORKFLOW)).toEqual([]);
    await page.goto('/site/index');
    await expect(page.locator('#page-content')).not.toContainText(MIXED_WORKFLOW);
  });

  // -- Viewer ----------------------------------------------------------------------

  test('viewer sees the request named and linked, and is warned that nobody can decide it', async ({ page }) => {
    const runId = await onlyRunId(page, BETA_APPROVAL_WORKFLOW);
    await page.goto(`/workflow-job/view?id=${runId}`);
    await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
    await expect(bannerTitle(page)).toHaveText(bannerApprovalStep(BETA_APPROVAL_STEP));

    // Named and linked: the viewer may open approval requests.
    const requestId = await bannerRequestId(page);
    await expect(bannerRequest(page)).toHaveText(factRequest(requestId));
    await expect(bannerRequest(page)).toHaveAttribute('href', `/approval/view?id=${requestId}`);
    const step = runStepRow(page, BETA_APPROVAL_STEP);
    await expect(step.locator('a[data-wjs-request-link]')).toHaveText(requestLabel(requestId));

    // On the rule's list, but without approval.decide; the other approver may not see it.
    await expect(bannerPersonal(page)).toHaveText(PERSONAL_NOT_ELIGIBLE);
    await expect(reviewLink(page)).toHaveCount(0);
    await expect(bannerFacts(page).filter({ hasText: factOpenApprovers(0) })).toHaveCount(1);
    await expect(bannerWarning(page)).toHaveText(WARNING_UNDECIDABLE);
    await expect(page.locator('#wj-cancel-form, #wj-resume-form')).toHaveCount(0);

    await bannerRequest(page).click();
    await expect(page).toHaveURL(new RegExp(`/approval/view\\?id=${requestId}$`));
    await expect(decisionForms(page)).toHaveCount(0);
  });

  test('viewer sees neither the run nor the request of another team\'s workflow', async ({ page, browser }, testInfo) => {
    const { runId, jobId, requestId } = await asAdmin(browser, testInfo, mixedWait);

    expect(await pendingRequestsOn(page, ALPHA_GATE_RULE)).toEqual([]);
    for (const path of [`/approval/view?id=${requestId}`, `/job/view?id=${jobId}`, `/workflow-job/view?id=${runId}`]) {
      await page.goto(path);
      await expectForbidden(page);
      await expect(page.locator('body'), path).not.toContainText(MIXED_WORKFLOW);
    }
  });
});
