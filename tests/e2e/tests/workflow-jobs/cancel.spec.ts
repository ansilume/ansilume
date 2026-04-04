import { test, expect } from '@playwright/test';

test.describe('Workflow Job Cancel', () => {
  test('workflow job detail page loads', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/workflow|job|status|step/i);
    }
  });
});
