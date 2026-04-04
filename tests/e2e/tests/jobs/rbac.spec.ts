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
    await expect(page.locator('body')).not.toContainText(/403|Forbidden/i);
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
});
