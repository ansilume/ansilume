import { test, expect } from '@playwright/test';
import { LAUNCHER_REFUSED_RUN, asOperator, decide, flash, requestStatus } from '../../lib/approvals';
import {
  AWAITING_APPROVAL,
  CANCELED,
  LONG_PAUSED_PAUSE,
  LONG_PAUSED_WORKFLOW,
  MIXED_GATED_STEP,
  MIXED_WORKFLOW,
  NEVER_EXPIRES,
  OTHER_DAY,
  OTHER_DAY_STAMP,
  PAUSED,
  PAUSED_STEP,
  PAUSED_WORKFLOW,
  PERSONAL_LAUNCHER,
  QUEUED,
  RUN_CANCELED_FLASH,
  RUN_CURRENT_STEP_COLUMN,
  RUN_STATUS_COLUMN,
  RUN_WORKFLOW_COLUMN,
  STAMP,
  STEP_RUN_CANCELED,
  WAITING_SINCE,
  activeRunAt,
  activeRunBy,
  bannerApprovalStep,
  bannerFacts,
  bannerGatedJob,
  bannerPaused,
  bannerPersonal,
  bannerQueued,
  bannerRequest,
  bannerTitle,
  bannerWarning,
  cancelRunIfActive,
  expectNotReloaded,
  factApprovals,
  factOpenApprovers,
  factRequest,
  factRule,
  forWorkflowStep,
  launchWorkflow,
  launchedAwaitingApproval,
  launchedPaused,
  markPage,
  onlyRunId,
  requestLabel,
  reviewLink,
  runState,
  stepBadge,
  stepRequestId,
  stepWaitingMarker,
  waitBanner,
  workflowTemplateId,
} from '../../lib/runs';
import { indexRows, rowsWhere } from '../../lib/scoping';
import {
  APPROVAL_WORKFLOW,
  GATED_JOB_STEP,
  GATED_JOB_WORKFLOW,
  GATE_RULE,
  runStepRow,
  stepJobId,
  templateStepRow,
} from '../../lib/workflows';

/**
 * What a workflow run waits for, on the run page, the Workflow Jobs list,
 * the workflow template page and the dashboard (services/runstate/). A run
 * that waits for an approval, at a pause step or for a runner is stored as
 * running and shows as Awaiting Approval, Paused or Queued.
 *
 * e2e-admin launches the runs: e2e-approval-workflow (an approval step on
 * e2e-approval-rule, then a job step), e2e-gated-job-workflow (a job step
 * that e2e-gate-rule gates, whose runner group no runner serves) and
 * e2e-paused-workflow (a pause step). The launcher never decides on their own
 * run, so e2e-operator, the rules' other approver, decides. Every test cancels
 * the run it started unless it has finished. The waiting runs of
 * e2e-mixed-gated-wf and e2e-long-paused-workflow are seeded
 * (commands/E2eWorkflowWaitSeeder.php); no test moves them on.
 */
const APPROVAL_STEP = 'e2e-step-approve';
const APPROVAL_RULE = 'e2e-approval-rule';

/** The run the running test started; afterEach cancels it unless it has finished. */
let started = '';

