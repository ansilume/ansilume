import { test, expect, Browser, Page, TestInfo } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows, rowsWhere } from '../../lib/scoping';

/**
 * Team scoping of workflow runs: a run follows its workflow template. Seeing
 * it needs view access to the project of every job step; resuming or
 * canceling it operator access to every one.
 *
 * Runs seeded by commands/E2eWorkflowScopingSeeder.php:
 *   e2e-alpha-wf          succeeded, child job on e2e-alpha-tmpl
 *   e2e-beta-wf           succeeded, child job on e2e-beta-tmpl
 *   e2e-alpha-viewed-wf   running, paused at its pause step (operator's team only views it)
 *   e2e-alpha-denied-wf   failed, launched by e2e-operator: the job of its step on
 *                         e2e-alpha-tmpl succeeded, its step on e2e-alpha-viewed-tmpl
 *                         was not launched, with the reason
 *   e2e-beta-approval-wf  running, waiting at its approval step
 */
const INDEX = '/workflow-job/index';
// Columns of the index: #, Workflow, Status, Launched By, Started, Finished.
const WORKFLOW = 1;

/** The id of the run of a fixture workflow, as the signed-in user sees the index. */
async function runId(page: Page, workflow: string): Promise<string> {
  return idWhere(await indexRows(page, INDEX), WORKFLOW, workflow);
}

/** The row of the step named `step` in the steps table of the open run page. */
function stepRow(page: Page, step: string) {
  return page.locator('#wj-steps-table tbody tr', { hasText: step });
}

/**
 * Why the second step of e2e-alpha-denied-wf's run was not launched, with
 * the ids of e2e-operator, whom the run runs as, and of e2e-alpha-viewed-tmpl.
 */
async function deniedReason(browser: Browser, testInfo: TestInfo): Promise<string> {
  return asAdmin(browser, testInfo, async (admin) => {
    // Columns of both indexes: #, then the name.
    const operator = idWhere(await indexRows(admin, '/user/index'), 1, 'e2e-operator');
    const template = idWhere(await indexRows(admin, '/job-template/index?sort=name'), 1, 'e2e-alpha-viewed-tmpl');
    return `Not launched: user #${operator}, whom this workflow runs as, may not launch job template "e2e-alpha-viewed-tmpl" (#${template}).`;
  });
}

