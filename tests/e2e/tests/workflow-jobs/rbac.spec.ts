import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { forgePost, idWhere, indexRows } from '../../lib/scoping';

/**
 * The viewer only sees the runs of workflows whose every job step is in a
 * project of their team (e2e-team-beta, see commands/E2eWorkflowScopingSeeder.php).
 * Their team holds the operator role on e2e-beta-proj, so only the viewer
 * RBAC role keeps them from canceling or resuming those runs.
 */
async function openWaitingBetaRun(page: Page): Promise<string> {
  // Columns of the index: #, Workflow, Status, ...; e2e-beta-approval-wf waits at its approval step.
  const id = idWhere(await indexRows(page, '/workflow-job/index'), 1, 'e2e-beta-approval-wf');
  await page.goto(`/workflow-job/view?id=${id}`);
  await expect(page.locator('#wj-status')).toHaveText('Running');
  return id;
}

test.describe('Workflow Jobs RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view workflow jobs index', async ({ page }) => {
    await page.goto('/workflow-job/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content table.table tbody')).toContainText('e2e-beta-approval-wf');
  });

  test('viewer cannot see cancel or resume forms', async ({ page }) => {
    // Regression: this used the first listed run and skipped itself when the
    // viewer saw none, which is every run of e2e-template since team scoping
    // covers workflows. The run is unfinished, so an operator would see Cancel.
    await openWaitingBetaRun(page);
    await expect(page.locator('form[action*="/workflow-job/cancel"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/workflow-job/resume"]')).toHaveCount(0);
  });

  test('viewer gets 403 on a CSRF-valid POST /workflow-job/cancel', async ({ page }) => {
    const id = await openWaitingBetaRun(page);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await forgePost(page, `/workflow-job/cancel?id=${id}`, {}, /\/workflow-job\/cancel/);
    await expectForbidden(page);

    await page.goto(`/workflow-job/view?id=${id}`);
    await expect(page.locator('#wj-status')).toHaveText('Running');
  });
});
