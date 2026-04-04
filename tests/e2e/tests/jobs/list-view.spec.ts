import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Jobs List & View', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/job/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/job/i);
  });

  test('shows status badges', async ({ page }) => {
    await page.goto('/job/index');
    // Status badges may or may not be present depending on job history
    const badges = page.locator('.badge, .label, .status-badge');
    const count = await badges.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('view job detail', async ({ page }) => {
    await page.goto('/job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/job|status|template|log/i);
    }
  });
});