/** The open page of e2e-alpha-denied-wf's run says why its second step failed without a job. */
async function expectRefusedStep(page: Page, reason: string) {
  await expect(page.locator('#wj-status')).toHaveText('Failed');
  const launched = stepRow(page, 'e2e-alpha-denied-wf-alpha');
  await expect(launched.locator('[data-wjs-status-cell] .badge')).toHaveText('Succeeded');
  await expect(launched.locator('[data-wjs-error]')).toHaveCount(0);
  const refused = stepRow(page, 'e2e-alpha-denied-wf-viewed');
  await expect(refused.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
  await expect(refused.locator('[data-wjs-error]')).toHaveText(reason);
  await expect(refused.locator('[data-wjs-job-cell]')).toHaveText('—');
}

test.describe('Team Scoping — Workflow Jobs', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  // -- Operator (team-alpha) ---------------------------------------------------

  test('operator sees the runs of workflows their team may view, and no others', async ({ page }) => {
    const rows = await indexRows(page, INDEX);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-wf')).toHaveLength(1);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-viewed-wf')).toHaveLength(1);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-denied-wf')).toHaveLength(1);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-beta-wf')).toHaveLength(0);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-beta-approval-wf')).toHaveLength(0);
  });

  test('operator gets 403 on the run of another team\'s workflow and on its status poll', async ({ page, browser }, testInfo) => {
    const ids = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, INDEX);
      return [idWhere(rows, WORKFLOW, 'e2e-beta-wf'), idWhere(rows, WORKFLOW, 'e2e-beta-approval-wf')];
    });
    for (const id of ids) {
      await page.goto(`/workflow-job/view?id=${id}`);
      await expectForbidden(page);
      await expect(page.locator('#wj-steps-table')).toHaveCount(0);
      const poll = await page.request.get(`/workflow-job/status?id=${id}`);
      expect(poll.status()).toBe(403);
    }
  });

  test('operator may view but not resume or cancel a run of a workflow their team only views', async ({ page }) => {
    const id = await runId(page, 'e2e-alpha-viewed-wf');
    await page.goto(`/workflow-job/view?id=${id}`);
    await expect(page.locator('#wj-status')).toHaveText('Running');
    await expect(page.locator('#wj-steps-table')).toContainText('e2e-alpha-viewed-wf-pause');
    await expect(page.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).toHaveCount(0);

    // Real form posts with a valid CSRF token: only the access check may stop them.
    await forgePost(page, `/workflow-job/resume?id=${id}`, {}, /\/workflow-job\/resume/);
    await expectForbidden(page);
    await page.goto(`/workflow-job/view?id=${id}`);
    await forgePost(page, `/workflow-job/cancel?id=${id}`, {}, /\/workflow-job\/cancel/);
    await expectForbidden(page);

    await page.goto(`/workflow-job/view?id=${id}`);
    await expect(page.locator('#wj-status')).toHaveText('Running');
    await expect(page.locator('#wj-steps-table tbody tr')).toHaveCount(1);
    await expect(page.locator('#wj-steps-table tbody tr').first()).toContainText('Running');
  });

  test('operator opens a finished run of their team\'s workflow with its child job', async ({ page }) => {
    await page.goto(`/workflow-job/view?id=${await runId(page, 'e2e-alpha-wf')}`);
    await expect(page.locator('#wj-status')).toHaveText('Succeeded');
    const step = page.locator('#wj-steps-table tbody tr', { hasText: 'e2e-alpha-wf-job' });
    await expect(step).toContainText('Succeeded');
    await step.locator('[data-wjs-job-cell] a').click();
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content')).toContainText('e2e-alpha-tmpl');
  });

  test('operator sees on the run page why a step of their run was not launched', async ({ page, browser }, testInfo) => {
    const reason = await deniedReason(browser, testInfo);
    await page.goto(`/workflow-job/view?id=${await runId(page, 'e2e-alpha-denied-wf')}`);
    await expectRefusedStep(page, reason);
  });

  test('operator\'s page of an unfinished run shows a refusal the status poll reports, as text', async ({ page }) => {
    // While a run is unfinished, its page polls /workflow-job/status and adds
    // the steps it reports. This poll answer has the paused run go on and its
    // job step refused, with HTML in the reason: the page must show it as text.
    const reason = '<img src=x onerror=alert(1)>denied';
    await page.route(/\/workflow-job\/status\?id=\d+$/, async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      const pause = data.steps[0];
      data.status = 'failed';
      data.status_label = 'Failed';
      data.status_css = 'danger';
      data.is_finished = true;
      data.steps = [
        { ...pause, is_current: false, status: 'succeeded', status_label: 'Succeeded', status_css: 'success' },
        {
          ...pause,
          workflow_step_id: pause.workflow_step_id + 1,
          step_name: 'e2e-alpha-viewed-wf-job',
          step_index: 2,
          job_id: null,
          status: 'failed',
          status_label: 'Failed',
          status_css: 'danger',
          error_message: reason,
        },
      ];
      await route.fulfill({ response, json: data });
    });

    await page.goto(`/workflow-job/view?id=${await runId(page, 'e2e-alpha-viewed-wf')}`);

    const refused = stepRow(page, 'e2e-alpha-viewed-wf-job');
    await expect(refused.locator('[data-wjs-error]')).toHaveText(reason);
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
    await expect(refused.locator('[data-wjs-status-cell] .badge')).toHaveText('Failed');
    await expect(stepRow(page, 'e2e-alpha-viewed-wf-pause').locator('[data-wjs-error]')).toHaveCount(0);
    // The whole answer was applied: the run is shown failed and polling stopped.
    await expect(page.locator('#wj-status')).toHaveText('Failed');
    await expect(page.locator('#wj-live')).toHaveCount(0);
  });

  // -- Viewer (team-beta) --------------------------------------------------------

  test('viewer sees the runs of their team\'s workflows, and no others', async ({ page }) => {
    const rows = await indexRows(page, INDEX);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-beta-wf')).toHaveLength(1);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-beta-approval-wf')).toHaveLength(1);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-wf')).toHaveLength(0);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-viewed-wf')).toHaveLength(0);
    expect(rowsWhere(rows, WORKFLOW, 'e2e-alpha-denied-wf')).toHaveLength(0);
    // e2e-team's workflow (job step on e2e-template), which the viewer's team cannot see either.
    expect(rowsWhere(rows, WORKFLOW, 'e2e-paused-workflow')).toHaveLength(0);
  });

  test('viewer opens a finished run of their team\'s workflow with its child job', async ({ page }) => {
    await page.goto(`/workflow-job/view?id=${await runId(page, 'e2e-beta-wf')}`);
    await expect(page.locator('#wj-status')).toHaveText('Succeeded');
    const step = page.locator('#wj-steps-table tbody tr', { hasText: 'e2e-beta-wf-job' });
    await expect(step).toContainText('Succeeded');
    await expect(step.locator('[data-wjs-error]')).toHaveCount(0);
    await step.locator('[data-wjs-job-cell] a').click();
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content')).toContainText('e2e-beta-tmpl');
  });

  test('viewer gets 403 on the run of another team\'s workflow and on its status poll', async ({ page, browser }, testInfo) => {
    const ids = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, INDEX);
      return ['e2e-alpha-wf', 'e2e-alpha-viewed-wf', 'e2e-alpha-denied-wf'].map((name) => idWhere(rows, WORKFLOW, name));
    });
    for (const id of ids) {
      await page.goto(`/workflow-job/view?id=${id}`);
      await expectForbidden(page);
      // The poll answer carries the reasons why steps failed.
      const poll = await page.request.get(`/workflow-job/status?id=${id}`);
      expect(poll.status()).toBe(403);
    }
  });

  // -- Admin -------------------------------------------------------------------------

  test('admin sees the runs of every team\'s workflows', async ({ page }) => {
    const rows = await indexRows(page, INDEX);
    for (const name of ['e2e-alpha-wf', 'e2e-alpha-viewed-wf', 'e2e-alpha-denied-wf', 'e2e-beta-wf', 'e2e-beta-approval-wf']) {
      expect(rowsWhere(rows, WORKFLOW, name), name).toHaveLength(1);
    }
    // The run the viewer's index must leave out (e2e-team's workflow).
    expect(rowsWhere(rows, WORKFLOW, 'e2e-paused-workflow').length).toBeGreaterThan(0);
  });

  test('admin sees on the run page why a step was not launched', async ({ page, browser }, testInfo) => {
    const reason = await deniedReason(browser, testInfo);
    await page.goto(`/workflow-job/view?id=${await runId(page, 'e2e-alpha-denied-wf')}`);
    await expectRefusedStep(page, reason);
  });
});
