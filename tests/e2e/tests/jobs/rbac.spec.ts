import { test, expect } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import {
  cancelJob,
  flash,
  jobInventoryCell,
  launchTemplate,
  linkedInventoryId,
  relaunchJob,
  relaunchedWaiting,
  waitingForApproval,
} from '../../lib/approvals';
import { HOST_INVENTORY, HOST_TEMPLATE } from '../../lib/inventories';
import { LOGSTREAM_RUNNING_JOB, seededJobId } from '../../lib/jobs';
import { cancelJobIfActive } from '../../lib/runs';
import { asAdmin } from '../../lib/scoping';

test.describe('Jobs RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view jobs index', async ({ page }) => {
    await page.goto('/job/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer cannot see cancel/relaunch buttons', async ({ page }) => {
    await page.goto('/job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(
        page.locator('a:has-text("Cancel"), button:has-text("Cancel"), a:has-text("Relaunch"), button:has-text("Relaunch")'),
      ).not.toBeVisible();
    }
  });

  test('viewer gets 403 on POST /job/cancel', async ({ page, browser }, testInfo) => {
    // The e2e-logstream-running seeded job (commands/E2eLogStreamSeeder.php)
    // is running and thus cancelable. POSTing directly as viewer must be
    // denied — UI hiding alone isn't enough; the endpoint must enforce the
    // permission too. Regression: the test looked for the job in the
    // viewer's job list, where team scoping hides it, and skipped itself.
    const id = await asAdmin(browser, testInfo, (admin) => seededJobId(admin, LOGSTREAM_RUNNING_JOB));

    await page.goto('/job/index');
    // With a valid CSRF token: only the permission check may refuse it.
    const response = await postWithPageCsrf(page, `/job/cancel?id=${id}`);
    expect(response.status()).toBe(403);

    await asAdmin(browser, testInfo, async (admin) => {
      await admin.goto(`/job/view?id=${id}`);
      await expect(admin.locator('#status-badge')).toHaveText(/running/i);
    });
  });

  // commands/E2eInventoryGateSeeder.php: e2e-host-gated-template is in a
  // project without a team, which every role sees, on e2e-gated-inventory,
  // which has no project either. e2e-admin launches; the job waits for
  // e2e-operator's approval and is canceled afterwards.
  test('viewer sees the inventory a job was launched with, and may open it', async ({ page, browser }, testInfo) => {
    const jobId = await asAdmin(browser, testInfo, (admin) => launchTemplate(admin, HOST_TEMPLATE));
    try {
      await page.goto(`/job/view?id=${jobId}`);
      await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
      const inventoryId = await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
      await jobInventoryCell(page).locator('a').click();
      await expect(page).toHaveURL(new RegExp(`/inventory/view\\?id=${inventoryId}$`));
      await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    } finally {
      await asAdmin(browser, testInfo, (admin) => cancelJobIfActive(admin, jobId));
    }
  });

  // An operator launches and re-launches what the operator may operate; a
  // job template whose inventory has an approval rule waits each time, for
  // e2e-admin, the rule's other approver.
  test('operator re-launches a job of a template on a gated inventory, and the new job waits for approval', async ({ page }) => {
    const jobId = await launchTemplate(page, HOST_TEMPLATE);
    let relaunchedId = '';
    try {
      await expect(flash(page, 'info')).toHaveText(waitingForApproval(jobId));
      await cancelJob(page);
      await expect(page.locator('#status-badge')).toHaveText('Canceled');

      relaunchedId = await relaunchJob(page);
      expect(relaunchedId, 'a new job').not.toBe(jobId);
      await expect(flash(page, 'info')).toHaveText(relaunchedWaiting(relaunchedId));
      await expect(page.locator('#status-badge')).toHaveText('Awaiting Approval');
      await linkedInventoryId(jobInventoryCell(page), HOST_INVENTORY);
    } finally {
      await cancelJobIfActive(page, relaunchedId);
      await cancelJobIfActive(page, jobId);
    }
  });
});