test.describe('What workflow runs wait for', () => {
  test.afterEach(async ({ page }) => {
    await cancelRunIfActive(page, started);
    started = '';
  });

  // regression: a run waiting at its approval step read "Running"
  // (tests/integration/controllers/WorkflowJobPageStateTest.php
  // testRegressionTheStatusOfARunWaitingForApprovalSaidRunning).
  test('regression: a run waiting at its approval step read "Running"', async ({ page }) => {
    started = await launchWorkflow(page, APPROVAL_WORKFLOW);
    // The launch flash names the step that waits and its request.
    const launchFlash = flash(page, 'success');
    await expect(launchFlash).toHaveText(launchedAwaitingApproval(APPROVAL_WORKFLOW, APPROVAL_STEP));
    const flashedRequest = ((await launchFlash.textContent()) ?? '').match(launchedAwaitingApproval(APPROVAL_WORKFLOW, APPROVAL_STEP))?.[1];

    await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
    await expect(runState(page)).toHaveClass(/(^|\s)text-bg-info(\s|$)/);
    const row = runStepRow(page, APPROVAL_STEP);
    await expect(stepBadge(row)).toHaveText(AWAITING_APPROVAL);
    const requestId = await stepRequestId(row);
    expect(flashedRequest, 'the flash names the request the step waits for').toBe(requestId);
    // The step names its request, not the placeholder job that holds it.
    await expect(row.locator('[data-wjs-job-cell]')).toHaveText(requestLabel(requestId));
    await expect(row.locator('[data-wjs-request-link]')).toHaveAttribute('href', `/approval/view?id=${requestId}`);
    await expect(row.locator('[data-wjs-duration-cell]')).toHaveText(/^waiting (\d+m )?\d+s$/);

    // The banner: the request, its rule, the counts and since when, and the launcher's own line.
    await expect(waitBanner(page)).toBeVisible();
    await expect(waitBanner(page)).toHaveAttribute('data-wait', 'approval');
    await expect(waitBanner(page)).toHaveClass(/(^|\s)alert-info(\s|$)/);
    await expect(bannerTitle(page)).toHaveText(bannerApprovalStep(APPROVAL_STEP));
    await expect(bannerFacts(page)).toHaveText([
      factRequest(requestId),
      factRule(APPROVAL_RULE),
      factApprovals(0, 1),
      factOpenApprovers(1),
      WAITING_SINCE,
      new RegExp(`^expires ${STAMP} \\(then rejected\\)$`),
    ]);
    await expect(bannerRequest(page)).toHaveAttribute('href', `/approval/view?id=${requestId}`);
    await expect(bannerPersonal(page)).toHaveText(PERSONAL_LAUNCHER);
    await expect(reviewLink(page), 'the launcher never decides').toHaveCount(0);
    await expect(bannerWarning(page)).toHaveCount(0);
  });

  // regression: a step that a run cancel ended read "Failed"
  // (tests/integration/controllers/WorkflowJobPageStateTest.php
  // testRegressionAStepEndedByARunCancelReadFailed).
  test('regression: a step of a canceled run read "Failed"', async ({ page }) => {
    started = await launchWorkflow(page, APPROVAL_WORKFLOW);
    const row = runStepRow(page, APPROVAL_STEP);
    const requestId = await stepRequestId(row);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#wj-cancel-form button[type="submit"]').click();
    await expect(flash(page, 'success')).toHaveText(RUN_CANCELED_FLASH);
    await expect(runState(page)).toHaveText(CANCELED);
    await expect(stepBadge(row)).toHaveText(CANCELED);
    await expect(stepBadge(row)).toHaveClass(/(^|\s)text-bg-warning(\s|$)/);
    await expect(row.locator('[data-wjs-error]')).toHaveText(STEP_RUN_CANCELED);
    // The run waits for nothing any more.
    await expect(waitBanner(page)).toBeHidden();
    await expect(page.locator('#wj-cancel-form, #wj-resume-form')).toHaveCount(0);
    await expect(row.locator('[data-wjs-request-link]')).toHaveText(requestLabel(requestId));
    await page.goto(`/approval/view?id=${requestId}`);
    await expect(requestStatus(page)).toHaveText('Canceled');
  });

  test('a gated job step reads Awaiting Approval, then Queued without a reload', async ({ page, browser }, testInfo) => {
    started = await launchWorkflow(page, GATED_JOB_WORKFLOW);
    await expect(flash(page, 'success')).toHaveText(launchedAwaitingApproval(GATED_JOB_WORKFLOW, GATED_JOB_STEP));
    const row = runStepRow(page, GATED_JOB_STEP);
    await expect(stepBadge(row)).toHaveText(AWAITING_APPROVAL);
    const jobId = await stepJobId(row);
    const requestId = await stepRequestId(row);
    await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
    await expect(bannerTitle(page)).toHaveText(bannerGatedJob(jobId, GATED_JOB_STEP));
    await expect(bannerFacts(page).nth(1)).toHaveText(factRule(GATE_RULE));
    await markPage(page);

    await asOperator(browser, testInfo, async (operator) => {
      await operator.goto(`/approval/view?id=${requestId}`);
      await decide(operator, 'approve');
      await expect(flash(operator, 'success')).toHaveText('Approval recorded.');
    });

    // The open page follows the poll: the job waits for a runner now, which
    // none serves. While nothing changes the page polls less and less often
    // (3, 6, 12, 24, then every 30 seconds: web/js/workflow-run-live.js), so
    // the change can take up to about 30 seconds to show.
    testInfo.setTimeout(testInfo.timeout + 35_000);
    await expect(stepBadge(row)).toHaveText(QUEUED, { timeout: 35_000 });
    await expect(runState(page)).toHaveText(QUEUED);
    await expect(waitBanner(page)).toHaveAttribute('data-wait', 'runner');
    await expect(waitBanner(page)).toHaveClass(/(^|\s)alert-secondary(\s|$)/);
    await expect(bannerTitle(page)).toHaveText(bannerQueued(jobId, GATED_JOB_STEP));
    await expect(bannerFacts(page)).toHaveText([WAITING_SINCE]);
    await expect(bannerPersonal(page)).toHaveCount(0);
    // The decided request stays named, with how it was decided.
    await expect(row.locator('[data-wjs-request-link]')).toHaveText(requestLabel(requestId));
    await expect(row.locator('[data-wjs-decision]')).toHaveText(new RegExp(`^Approved by e2e-operator at ${STAMP}\\.$`));
    await expectNotReloaded(page);
  });

  test('a run paused at its first step reads Paused, and so does its launch flash', async ({ page }) => {
    started = await launchWorkflow(page, PAUSED_WORKFLOW);
    await expect(flash(page, 'success')).toHaveText(launchedPaused(PAUSED_WORKFLOW, PAUSED_STEP));
    await expect(runState(page)).toHaveText(PAUSED);
    await expect(runState(page)).toHaveClass(/(^|\s)text-bg-warning(\s|$)/);
    await expect(stepBadge(runStepRow(page, PAUSED_STEP))).toHaveText(PAUSED);
    await expect(waitBanner(page)).toHaveAttribute('data-wait', 'resume');
    await expect(waitBanner(page)).toHaveClass(/(^|\s)alert-warning(\s|$)/);
    await expect(bannerTitle(page)).toHaveText(bannerPaused(PAUSED_STEP));
    await expect(bannerFacts(page)).toHaveText([WAITING_SINCE]);
    await expect(page.locator('#wj-resume-form')).toBeVisible();
    await expect(page.locator('#wj-cancel-form')).toBeVisible();
  });

  test('the Workflow Jobs list filters the runs by what they wait for', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const filter = page.locator('#wj-filter');
    await expect(filter.locator('a')).toHaveText(['All', AWAITING_APPROVAL, PAUSED, QUEUED]);
    await expect(filter.locator('a[aria-current="page"]')).toHaveText('All');

    await filter.getByRole('link', { name: AWAITING_APPROVAL }).click();
    await expect(page).toHaveURL(/\/workflow-job\/index\?waiting_for=approval$/);
    await expect(filter.locator('a[aria-current="page"]')).toHaveText(AWAITING_APPROVAL);
    const waiting = await indexRows(page, '/workflow-job/index?waiting_for=approval');
    expect(waiting.length).toBeGreaterThan(0);
    expect(waiting.filter((row) => row.cells[RUN_STATUS_COLUMN] !== AWAITING_APPROVAL), 'every run waits for an approval').toEqual([]);
    const mixed = rowsWhere(waiting, RUN_WORKFLOW_COLUMN, MIXED_WORKFLOW);
    expect(mixed).toHaveLength(1);
    // The current step names the request the run waits for.
    expect(mixed[0].cells[RUN_CURRENT_STEP_COLUMN]).toMatch(new RegExp(`^${MIXED_GATED_STEP} Request #\\d+$`));

    await page.goto('/workflow-job/index?waiting_for=approval');
    await filter.getByRole('link', { name: PAUSED }).click();
    await expect(page).toHaveURL(/\/workflow-job\/index\?waiting_for=resume$/);
    const paused = await indexRows(page, '/workflow-job/index?waiting_for=resume');
    expect(paused.filter((row) => row.cells[RUN_STATUS_COLUMN] !== PAUSED), 'every run is paused').toEqual([]);
    const longPaused = rowsWhere(paused, RUN_WORKFLOW_COLUMN, LONG_PAUSED_WORKFLOW);
    expect(longPaused).toHaveLength(1);
    expect(longPaused[0].cells[RUN_CURRENT_STEP_COLUMN]).toBe(LONG_PAUSED_PAUSE);
    expect(rowsWhere(paused, RUN_WORKFLOW_COLUMN, MIXED_WORKFLOW)).toEqual([]);

    await page.goto('/workflow-job/index?waiting_for=resume');
    await filter.getByRole('link', { name: QUEUED }).click();
    await expect(page).toHaveURL(/\/workflow-job\/index\?waiting_for=runner$/);
    const queued = await indexRows(page, '/workflow-job/index?waiting_for=runner');
    expect(queued.filter((row) => row.cells[RUN_STATUS_COLUMN] !== QUEUED), 'every run waits for a runner').toEqual([]);
    expect(rowsWhere(queued, RUN_WORKFLOW_COLUMN, LONG_PAUSED_WORKFLOW)).toEqual([]);

    // A filter the list does not know is ignored: every run is listed.
    await page.goto('/workflow-job/index?waiting_for=everything');
    await expect(filter.locator('a[aria-current="page"]')).toHaveText('All');
  });

  test('the template page lists its active runs and where they wait', async ({ page }) => {
    const runId = await onlyRunId(page, LONG_PAUSED_WORKFLOW);
    const workflowId = await workflowTemplateId(page, LONG_PAUSED_WORKFLOW);
    await page.goto(`/workflow-template/view?id=${workflowId}`);

    const card = page.locator('#wf-active-runs');
    await expect(card.locator('.card-header')).toHaveText('Active runs');
    const run = card.locator(`li[data-run-id="${runId}"]`);
    await expect(run.locator('.badge[data-run-state]')).toHaveText(PAUSED);
    await expect(run.locator('.badge[data-run-state]')).toHaveAttribute('data-run-state', 'paused');
    // Paused 26 hours ago: the date is part of the time.
    await expect(run.locator('span', { hasText: 'at step' })).toHaveText(activeRunAt(LONG_PAUSED_PAUSE, OTHER_DAY));
    await expect(run).toContainText(activeRunBy('e2e-admin'));
    // The step where it waits counts it.
    await expect(templateStepRow(page, LONG_PAUSED_PAUSE).locator('[data-step-waiting]')).toHaveText(stepWaitingMarker(1));

    await card.locator('#wf-active-runs-all').click();
    await expect(page).toHaveURL(new RegExp(`/workflow-job/index\\?workflow_template_id=${workflowId}&status=running$`));
    const listed = await page.locator('#page-content table.table tbody tr').evaluateAll((trs) => trs.map((tr) => tr.querySelector('a')?.textContent?.trim()));
    expect(listed, 'the list shows the workflow\'s active run only').toEqual([runId]);
  });

  test('the template page names the request an active run waits for', async ({ page }) => {
    const runId = await onlyRunId(page, MIXED_WORKFLOW);
    await page.goto(`/workflow-template/view?id=${await workflowTemplateId(page, MIXED_WORKFLOW)}`);
    const run = page.locator(`#wf-active-runs li[data-run-id="${runId}"]`);
    await expect(run.locator('.badge[data-run-state]')).toHaveText(AWAITING_APPROVAL);
    await expect(run.locator('span', { hasText: 'at step' })).toHaveText(activeRunAt(MIXED_GATED_STEP));
    const request = run.locator('[data-run-request]');
    await expect(request).toHaveText(/^Request #\d+$/);
    await expect(request).toHaveAttribute('href', /^\/approval\/view\?id=\d+$/);
  });

  // regression: the dashboard flagged pauses but not approval waits
  // (tests/integration/controllers/DashboardWorkflowWaitsTest.php
  // testRegressionTheDashboardFlaggedPausesButNotApprovalWaits).
  test('regression: the dashboard flagged pauses but not approval waits', async ({ page }) => {
    const mixedRunId = await onlyRunId(page, MIXED_WORKFLOW);
    const longRunId = await onlyRunId(page, LONG_PAUSED_WORKFLOW);
    await page.goto('/site/index');
    const panel = page.locator('#dashboard-running-workflows');

    const paused = panel.locator(`tr[data-run-id="${longRunId}"]`);
    await expect(paused.locator('[data-run-state]')).toHaveText(PAUSED);
    await expect(paused.locator('form[action*="/workflow-job/resume"]')).toHaveCount(1);
    await expect(paused.locator('a[data-run-review]')).toHaveCount(0);

    const mixed = panel.locator(`tr[data-run-id="${mixedRunId}"]`);
    const state = mixed.locator('[data-run-state]');
    await expect(state).toHaveText(AWAITING_APPROVAL);
    await expect(state).toHaveAttribute('data-run-state', 'awaiting_approval');
    await expect(state).toHaveAttribute('title', WAITING_SINCE);
    await expect(mixed.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);
    const review = mixed.locator('a[data-run-review]');
    await expect(review).toHaveText('Review');
    const requestId = (await review.getAttribute('data-run-review')) ?? '';

    await review.click();
    await expect(page).toHaveURL(new RegExp(`/approval/view\\?id=${requestId}$`));
    await expect(page.locator('#approval-for')).toHaveText(forWorkflowStep(MIXED_WORKFLOW, MIXED_GATED_STEP, mixedRunId));
    // e2e-admin launched the run: another approver decides.
    await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED_RUN);
  });

  // regression: "Running for" wrapped after 24 hours
  // (tests/integration/controllers/DashboardWorkflowWaitsTest.php
  // testRegressionRunningForWrappedAfter24Hours).
  test('regression: "Running for" wrapped after 24 hours', async ({ page }) => {
    const runId = await onlyRunId(page, LONG_PAUSED_WORKFLOW);
    await page.goto('/site/index');
    const cells = page.locator(`#dashboard-running-workflows tr[data-run-id="${runId}"] td`);
    // #, Workflow, Current Step, Launched by, Started, Running for
    await expect(cells.nth(1)).toHaveText(LONG_PAUSED_WORKFLOW);
    await expect(cells.nth(4)).toHaveText(OTHER_DAY_STAMP);
    await expect(cells.nth(5)).toHaveText(/^1d \d{1,2}h$/);

    // The run page counts the days too.
    await page.goto(`/workflow-job/view?id=${runId}`);
    const pause = runStepRow(page, LONG_PAUSED_PAUSE);
    await expect(stepBadge(pause)).toHaveText(PAUSED);
    await expect(pause.locator('[data-wjs-started-cell]')).toHaveText(OTHER_DAY_STAMP);
    await expect(pause.locator('[data-wjs-duration-cell]')).toHaveText(/^waiting 1d \d{1,2}h$/);
    await expect(bannerTitle(page)).toHaveText(bannerPaused(LONG_PAUSED_PAUSE));
    await expect(bannerFacts(page)).toHaveText([new RegExp(`^waiting since ${OTHER_DAY}$`)]);
  });

  test('a waiting request that never expires says so', async ({ page }) => {
    await page.goto(`/workflow-job/view?id=${await onlyRunId(page, MIXED_WORKFLOW)}`);
    await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
    await expect(bannerFacts(page).last()).toHaveText(NEVER_EXPIRES);
  });
});
