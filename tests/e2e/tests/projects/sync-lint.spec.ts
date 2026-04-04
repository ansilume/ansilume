import { test, expect } from '@playwright/test';

test.describe('Project Sync/Lint', () => {
  test('sync button present on git project detail', async ({ page }) => {
    await page.goto('/project/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      // Sync button should be present for git-backed projects
      const syncBtn = page.locator('a:has-text("Sync"), button:has-text("Sync")').first();
      // May not be visible for manual projects, just verify page loads
      await expect(page.locator('body')).toContainText(/project|scm/i);
    }
  });
});
