import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import {
  EXPIRES_NEVER,
  HISTORY_RULE,
  HISTORY_TEMPLATE,
  LAUNCHER_REFUSED_RUN,
  REQUEST_EXPIRES_COLUMN,
  REQUEST_FOR_COLUMN,
  REQUEST_RULE_COLUMN,
  REQUEST_STATUS_COLUMN,
  decisionForms,
  requestStatus,
} from '../../lib/approvals';
import {
  ALPHA_GATE_RULE,
  AWAITING_APPROVAL,
  BETA_APPROVAL_RULE,
  BETA_APPROVAL_STEP,
  BETA_APPROVAL_WORKFLOW,
  MIXED_GATED_STEP,
  MIXED_WORKFLOW,
  forJobTemplate,
  forWorkflowStep,
  onlyRunId,
  runState,
  workflowTemplateId,
} from '../../lib/runs';
import { IndexRow, forgePost, indexRows, rowsWhere } from '../../lib/scoping';
import { E2E_WATCHER, asUser } from '../../lib/session';

/**
 * What an approval request is for (views/approval/_for.php): the workflow,
 * step and run of its job, for a user who may view the workflow, else its job
 * template; on the approval list, the request page and the dashboard. And the
 * list's status filter.
 *
 * Fixtures: the seeded run of e2e-mixed-gated-wf, whose gated job step waits
 * for a request on e2e-alpha-gate-rule, launched by e2e-admin
 * (commands/E2eWorkflowWaitSeeder.php); the run of e2e-beta-approval-wf,
 * waiting at its approval step (E2eWorkflowScopingSeeder.php), which the
 * watcher, a viewer of its team, sees; the requests of
 * e2e-history-gated-template (E2eApprovalSeeder.php), one of them canceled.
 * Nothing here decides or leaves anything waiting.
 */

/** The one pending request on the rule `rule` that the user of `page` sees. */
async function pendingRequestOn(page: Page, rule: string): Promise<IndexRow> {
  const rows = rowsWhere(await indexRows(page, '/approval/index?status=pending'), REQUEST_RULE_COLUMN, rule);
  expect(rows, `one pending request on ${rule}`).toHaveLength(1);
  return rows[0];
}

/** The status filter of the approval list. */
function statusFilter(page: Page) {
  return page.locator('#approval-status-filter');
}

