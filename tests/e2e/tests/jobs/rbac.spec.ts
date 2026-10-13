import { test, expect } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import { LOGSTREAM_RUNNING_JOB, seededJobId } from '../../lib/jobs';
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
});
