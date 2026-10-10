import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { forgePost, indexRows, rowsWhere } from '../../lib/scoping';

/**
 * The viewer only sees the approval requests of jobs in their team's projects
 * and of workflows whose every job step is in one (commands/E2eWorkflowScopingSeeder.php:
 * the run of e2e-beta-approval-wf waits for a request on e2e-beta-approval-rule).
 * The rule lists the viewer as approver, but the viewer role lacks approval.decide.
 */
async function openBetaRequest(page: Page): Promise<string> {
  // Columns of the index: #, Job, Rule, Status, ...
  const rows = rowsWhere(await indexRows(page, '/approval/index'), 2, 'e2e-beta-approval-rule');
  expect(rows, 'the viewer sees the request of their team\'s workflow').toHaveLength(1);
  await page.goto(`/approval/view?id=${rows[0].id}`);
  await expect(page.locator('#page-content table').first()).toContainText('Pending');
  return rows[0].id;
}

test.describe('Approvals RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view approvals index', async ({ page }) => {
    await page.goto('/approval/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content table.table tbody')).toContainText('e2e-beta-approval-rule');
  });

  test('viewer cannot see approve or reject buttons', async ({ page }) => {
    // Regression: this used the first listed request and skipped itself when
    // the viewer saw none, which is every request of an e2e-template workflow
    // since team scoping covers workflows.
    await openBetaRequest(page);
    await expect(page.locator('form[action*="/approval/approve"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/approval/reject"]')).toHaveCount(0);
  });

  test('viewer gets 403 on a CSRF-valid POST /approval/approve', async ({ page }) => {
    const id = await openBetaRequest(page);

    // A real form post with a valid CSRF token: only the permission check may stop it.
    await forgePost(page, `/approval/approve?id=${id}`, {}, /\/approval\/approve/);
    await expectForbidden(page);

    await page.goto(`/approval/view?id=${id}`);
    await expect(page.locator('#page-content table').first()).toContainText('Pending');
  });
});
