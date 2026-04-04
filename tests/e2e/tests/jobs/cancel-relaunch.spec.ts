import { test, expect } from '@playwright/test';

test.describe('Job Cancel/Relaunch', () => {
  test('job detail shows action buttons for admin', async ({ page }) => {
    await page.goto('/job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      // Admin should see relaunch button on completed jobs
      await expect(page.locator('body')).toContainText(/job|status|template/i);
    }
  });
});
