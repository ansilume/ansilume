import { test, expect } from '@playwright/test';

test.describe('Analytics RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });

  test('operator can view analytics', async ({ page }) => {
    await page.goto('/analytics/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });
});
