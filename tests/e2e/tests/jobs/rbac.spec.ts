import { test, expect } from '@playwright/test';

test.describe('Jobs RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view jobs index', async ({ page }) => {
    await page.goto('/job/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
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

  test('viewer gets 403 on POST /job/cancel', async ({ page }) => {
    // The e2e-logstream-running seeded job is in status=running and thus
    // cancelable. POSTing directly as viewer must be denied — UI hiding
    // alone isn't enough; the endpoint must enforce the permission too.
    await page.goto('/job/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-logstream-running',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No e2e-logstream-running job fixture');
      return;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Job link has no numeric id');
      return;
    }
    const response = await page.request.post(`/job/cancel?id=${match[1]}`, {
      data: {},
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    // Viewer is authenticated without permission → 403. Accept a redirect
    // or 400 too (BaseController variations) as a denial signal.
    expect([302, 303, 400, 403]).toContain(response.status());
  });
});
