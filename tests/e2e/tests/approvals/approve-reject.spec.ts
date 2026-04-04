import { test, expect } from '@playwright/test';

test.describe('Approval Actions', () => {
  test('approval detail page loads', async ({ page }) => {
    await page.goto('/approval/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/approval|approve|reject|status/i);
    }
  });
});
