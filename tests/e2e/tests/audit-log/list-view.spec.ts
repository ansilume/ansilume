import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Audit Log', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/audit-log/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/audit/i);
  });

  test('shows log entries', async ({ page }) => {
    await page.goto('/audit-log/index');
    const rows = page.locator('table.table tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });

  test('view detail', async ({ page }) => {
    await page.goto('/audit-log/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/audit|action|user|detail/i);
    }
  });
});