test.describe('What approval requests are for', () => {
  test('a gated job step\'s request is for its workflow, step and run, which it links', async ({ page }) => {
    const runId = await onlyRunId(page, MIXED_WORKFLOW);
    const workflowId = await workflowTemplateId(page, MIXED_WORKFLOW);
    const request = await pendingRequestOn(page, ALPHA_GATE_RULE);
    expect(request.cells[REQUEST_FOR_COLUMN]).toBe(forWorkflowStep(MIXED_WORKFLOW, MIXED_GATED_STEP, runId));
    expect(request.cells[REQUEST_EXPIRES_COLUMN], 'the rule has no timeout').toBe(EXPIRES_NEVER);

    await page.goto(`/approval/view?id=${request.id}`);
    const forCell = page.locator('#approval-for');
    await expect(forCell).toHaveText(forWorkflowStep(MIXED_WORKFLOW, MIXED_GATED_STEP, runId));
    const workflow = forCell.locator('a[data-for-workflow]');
    await expect(workflow).toHaveText(MIXED_WORKFLOW);
    await expect(workflow).toHaveAttribute('href', `/workflow-template/view?id=${workflowId}`);
    const run = forCell.locator('a[data-for-run]');
    await expect(run).toHaveText(`Workflow Job #${runId}`);

    await run.click();
    await expect(page).toHaveURL(new RegExp(`/workflow-job/view\\?id=${runId}$`));
    await expect(runState(page)).toHaveText(AWAITING_APPROVAL);
  });

  // regression: the launcher of a workflow run read "You launched this job"
  // (tests/integration/services/ApprovalVoteTest.php
  // testRegressionTheLauncherOfAWorkflowRunWasToldYouLaunchedThisJob; the old
  // services/ApprovalDecisionRefusedException.php had LAUNCHER_REFUSED only).
  test('regression: the launcher of a workflow run read "You launched this job"', async ({ page }) => {
    const request = await pendingRequestOn(page, ALPHA_GATE_RULE);
    await page.goto(`/approval/view?id=${request.id}`);
    await expect(page.locator('#approval-launched-by')).toHaveText('e2e-admin');
    await expect(page.locator('#approval-decision-note')).toHaveText(LAUNCHER_REFUSED_RUN);
    await expect(decisionForms(page)).toHaveCount(0);

    // A real form post with a valid CSRF token: the server refuses it with the same words.
    await forgePost(page, `/approval/approve?id=${request.id}`, {}, /\/approval\/approve/);
    await expectForbidden(page);
    await expect(page.locator('#page-content')).toContainText(LAUNCHER_REFUSED_RUN);
    await page.goto(`/approval/view?id=${request.id}`);
    await expect(requestStatus(page)).toHaveText('Pending');
  });

  test('a job template\'s request is for its template', async ({ page }) => {
    const rows = rowsWhere(await indexRows(page, '/approval/index'), REQUEST_RULE_COLUMN, HISTORY_RULE);
    expect(rows.length).toBeGreaterThan(0);
    for (const row of rows) {
      expect(row.cells[REQUEST_FOR_COLUMN]).toBe(forJobTemplate(HISTORY_TEMPLATE));
    }
    await page.goto(`/approval/view?id=${rows[0].id}`);
    await expect(page.locator('#approval-for')).toHaveText(forJobTemplate(HISTORY_TEMPLATE));
    await expect(page.locator('#approval-for a')).toHaveCount(0);
  });

  test('the status filter lists the requests of one status', async ({ page }) => {
    await page.goto('/approval/index');
    const filter = statusFilter(page);
    await expect(filter.locator('a')).toHaveText(['All', 'Pending', 'Approved', 'Rejected', 'Timed Out', 'Canceled']);
    await expect(filter.locator('a[aria-current="page"]')).toHaveText('All');

    await filter.getByRole('link', { name: 'Pending' }).click();
    await expect(page).toHaveURL(/\/approval\/index\?status=pending$/);
    await expect(filter.locator('a[aria-current="page"]')).toHaveText('Pending');
    const pending = await indexRows(page, '/approval/index?status=pending');
    expect(pending.length).toBeGreaterThan(0);
    expect(pending.filter((row) => row.cells[REQUEST_STATUS_COLUMN] !== 'Pending'), 'every request is pending').toEqual([]);
    expect(rowsWhere(pending, REQUEST_RULE_COLUMN, ALPHA_GATE_RULE)).toHaveLength(1);

    await page.goto('/approval/index?status=pending');
    await filter.getByRole('link', { name: 'Canceled' }).click();
    await expect(page).toHaveURL(/\/approval\/index\?status=canceled$/);
    const canceled = await indexRows(page, '/approval/index?status=canceled');
    expect(canceled.filter((row) => row.cells[REQUEST_STATUS_COLUMN] !== 'Canceled'), 'every request is canceled').toEqual([]);
    expect(rowsWhere(canceled, REQUEST_RULE_COLUMN, HISTORY_RULE), 'the canceled history request').toHaveLength(1);
    expect(rowsWhere(canceled, REQUEST_RULE_COLUMN, ALPHA_GATE_RULE)).toEqual([]);
    // A canceled request reads in grey, which the dark theme shows (jobs/badge-contrast.spec.ts).
    await page.goto('/approval/index?status=canceled');
    await expect(page.locator('#approval-requests-table tbody .badge').first()).toHaveClass(/(^|\s)text-bg-secondary(\s|$)/);

    // A status the list does not know is ignored: every request is listed.
    await page.goto('/approval/index?status=PENDING');
    await expect(filter.locator('a[aria-current="page"]')).toHaveText('All');
  });

  test('the watcher sees what their team\'s request is for, and opens its run', async ({ page, browser }, testInfo) => {
    // The run's ID as e2e-admin sees it; the watcher, a viewer of team-beta, sees the run too.
    const runId = await onlyRunId(page, BETA_APPROVAL_WORKFLOW);

    await asUser(browser, testInfo, E2E_WATCHER, async (watcher) => {
      const request = await pendingRequestOn(watcher, BETA_APPROVAL_RULE);
      expect(request.cells[REQUEST_FOR_COLUMN]).toBe(forWorkflowStep(BETA_APPROVAL_WORKFLOW, BETA_APPROVAL_STEP, runId));
      // Nothing of another team's workflow.
      expect(rowsWhere(await indexRows(watcher, '/approval/index'), REQUEST_RULE_COLUMN, ALPHA_GATE_RULE)).toEqual([]);

      await watcher.goto(`/approval/view?id=${request.id}`);
      await expect(watcher.locator('#approval-for')).toHaveText(forWorkflowStep(BETA_APPROVAL_WORKFLOW, BETA_APPROVAL_STEP, runId));
      await expect(decisionForms(watcher)).toHaveCount(0);
      await watcher.locator('#approval-for a[data-for-run]').click();
      await expect(watcher).toHaveURL(new RegExp(`/workflow-job/view\\?id=${runId}$`));
      await expect(runState(watcher)).toHaveText(AWAITING_APPROVAL);
    });
  });
});
